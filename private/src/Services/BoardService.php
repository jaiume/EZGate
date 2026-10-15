<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;
use App\DAO\BoardTypeDAO;
use App\DAO\FirmwareVersionDAO;
use App\DAO\OpenCommandDAO;
use App\DAO\SiteDAO;
use App\DAO\VirtualDeviceDAO;
use App\DAO\WifiNetworkDAO;
use App\Support\BoardPresenter;
use App\Support\FleetSettings;
use App\Support\HardwareId;
use App\Support\LoraKeys;
use App\Support\AppLog;
use App\Support\PulseDuration;
use App\Support\Uptime;
use App\Support\WifiSecurity;

class BoardService
{
    public function __construct(
        private BoardDAO $boardDAO,
        private SiteDAO $siteDAO,
        private OpenCommandDAO $openCommandDAO,
        private FirmwareVersionDAO $firmwareVersionDAO,
        private FirmwareService $firmwareService,
        private WifiNetworkDAO $wifiNetworkDAO,
        private BoardTypeDAO $boardTypeDAO,
        private VirtualDeviceDAO $virtualDeviceDAO,
        private VirtualDeviceService $virtualDeviceService
    ) {
    }

    public function isolationCutoff(): string
    {
        $timeout = (int) ConfigService::get('app.isolation_timeout_seconds', 60);
        return gmdate('Y-m-d H:i:s', time() - max($timeout, 1));
    }

