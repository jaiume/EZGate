<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\StatusService;
use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class StatusController
{
    public function __construct(private StatusService $statusService)
    {
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $token = $this->extractToken($request, $query);
        $identifier = (string) ($query['identifier'] ?? $query['hardware_id'] ?? '');
        return JsonResponder::fromService($this->statusService->status($token, $identifier));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function extractToken(ServerRequestInterface $request, array $params): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        $headerToken = $request->getHeaderLine('X-EZGate-Token');
        if ($headerToken !== '') {
            return $headerToken;
        }

        $fromQuery = $params['token'] ?? null;
        return is_string($fromQuery) ? $fromQuery : null;
    }
}
