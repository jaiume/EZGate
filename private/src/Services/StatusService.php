<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;

class StatusService
{
    public function __construct(
        private AuthService $authService,
        private BoardDAO $boardDAO,
        private BoardService $boardService
    ) {
    }

    public function status(?string $token, string $identifier): array
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

        $presented = $this->boardService->present($board);

        return [
            'success' => true,
            'data' => [
                'hardware_id' => $board['hardware_id'],
                'status' => !empty($presented['online']) ? 'online' : 'offline',
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
