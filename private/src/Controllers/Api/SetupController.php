<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\SetupService;
use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SetupController
{
    public function __construct(private SetupService $setupService)
    {
    }

    public function sites(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $hardwareId = trim((string) ($request->getQueryParams()['hardware_id'] ?? ''));
        return JsonResponder::fromService($this->setupService->listSitesForSetup($hardwareId));
    }

    public function complete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        return JsonResponder::fromService($this->setupService->completeSetup($body));
    }
}
