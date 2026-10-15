<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\AccesstagService;
use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AccesstagController
{
    public function __construct(private AccesstagService $accesstagService)
    {
    }

    public function replace(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $result = $this->accesstagService->replace(
            $this->token($request, $body),
            (string) ($body['identifier'] ?? ''),
            $body['version'] ?? null,
            $body['accesstags'] ?? null,
            array_key_exists('version', $body),
            array_key_exists('accesstags', $body),
            $body['reads_endpoint'] ?? null,
            $body['reads_token'] ?? null,
            array_key_exists('reads_endpoint', $body),
            array_key_exists('reads_token', $body)
        );
        return JsonResponder::fromService($result);
    }

    public function pull(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        return JsonResponder::fromService($this->accesstagService->pull($body));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function token(ServerRequestInterface $request, array $body): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }
        $headerToken = $request->getHeaderLine('X-EZGate-Token');
        if ($headerToken !== '') {
            return $headerToken;
        }
        $query = $request->getQueryParams()['token'] ?? null;
        if (is_string($query) && $query !== '') {
            return $query;
        }
        $fromBody = $body['token'] ?? null;
        return is_string($fromBody) ? $fromBody : null;
    }
}
