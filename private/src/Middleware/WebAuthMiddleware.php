<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class WebAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthService $authService)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->authService->isLoggedIn()) {
            return $handler->handle($request);
        }

        $response = new Response();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
