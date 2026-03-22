<?php

declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;
use App\Controller\HealthController;
use App\Controller\WebController;

Router::get('/health', [HealthController::class, 'index']);

Router::get('/', [WebController::class, 'index']);
Router::get('/assets/{file:.+}', [WebController::class, 'asset']);
Router::post('/api/auth', [WebController::class, 'authenticate']);

// SPA catch-all: serve index.html for client-side routes
Router::get('/conversations[/{path:.*}]', [WebController::class, 'spa']);
Router::get('/channels[/{path:.*}]', [WebController::class, 'spa']);
Router::get('/projects[/{path:.*}]', [WebController::class, 'spa']);
Router::get('/tasks', [WebController::class, 'spa']);
Router::get('/memory[/{path:.*}]', [WebController::class, 'spa']);
Router::get('/skills', [WebController::class, 'spa']);
Router::get('/agents[/{path:.*}]', [WebController::class, 'spa']);
Router::get('/todos', [WebController::class, 'spa']);
Router::get('/notes', [WebController::class, 'spa']);
Router::get('/system[/{path:.*}]', [WebController::class, 'spa']);