    /**
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    public function present(array $board, ?string $cutoff = null): array
    {
        $presented = BoardPresenter::forList($board, $cutoff ?? $this->isolationCutoff());
        $presented['virtual_devices'] = $this->virtualDeviceService->summariesForBoard((int) $presented['id']);

        return $presented;
    }

    public function listBoards(): array
    {
        $cutoff = $this->isolationCutoff();
        $boards = [];
        foreach ($this->boardDAO->findAllWithSite() as $board) {
            $boards[] = $this->present($board, $cutoff);
        }

        return [
            'success' => true,
            'message' => 'Boards loaded.',
            'data' => ['boards' => $boards],
            'error' => null,
        ];
    }

    public function getBoard(int $id): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        $board['uptime_label'] = Uptime::label($board['last_uptime_seconds'] ?? null);
        $board = $this->presentBoardPulse($board);
        $editor = $this->virtualDeviceService->editorForBoard($board);
        $board['virtual_devices'] = $editor['virtual_devices'];
        $board['taken_relays'] = $editor['taken_relays'];
        $board['taken_readers'] = $editor['taken_readers'];
        $board['can_add_device'] = $editor['can_add_device'];

        $sites = [];
        foreach ($this->siteDAO->findAll() as $site) {
            $siteOverride = PulseDuration::stored($site['pulse_duration_ms'] ?? null);
            $site['pulse_resolved'] = PulseDuration::resolve(null, $siteOverride);
            $sites[] = $site;
        }

        return [
            'success' => true,
            'message' => 'Board loaded.',
            'data' => [
                'board' => $board,
                'sites' => $sites,
            ],
            'error' => null,
        ];
    }

    public function addToInventory(string $hardwareId, int $boardTypeId): array
    {
        $hardwareId = trim($hardwareId);
        if ($hardwareId === '') {
            return $this->fail('Board identifier is required.', 'VALIDATION_ERROR');
        }
        if ($boardTypeId <= 0 || $this->boardTypeDAO->findById($boardTypeId) === null) {
            return $this->fail('Choose a board type.', 'VALIDATION_ERROR');
        }

        $existing = $this->boardDAO->findByHardwareId($hardwareId);
        if ($existing !== null) {
            return $this->fail('That board is already in inventory.', 'VALIDATION_ERROR');
        }

        $id = $this->boardDAO->create($hardwareId, null, null, $boardTypeId);
        $this->virtualDeviceService->ensureForBoard($id);
        return [
            'success' => true,
            'message' => 'Board added to inventory.',
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function updateBoard(int $id, ?string $friendlyName, ?int $siteId, ?int $pulseDurationMs): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        $name = $friendlyName !== null ? trim($friendlyName) : null;
        if ($name === '') {
            $name = null;
        }

        if ($siteId !== null && $this->siteDAO->findById($siteId) === null) {
            return $this->fail('Site not found.', 'NOT_FOUND');
        }

        $this->boardDAO->update($id, $name, $siteId, null);

        return [
            'success' => true,
            'message' => 'Board updated.',
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function testTrigger(int $id): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        if ((int) ($board['relay_count'] ?? 1) > 1) {
            return $this->fail('Trigger a virtual device on this board.', 'VALIDATION_ERROR');
        }
        $devices = $this->virtualDeviceDAO->findByBoardId($id);
        if ($devices === [] || $this->virtualDeviceDAO->relays((int) $devices[0]['id']) === []) {
            return $this->fail('Virtual device has no relays.', 'VALIDATION_ERROR');
        }
        $queued = $this->openCommandDAO->queue(
            $id,
            (int) $devices[0]['id'],
            FleetSettings::openCommandTtlSecondsForBoard($board)
        );
        $label = $board['friendly_name'] ?: $board['hardware_id'];
        $message = !empty($queued['created'])
            ? 'Open command queued for ' . $label . '. It will pulse on the next heartbeat.'
            : 'An open command is already waiting for ' . $label . '. Extra triggers will not pulse again until the board reports it executed.';

        return [
            'success' => true,
            'message' => $message,
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function requestWifiScan(int $id): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        $this->boardDAO->requestScan($id);
        $label = $board['friendly_name'] ?: $board['hardware_id'];

        return [
            'success' => true,
            'message' => 'WiFi scan requested for ' . $label . '. Results will appear after the next heartbeats.',
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function requestReboot(int $id): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        $this->boardDAO->requestReboot($id);
        $label = $board['friendly_name'] ?: $board['hardware_id'];
        $message = 'Reboot queued for ' . $label . '. It will restart on the next heartbeat.';

        return [
            'success' => true,
            'message' => $message,
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function requestReprovision(int $id): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        $this->boardDAO->bumpConfigVersion($id);
        $label = $board['friendly_name'] ?: $board['hardware_id'];

        return [
            'success' => true,
            'message' => 'Configuration will be sent to ' . $label . ' on the next heartbeat.',
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function setLoraOnly(int $id, bool $loraOnly): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }
        $already = !empty($board['lora_only']);
        if ($already === $loraOnly) {
            $label = $board['friendly_name'] ?: $board['hardware_id'];
            return [
                'success' => true,
                'message' => $loraOnly
                    ? $label . ' is already LoRa only.'
                    : $label . ' may already use WiFi.',
                'data' => ['id' => $id],
                'error' => null,
            ];
        }
        $this->boardDAO->setLoraOnly($id, $loraOnly);
        $label = $board['friendly_name'] ?: $board['hardware_id'];
        AppLog::info('lora_only=' . ($loraOnly ? '1' : '0') . ' board=' . $board['hardware_id']);

        return [
            'success' => true,
            'message' => $loraOnly
                ? $label . ' will switch to LoRa only after it confirms an upstream LoRa path.'
                : $label . ' may use WiFi again on the next config push.',
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function loraCredentialForUsb(int $id): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }
        $cred = $this->ensureCredential($id);
        $fresh = $this->boardDAO->findById($id) ?? $board;

        return [
            'success' => true,
            'message' => 'LoRa board credential ready.',
            'data' => [
                'hardware_id' => $fresh['hardware_id'],
                'credential' => $cred,
            ],
            'error' => null,
        ];
    }

    private function ensureCredential(int $id): string
    {
        $board = $this->boardDAO->findById($id);
        $existing = strtoupper(trim((string) ($board['lora_credential'] ?? '')));
        if (preg_match('/^[0-9A-F]{32}$/', $existing)) {
            return $existing;
        }
        $cred = LoraKeys::randomHex();
        $this->boardDAO->ensureLoraCredential($id, $cred);
        $again = $this->boardDAO->findById($id);

        return strtoupper(trim((string) ($again['lora_credential'] ?? $cred)));
    }

    public function ensureBoard(string $hardwareId): array
    {
        $existing = $this->boardDAO->findByHardwareId($hardwareId);
        if ($existing !== null) {
            return $existing;
        }

        $id = $this->boardDAO->create($hardwareId);
        $this->virtualDeviceService->ensureForBoard($id);
        $created = $this->boardDAO->findById($id);
        return $created ?? ['id' => $id, 'hardware_id' => $hardwareId];
    }

    public function listInventory(): array
    {
        $listed = $this->listBoards();
        $boards = $listed['data']['boards'] ?? [];
        $boards = array_values(array_filter($boards, static function (array $board): bool {
            $siteId = $board['site_id'] ?? null;
            return $siteId === null || $siteId === '' || (int) $siteId === 0;
        }));
        usort($boards, static function (array $a, array $b): int {
            $aName = (string) ($a['friendly_name'] ?: $a['hardware_id']);
            $bName = (string) ($b['friendly_name'] ?: $b['hardware_id']);
            return strcasecmp($aName, $bName);
        });

        $versions = $this->usbFirmwareOptions();

        return [
            'success' => true,
            'message' => 'Inventory loaded.',
            'data' => [
                'boards' => $boards,
                'sites' => $this->siteDAO->findAll(),
                'board_types' => $this->boardTypeDAO->findAll(),
                'firmware_versions' => $versions,
            ],
            'error' => null,
        ];
    }

    public function usbPage(): array
    {
        return [
            'success' => true,
            'message' => 'USB tools loaded.',
            'data' => [
                'firmware_versions' => $this->usbFirmwareOptions(),
                'board_types' => $this->boardTypeDAO->findAll(),
                'sites' => array_map(static function (array $site): array {
                    return [
                        'id' => (int) $site['id'],
                        'name' => (string) $site['name'],
                    ];
                }, $this->siteDAO->findAll()),
            ],
            'error' => null,
        ];
    }

    /**
     * @return list<array{id: int, version: string, board_type_id: int, board_type_name: string}>
     */
    private function usbFirmwareOptions(): array
    {
        $typeNames = [];
        foreach ($this->boardTypeDAO->findAll() as $type) {
            $typeNames[(int) $type['id']] = (string) $type['name'];
        }
        $versions = [];
        foreach ($this->firmwareVersionDAO->findAll() as $version) {
            if (!$this->firmwareService->hasUsbFactory($version)) {
                continue;
            }
            $typeId = (int) ($version['board_type_id'] ?? 0);
            $versions[] = [
                'id' => (int) $version['id'],
                'version' => (string) $version['version'],
                'board_type_id' => $typeId,
                'board_type_name' => $typeNames[$typeId] ?? '',
            ];
        }

        return $versions;
    }

