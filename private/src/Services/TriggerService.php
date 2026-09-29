<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;
use App\DAO\OpenCommandDAO;
use App\Support\FleetSettings;

class TriggerService
{
    public function __construct(
        private AuthService $authService,
        private BoardDAO $boardDAO,
        private OpenCommandDAO $openCommandDAO
    ) {
    }

    public function trigger(?string $token, string $identifier): array
    {
        if (!$this->authService->apiKeyMatches($token)) {
            return $this->fail('Invalid API key.', 'UNAUTHORIZED');
        }

        $identifier = trim($identifier);
        if ($identifier === '') {
            return $this->fail('Board identifier is required.', 'VALIDATION_ERROR');
        }

        $board = $this->boardDAO->findByHardwareId($identifier);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        $this->openCommandDAO->queue((int) $board['id'], FleetSettings::openCommandTtlSecondsForBoard($board));

        return [
            'success' => true,
            'data' => [
                'hardware_id' => $board['hardware_id'],
            ],
            'error' => null,
        ];
    }

    private function fail(string $message, string $code): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => ['code' => $code, 'details' => []],
        ];
    }
}
