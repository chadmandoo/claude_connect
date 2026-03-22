<?php

declare(strict_types=1);

namespace App\Web;

use App\Storage\RedisStore;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Contract\ConfigInterface;

class WebAuthManager
{
    private const TOKEN_TTL = 86400; // 24 hours

    #[Inject]
    private RedisStore $redis;

    #[Inject]
    private ConfigInterface $config;

    public function authenticate(string $password): ?string
    {
        $expected = $this->config->get('mcp.web.auth_password', '');
        if ($expected === '' || !hash_equals($expected, $password)) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $this->redis->setWebToken($token, self::TOKEN_TTL);

        return $token;
    }

    public function validateToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        return $this->redis->hasWebToken($token);
    }

    public function revokeToken(string $token): void
    {
        $this->redis->deleteWebToken($token);
    }

    public function getUserId(): string
    {
        return $this->config->get('mcp.web.user_id', 'web_user');
    }
}
