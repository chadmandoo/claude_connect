<?php

declare(strict_types=1);

namespace App\Controller;

use App\Web\WebAuthManager;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * HTTP controller for the web frontend: serves the SPA index, static assets with ETag caching,
 * SPA catch-all routing, and password-based authentication.
 */
class WebController
{
    private const MIME_TYPES = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];

    #[Inject]
    private WebAuthManager $authManager;

    #[Inject]
    private RequestInterface $request;

    #[Inject]
    private ResponseInterface $response;

    public function index(): PsrResponseInterface
    {
        $path = BASE_PATH . '/public/index.html';
        if (!file_exists($path)) {
            return $this->response->withStatus(404)->raw('Not found');
        }

        return $this->response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache')
            ->withBody(new SwooleStream(file_get_contents($path)));
    }

    public function asset(string $file): PsrResponseInterface
    {
        // Sanitize: prevent directory traversal while allowing subdirectories
        $file = ltrim($file, '/');
        if (str_contains($file, '..')) {
            return $this->response->withStatus(403)->raw('Forbidden');
        }
        $path = BASE_PATH . '/public/assets/' . $file;

        if (!file_exists($path) || !is_file($path)) {
            return $this->response->withStatus(404)->raw('Not found');
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$ext] ?? 'application/octet-stream';

        // Hashed assets can be cached long-term
        $cacheControl = in_array($ext, ['html'], true) ? 'no-cache' : 'public, max-age=31536000, immutable';
        $etag = '"' . md5_file($path) . '"';

        // Return 304 if unchanged
        $ifNoneMatch = $this->request->header('if-none-match', '');
        if ($ifNoneMatch === $etag) {
            return $this->response->withStatus(304);
        }

        return $this->response
            ->withStatus(200)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', $cacheControl)
            ->withHeader('ETag', $etag)
            ->withBody(new SwooleStream(file_get_contents($path)));
    }

    /**
     * SPA catch-all: serve index.html for any unmatched route so client-side routing works.
     */
    public function spa(): PsrResponseInterface
    {
        return $this->index();
    }

    public function authenticate(): PsrResponseInterface
    {
        $body = json_decode($this->request->getBody()->getContents(), true);
        $password = $body['password'] ?? '';

        if ($password === '') {
            return $this->response->withStatus(400)->json(['error' => 'Password required']);
        }

        $token = $this->authManager->authenticate($password);

        if ($token === null) {
            return $this->response->withStatus(401)->json(['error' => 'Invalid password']);
        }

        return $this->response->json([
            'token' => $token,
            'user_id' => $this->authManager->getUserId(),
        ]);
    }
}
