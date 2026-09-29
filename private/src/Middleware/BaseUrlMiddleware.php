<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\UtilityService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class BaseUrlMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        UtilityService::rememberBaseUrl($request);
        return $handler->handle($request);
    }
}
