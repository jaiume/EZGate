<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\CsrfService;
use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CsrfService $csrfService,
        private Twig $twig
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->csrfService->token();
        $this->twig->getEnvironment()->addGlobal('csrf_token', $token);

        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $submitted = is_array($body) ? ($body['_csrf'] ?? null) : null;
        if (!is_string($submitted) || $submitted === '') {
            $submitted = $request->getHeaderLine('X-CSRF-Token');
        }
        if ($this->csrfService->isValid(is_string($submitted) ? $submitted : null)) {
            return $handler->handle($request);
        }

        $wantsJson = str_contains($request->getHeaderLine('Accept'), 'application/json')
            || str_contains($request->getHeaderLine('Content-Type'), 'application/json');
        if ($wantsJson) {
            return JsonResponder::write([
                'success' => false,
                'message' => 'Invalid security token.',
                'data' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'details' => []],
            ], 400);
        }

        $response = new Response();
        $response->getBody()->write('Invalid security token.');
        return $response->withStatus(400)->withHeader('Content-Type', 'text/plain');
    }
}
