<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\AccessReadDAO;
use App\DAO\BoardDAO;
use App\DAO\VirtualDeviceDAO;
use App\Support\VirtualDeviceMode;

class ReadService
{
    public function __construct(
        private BoardDAO $boardDAO,
        private VirtualDeviceDAO $virtualDeviceDAO,
        private AccessReadDAO $accessReadDAO,
        private EntryZenReadService $entryZenReadService
    ) {
    }

    public function ingest(array $body): array
    {
        $hardwareId = trim((string) ($body['hardware_id'] ?? ''));
        if ($hardwareId === '') {
            return $this->fail('Board identifier is required.', 'VALIDATION_ERROR');
        }
        $board = $this->boardDAO->findByHardwareId($hardwareId);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }
        $reads = $body['reads'] ?? null;
        if (!is_array($reads)) {
            return $this->fail('Reads are required.', 'VALIDATION_ERROR');
        }

        $accepted = [];
        $rejected = [];
        foreach ($reads as $read) {
            if (!is_array($read)) {
                continue;
            }
            $eventId = $read['board_event_id'] ?? null;
            if (!is_int($eventId) && !(is_string($eventId) && ctype_digit((string) $eventId))) {
                continue;
            }
            $eventId = (int) $eventId;
            $publicId = trim((string) ($read['virtual_device'] ?? ''));
            $number = trim((string) ($read['accesstag_number'] ?? ''));
            $device = $publicId === '' ? null : $this->virtualDeviceDAO->findByPublicId($publicId);
            if ($number === ''
                || $device === null
                || (int) $device['board_id'] !== (int) $board['id']
                || !VirtualDeviceMode::hasReader($device)
            ) {
                $rejected[] = $eventId;
                continue;
            }
            $readAt = $read['read_at'] ?? null;
            $readAt = $readAt === null || $readAt === '' ? null : $this->utcDateTime((string) $readAt);
            $inList = !empty($read['in_list']) && $this->virtualDeviceDAO->relays((int) $device['id']) !== [];
            if ($this->isDuplicate((int) $board['id'], $eventId, $publicId, $number, $readAt, $inList)) {
                $accepted[] = $eventId;
                continue;
            }
            $this->accessReadDAO->insert((int) $device['id'], $number, $readAt, $inList, $eventId);
            $accepted[] = $eventId;
        }

        $this->entryZenReadService->pushForBoard((int) $board['id']);

        return [
            'success' => true,
            'data' => [
                'accepted' => $accepted,
                'rejected' => $rejected,
            ],
            'error' => null,
        ];
    }

    private function isDuplicate(
        int $boardId,
        int $eventId,
        string $publicId,
        string $number,
        ?string $readAt,
        bool $inList
    ): bool {
        foreach ($this->accessReadDAO->matchingEvent($boardId, $eventId) as $existing) {
            $existingAt = $existing['read_at'] !== null ? (string) $existing['read_at'] : null;
            if ((string) $existing['public_id'] === $publicId
                && (string) $existing['accesstag_number'] === $number
                && $existingAt === $readAt
                && !empty($existing['in_list']) === $inList
            ) {
                return true;
            }
        }

        return false;
    }

    private function utcDateTime(string $value): ?string
    {
        $stamp = strtotime($value);
        if ($stamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $stamp);
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
