<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;
use App\DAO\OpenCommandDAO;
use App\DAO\VirtualDeviceDAO;
use App\Support\FleetSettings;

class TriggerService
{
    public function __construct(
        private AuthService $authService,
        private BoardDAO $boardDAO,
        private VirtualDeviceDAO $virtualDeviceDAO,
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
            return $this->fail('Identifier is required.', 'VALIDATION_ERROR');
        }

        $device = $this->virtualDeviceDAO->findByPublicId($identifier);
        if ($device === null) {
            return $this->fail('Virtual device not found.', 'NOT_FOUND');
        }
        if ($this->virtualDeviceDAO->relays((int) $device['id']) === []) {
            return $this->fail('Virtual device has no relays.', 'VALIDATION_ERROR');
        }

        $board = $this->boardDAO->findById((int) $device['board_id']);
        if ($board === null) {
            return $this->fail('Virtual device not found.', 'NOT_FOUND');
        }

        $this->openCommandDAO->queue(
            (int) $board['id'],
            (int) $device['id'],
            FleetSettings::openCommandTtlSecondsForBoard($board)
        );

        return [
            'success' => true,
            'data' => [
                'virtual_device' => (string) $device['public_id'],
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
