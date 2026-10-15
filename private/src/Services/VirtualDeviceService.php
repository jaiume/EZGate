<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;
use App\DAO\OpenCommandDAO;
use App\DAO\VirtualDeviceDAO;
use App\Support\FleetSettings;
use App\Support\PulseDuration;
use App\Support\VirtualDeviceMode;

class VirtualDeviceService
{
    public function __construct(
        private VirtualDeviceDAO $virtualDeviceDAO,
        private BoardDAO $boardDAO,
        private OpenCommandDAO $openCommandDAO
    ) {
    }

    public function ensureForBoard(int $boardId): void
    {
        $board = $this->boardDAO->findById($boardId);
        if ($board === null || (int) ($board['relay_count'] ?? 1) !== 1) {
            return;
        }
        $existing = $this->virtualDeviceDAO->findByBoardId($boardId);
        if ($existing !== []) {
            return;
        }
        $name = $board['friendly_name'] !== null ? (string) $board['friendly_name'] : null;
        $pulse = PulseDuration::stored($board['pulse_duration_ms'] ?? null);
        $id = $this->virtualDeviceDAO->create($boardId, $this->virtualDeviceDAO->newPublicId(), $name, null);
        $this->virtualDeviceDAO->replaceRelays($id, [['relay' => 1, 'pulse' => $pulse]]);
    }

    public function syncOneRelayName(int $boardId, ?string $friendlyName): void
    {
        $board = $this->boardDAO->findById($boardId);
        if ($board === null || (int) ($board['relay_count'] ?? 1) !== 1) {
            return;
        }
        $devices = $this->virtualDeviceDAO->findByBoardId($boardId);
        if ($devices === []) {
            return;
        }
        $name = $friendlyName !== null ? trim($friendlyName) : null;
        $this->virtualDeviceDAO->update((int) $devices[0]['id'], $name === '' ? null : $name, null);
    }

    /**
     * @param list<int> $relays
     * @param array<int, ?int> $pulses keyed by relay number
     */
    public function save(
        ?int $id,
        int $boardId,
        ?string $friendlyName,
        array $relays,
        ?int $wiegandReader,
        array $pulses
    ): array {
        $board = $this->boardDAO->findById($boardId);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }
        $name = $friendlyName !== null ? trim($friendlyName) : null;
        if ($name === '') {
            $name = null;
        }
        $relayCount = (int) $board['relay_count'];
        $wiegandCount = (int) ($board['wiegand_count'] ?? 0);
        $relays = array_values(array_unique(array_map('intval', $relays)));
        sort($relays);
        foreach ($relays as $relay) {
            if ($relay < 1 || $relay > $relayCount) {
                return $this->fail('Relay ' . $relay . ' is not on this board.', 'VALIDATION_ERROR');
            }
        }
        $taken = $this->virtualDeviceDAO->relayNumbersOnBoard($boardId, $id);
        foreach ($relays as $relay) {
            if (in_array($relay, $taken, true)) {
                return $this->fail('Relay ' . $relay . ' is already assigned.', 'VALIDATION_ERROR');
            }
        }
        if ($wiegandReader !== null) {
            if ($wiegandCount < 1 || $wiegandReader < 1 || $wiegandReader > $wiegandCount) {
                return $this->fail('That Wiegand reader is not on this board.', 'VALIDATION_ERROR');
            }
            if ($this->virtualDeviceDAO->readerTaken($boardId, $wiegandReader, $id)) {
                return $this->fail('That Wiegand reader is already assigned.', 'VALIDATION_ERROR');
            }
        }
        if ($relays === [] && $wiegandReader === null) {
            return $this->fail('Assign a relay or a Wiegand reader.', 'VALIDATION_ERROR');
        }
        $relayRows = [];
        foreach ($relays as $relay) {
            $pulse = $pulses[$relay] ?? null;
            if ($pulse !== null && ($pulse < 50 || $pulse > 10000)) {
                return $this->fail('Enter a pulse duration between 50 and 10000 ms.', 'VALIDATION_ERROR');
            }
            $relayRows[] = ['relay' => $relay, 'pulse' => $pulse];
        }

        if ($id === null) {
            $deviceId = $this->virtualDeviceDAO->create(
                $boardId,
                $this->virtualDeviceDAO->newPublicId(),
                $name,
                $wiegandReader
            );
        } else {
            $device = $this->virtualDeviceDAO->findById($id);
            if ($device === null || (int) $device['board_id'] !== $boardId) {
                return $this->fail('Virtual device not found.', 'NOT_FOUND');
            }
            $previousReader = $device['wiegand_reader'] !== null ? (int) $device['wiegand_reader'] : null;
            $this->virtualDeviceDAO->update($id, $name, $wiegandReader);
            $deviceId = $id;
            if ($previousReader !== $wiegandReader) {
                // The list stays on the virtual device when the reader changes.
            }
        }
        $this->virtualDeviceDAO->replaceRelays($deviceId, $relayRows);
        $this->boardDAO->bumpConfigVersion($boardId);

