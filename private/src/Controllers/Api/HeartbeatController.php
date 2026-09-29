<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\HeartbeatService;
use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class HeartbeatController
{
    public function __construct(private HeartbeatService $heartbeatService)
    {
    }

    public function heartbeat(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        return JsonResponder::fromService($this->heartbeatService->process($body));
    }
}
