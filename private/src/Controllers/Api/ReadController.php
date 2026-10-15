<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\ReadService;
use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ReadController
{
    public function __construct(private ReadService $readService)
    {
    }

    public function ingest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        return JsonResponder::fromService($this->readService->ingest($body));
    }
}