        return [
            'success' => true,
            'message' => 'Virtual device saved.',
            'data' => ['id' => $deviceId, 'board_id' => $boardId],
            'error' => null,
        ];
    }

    public function delete(int $id): array
    {
        $device = $this->virtualDeviceDAO->findById($id);
        if ($device === null) {
            return $this->fail('Virtual device not found.', 'NOT_FOUND');
        }
        $boardId = (int) $device['board_id'];
        $this->virtualDeviceDAO->delete($id);
        $this->boardDAO->bumpConfigVersion($boardId);

        return [
            'success' => true,
            'message' => 'Virtual device removed.',
            'data' => ['id' => $id, 'board_id' => $boardId],
            'error' => null,
        ];
    }

    public function trigger(int $id): array
    {
        $device = $this->virtualDeviceDAO->findById($id);
        if ($device === null) {
            return $this->fail('Virtual device not found.', 'NOT_FOUND');
        }
        if ($this->virtualDeviceDAO->relays($id) === []) {
            return $this->fail('Virtual device has no relays.', 'VALIDATION_ERROR');
        }
        $board = $this->boardDAO->findById((int) $device['board_id']);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }
        $queued = $this->openCommandDAO->queue(
            (int) $board['id'],
            $id,
            FleetSettings::openCommandTtlSecondsForBoard($board)
        );
        $label = $device['friendly_name'] ?: $device['public_id'];
        $message = !empty($queued['created'])
            ? 'Open command queued for ' . $label . '. It will pulse on the next heartbeat.'
            : 'An open command is already waiting for ' . $label . '. Extra triggers will not pulse again until the board reports it executed.';

        return [
            'success' => true,
            'message' => $message,
            'data' => ['id' => $id, 'board_id' => (int) $board['id']],
            'error' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summariesForBoard(int $boardId): array
    {
        $devices = [];
        foreach ($this->virtualDeviceDAO->findByBoardId($boardId) as $device) {
            $devices[] = $this->presentDevice($device);
        }

        return $devices;
    }

    /**
     * @param array<string, mixed> $board
     * @return array{virtual_devices: list<array<string, mixed>>, taken_relays: list<int>, taken_readers: list<int>, can_add_device: bool}
     */
    public function editorForBoard(array $board): array
    {
        $devices = $this->summariesForBoard((int) $board['id']);
        $takenRelays = [];
        $takenReaders = [];
        foreach ($devices as $device) {
            foreach ($device['relays'] as $relay) {
                $takenRelays[] = (int) $relay['relay'];
            }
            if (!empty($device['wiegand_reader'])) {
                $takenReaders[] = (int) $device['wiegand_reader'];
            }
        }
        foreach ($devices as $index => $device) {
            $mineRelays = array_map(static fn (array $relay): int => (int) $relay['relay'], $device['relays']);
            $devices[$index]['taken_relays'] = array_values(array_diff($takenRelays, $mineRelays));
            $mineReader = !empty($device['wiegand_reader']) ? (int) $device['wiegand_reader'] : null;
            $devices[$index]['taken_readers'] = array_values(array_filter(
                $takenReaders,
                static fn (int $reader): bool => $reader !== $mineReader
            ));
        }
        $relayCount = (int) ($board['relay_count'] ?? 1);
        $wiegandCount = (int) ($board['wiegand_count'] ?? 0);

        return [
            'virtual_devices' => $devices,
            'taken_relays' => $takenRelays,
            'taken_readers' => $takenReaders,
            'can_add_device' => count(array_unique($takenRelays)) < $relayCount
                || count(array_unique($takenReaders)) < $wiegandCount,
        ];
    }

    public function pageData(): array
    {
        $boards = [];
        foreach ($this->virtualDeviceDAO->findMultiRelayBoards() as $board) {
            $devices = [];
            foreach ($this->virtualDeviceDAO->findByBoardId((int) $board['id']) as $device) {
                $devices[] = $this->presentDevice($device);
            }
            $board['virtual_devices'] = $devices;
            $takenRelays = [];
            $takenReaders = [];
            foreach ($devices as $device) {
                foreach ($device['relays'] as $relay) {
                    $takenRelays[] = (int) $relay['relay'];
                }
                if (!empty($device['wiegand_reader'])) {
                    $takenReaders[] = (int) $device['wiegand_reader'];
                }
            }
            foreach ($devices as $index => $device) {
                $mineRelays = array_map(static fn (array $relay): int => (int) $relay['relay'], $device['relays']);
                $devices[$index]['taken_relays'] = array_values(array_diff($takenRelays, $mineRelays));
                $mineReader = !empty($device['wiegand_reader']) ? (int) $device['wiegand_reader'] : null;
                $devices[$index]['taken_readers'] = array_values(array_filter(
                    $takenReaders,
                    static fn (int $reader): bool => $reader !== $mineReader
                ));
            }
            $board['virtual_devices'] = $devices;
            $board['taken_relays'] = $takenRelays;
            $board['taken_readers'] = $takenReaders;
            $boards[] = $board;
        }

        return $boards;
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    public function presentDevice(array $device): array
    {
        $relays = [];
        foreach ($this->virtualDeviceDAO->relays((int) $device['id']) as $relay) {
            $stored = PulseDuration::stored($relay['pulse_duration_ms'] ?? null);
            $relays[] = [
                'relay' => (int) $relay['relay_number'],
                'pulse_duration_ms' => $stored,
                'pulse_resolved' => PulseDuration::resolve($stored, PulseDuration::stored($device['site_pulse_duration_ms'] ?? null)),
            ];
        }
        $device['relays'] = $relays;
        $device['accesstag_count'] = $this->virtualDeviceDAO->countNumbers((int) $device['id']);
        $device['operation'] = VirtualDeviceMode::operation($device, $relays !== []);
        return $device;
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
