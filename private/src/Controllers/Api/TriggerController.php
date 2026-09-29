<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\TriggerService;
use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class TriggerController
{
    public function __construct(private TriggerService $triggerService)
    {
    }

    public function trigger(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $token = $this->extractToken($request, $body);
        $identifier = (string) ($body['identifier'] ?? $body['hardware_id'] ?? '');
        $result = $this->triggerService->trigger($token, $identifier);
        return JsonResponder::fromService($result);
    }

    private function extractToken(ServerRequestInterface $request, array $body): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        $headerToken = $request->getHeaderLine('X-EZGate-Token');
        if ($headerToken !== '') {
            return $headerToken;
        }

        $fromBody = $body['token'] ?? null;
        return is_string($fromBody) ? $fromBody : null;
    }
}
