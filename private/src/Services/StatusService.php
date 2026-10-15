<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;
use App\DAO\VirtualDeviceDAO;
use App\Support\VirtualDeviceMode;

class StatusService
{
    public function __construct(
        private AuthService $authService,
        private BoardDAO $boardDAO,
        private BoardService $boardService,
        private VirtualDeviceDAO $virtualDeviceDAO,
        private EntryZenReadService $entryZenReadService
    ) {
    }

    public function status(?string $token, string $identifier): array
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

        $board = $this->boardDAO->findById((int) $device['board_id']);
        if ($board === null) {
            return $this->fail('Virtual device not found.', 'NOT_FOUND');
        }

        $this->entryZenReadService->pushPending();
        if ($this->virtualDeviceDAO->relays((int) $device['id']) === []) {
            return $this->fail('Virtual device has no relays.', 'VALIDATION_ERROR');
        }
        $presented = $this->boardService->present($board);

        return [
            'success' => true,
            'data' => [
                'virtual_device' => (string) $device['public_id'],
                'status' => !empty($presented['online']) ? 'online' : 'offline',
            ],
            'error' => null,
        ];
    }

    public function readerStatus(?string $token, string $identifier): array
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

        $this->entryZenReadService->pushPending();
        if (!VirtualDeviceMode::hasReader($device)) {
            return $this->fail('Virtual device has no Wiegand reader.', 'VALIDATION_ERROR');
        }
        $hasRelays = $this->virtualDeviceDAO->relays((int) $device['id']) !== [];
        $data = [
            'virtual_device' => (string) $device['public_id'],
            'operation' => VirtualDeviceMode::operation($device, $hasRelays),
        ];
        if ($hasRelays) {
            $version = $device['accesstag_list_version'];
            $data['accesstag_list_version'] = $version !== null && $version !== '' ? (string) $version : null;
        }

        return [
            'success' => true,
            'data' => $data,
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