    public function identifyUsb(string $hardwareId, ?string $boardType = null): array
    {
        $candidates = HardwareId::candidates($hardwareId);
        if ($candidates === []) {
            return $this->fail('That USB MAC is not a board identifier.', 'VALIDATION_ERROR');
        }
        $typeId = null;
        $reportedType = $boardType !== null ? trim($boardType) : '';
        if ($reportedType !== '') {
            $type = $this->boardTypeDAO->findByName($reportedType);
            if ($type === null) {
                return $this->fail('That board type is not known.', 'VALIDATION_ERROR');
            }
            $typeId = (int) $type['id'];
        }

        foreach ($candidates as $candidate) {
            $existing = $this->boardDAO->findByHardwareId($candidate);
            if ($existing !== null) {
                $this->ensureCredential((int) $existing['id']);
                $this->virtualDeviceService->ensureForBoard((int) $existing['id']);
                $existing = $this->boardDAO->findById((int) $existing['id']) ?? $existing;
                return $this->okUsbBoard($existing, false, 'Matched ' . $candidate . ' on USB.');
            }
        }

        $storeId = HardwareId::fromUsbMac($hardwareId) ?? $candidates[0];
        if ($typeId === null) {
            return [
                'success' => false,
                'message' => 'Choose a board type for ' . $storeId . '.',
                'data' => ['hardware_id' => $storeId],
                'error' => ['code' => 'BOARD_TYPE_REQUIRED', 'details' => []],
            ];
        }
        $id = $this->boardDAO->create($storeId, null, null, $typeId);
        $this->ensureCredential($id);
        $this->virtualDeviceService->ensureForBoard($id);
        $created = $this->boardDAO->findById($id);
        if ($created === null) {
            return $this->fail('Unable to add the board to inventory.', 'INTERNAL_ERROR');
        }

        return $this->okUsbBoard($created, true, 'Added ' . $storeId . ' to inventory.');
    }

    public function wipeBoard(int $id): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        $this->boardDAO->wipeToInventory($id);
        $label = $board['friendly_name'] ?: $board['hardware_id'];

