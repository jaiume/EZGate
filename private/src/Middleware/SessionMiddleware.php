<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\ConfigService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SessionMiddleware implements MiddlewareInterface
{
    public static function shouldStart(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        return !str_starts_with($path, '/api/');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!self::shouldStart($request)) {
            return $handler->handle($request);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            $name = (string) ConfigService::get('auth.session_name', 'ezgate_session');
            session_name($name);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $request->getUri()->getScheme() === 'https',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        return $handler->handle($request);
    }
}