        return [
            'success' => true,
            'message' => $label . ' was wiped and left unassigned in inventory.',
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function deleteFromInventory(int $id): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        $siteId = $board['site_id'] ?? null;
        if ($siteId !== null && $siteId !== '' && (int) $siteId > 0) {
            return $this->fail('Move the board to inventory before deleting it.', 'VALIDATION_ERROR');
        }

        $label = $board['friendly_name'] ?: $board['hardware_id'];
        $this->boardDAO->delete($id);
        AppLog::info('board deleted hardware_id=' . $board['hardware_id']);

        return [
            'success' => true,
            'message' => $label . ' was deleted. Its hardware id was removed, so the next USB connection will ask for the board type.',
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function moveToSite(int $id, ?int $siteId): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        if ($siteId !== null && $this->siteDAO->findById($siteId) === null) {
            return $this->fail('Site not found.', 'NOT_FOUND');
        }

        $this->boardDAO->updateSiteId($id, $siteId);
        $label = $board['friendly_name'] ?: $board['hardware_id'];
        $message = $siteId === null
            ? $label . ' was moved to unassigned inventory.'
            : $label . ' was moved to the site.';

        return [
            'success' => true,
            'message' => $message,
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function recordUsbFirmwareLoad(int $id, int $firmwareId): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }

        $version = $this->firmwareVersionDAO->findById($firmwareId);
        if ($version === null) {
            return $this->fail('Firmware version not found.', 'NOT_FOUND');
        }
        if (!$this->firmwareService->hasUsbFactory($version)) {
            return $this->fail('That version has no USB factory image.', 'VALIDATION_ERROR');
        }

        $this->boardDAO->recordUsbFirmwareLoad($id, (string) $version['version']);
        $fresh = $this->boardDAO->findById($id) ?? $board;
        $label = $fresh['friendly_name'] ?: $fresh['hardware_id'];
        $message = $label . ' loaded firmware ' . $version['version'] . ' over USB.';

        return [
            'success' => true,
            'message' => $message,
            'data' => [
                'id' => $id,
                'site_id' => $fresh['site_id'] !== null ? (int) $fresh['site_id'] : null,
            ],
            'error' => null,
        ];
    }

    public function usbConfigForBoard(int $id): array
    {
        $board = $this->boardDAO->findById($id);
        if ($board === null) {
            return $this->fail('Board not found.', 'NOT_FOUND');
        }
        $this->ensureCredential($id);
        $board = $this->boardDAO->findById($id) ?? $board;
        $siteId = isset($board['site_id']) ? (int) $board['site_id'] : 0;
        if ($siteId < 1) {
            return [
                'success' => true,
                'message' => 'Board is unassigned.',
                'data' => [
                    'config' => null,
                    'site' => null,
                ],
                'error' => null,
            ];
        }

        $site = $this->siteDAO->findById($siteId);
        if ($site === null) {
            return $this->fail('Site not found.', 'NOT_FOUND');
        }
        if (empty($site['lora_key'])) {
            $this->siteDAO->ensureLoraKey($siteId, LoraKeys::randomHex());
            $site = $this->siteDAO->findById($siteId) ?? $site;
        }

        $networks = [];
        foreach ($this->wifiNetworkDAO->findBySiteId($siteId) as $network) {
            $networks[] = [
                'ssid' => $network['ssid'],
                'password' => $network['password'],
                'security' => WifiSecurity::normalize($network['security'] ?? '') ?? WifiSecurity::WPA2,
            ];
        }

        $config = array_merge([
            'version' => $board['config_updated_at'],
            'friendly_name' => $board['friendly_name'] ?: $board['hardware_id'],
            'site_id' => $siteId,
            'pulse_duration_ms' => PulseDuration::resolveFromBoard($board),
            'wifi_networks' => $networks,
            'lora_only' => !empty($board['lora_only']),
        ], FleetSettings::forBoardConfig());
        $config['lora_key_id'] = (int) ($site['lora_key_id'] ?? 1);
        $siteKey = strtoupper(trim((string) ($site['lora_key'] ?? '')));
        $cred = strtoupper(trim((string) ($board['lora_credential'] ?? '')));
        if ($cred !== '' && $siteKey !== '') {
            $wrapped = LoraKeys::wrapSiteKey($cred, $siteKey);
            if ($wrapped !== null) {
                $config['lora_site_key_wrapped'] = $wrapped;
            }
        }

        return [
            'success' => true,
            'message' => 'USB site configuration ready.',
            'data' => [
                'config' => $config,
                'site' => [
                    'id' => $siteId,
                    'name' => (string) ($site['name'] ?? ''),
                ],
            ],
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $board
     */
    private function okUsbBoard(array $board, bool $created, string $message): array
    {
        $presented = $this->present($board);

        return [
            'success' => true,
            'message' => $message,
            'data' => [
                'board' => $presented,
                'created' => $created,
            ],
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    private function presentBoardPulse(array $board): array
    {
        $boardOverride = PulseDuration::stored(
            (int) ($board['relay_count'] ?? 1) === 1
                ? ($board['primary_relay_pulse_ms'] ?? null)
                : ($board['pulse_duration_ms'] ?? null)
        );
        $siteOverride = PulseDuration::stored($board['site_pulse_duration_ms'] ?? null);
        $board['pulse_inherit'] = $boardOverride === null;
        $board['pulse_inherited'] = PulseDuration::resolve(null, $siteOverride);
        $board['pulse_resolved'] = PulseDuration::resolve($boardOverride, $siteOverride);
        $board['pulse_default'] = PulseDuration::appDefault();
        $board['pulse_duration_ms'] = $boardOverride;

        return $board;
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
