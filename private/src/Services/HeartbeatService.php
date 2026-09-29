<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;
use App\DAO\OpenCommandDAO;
use App\DAO\SiteDAO;
use App\DAO\WifiNetworkDAO;
use App\Support\AppLog;
use App\Support\FirmwareAlreadyRunning;
use App\Support\FleetSettings;
use App\Support\LoraDownlinkConfig;
use App\Support\LoraKeys;
use App\Support\PulseDuration;
use App\Support\WifiScan;
use App\Support\WifiSecurity;
use App\Support\WifiSsid;

class HeartbeatService
{
    public function __construct(
        private BoardDAO $boardDAO,
        private WifiNetworkDAO $wifiNetworkDAO,
        private OpenCommandDAO $openCommandDAO,
        private ConfigVersionService $configVersionService,
        private FirmwareService $firmwareService,
        private SiteDAO $siteDAO
    ) {
    }

    public function process(array $payload): array
    {
        $hardwareId = trim((string) ($payload['hardware_id'] ?? ''));
        if ($hardwareId === '') {
            return $this->fail('Board identifier is required.', 'VALIDATION_ERROR');
        }

        $board = $this->boardDAO->findByHardwareId($hardwareId);
        if ($board === null) {
            $id = $this->boardDAO->create($hardwareId);
            $this->boardDAO->ensureLoraCredential($id, LoraKeys::randomHex());
            $board = $this->boardDAO->findById($id);
        }
        if ($board === null) {
            return $this->fail('Unable to record board.', 'INTERNAL_ERROR');
        }

        $boardId = (int) $board['id'];
        $completedId = isset($payload['completed_command_id']) ? (int) $payload['completed_command_id'] : 0;
        if ($completedId > 0) {
            $this->openCommandDAO->markCompleted($completedId, $boardId);
            AppLog::info('open executed board=' . $hardwareId . ' command_id=' . $completedId);
        }
        $receivedId = isset($payload['received_command_id']) ? (int) $payload['received_command_id'] : 0;
        if ($receivedId > 0) {
            $this->openCommandDAO->markDelivered($receivedId, $boardId);
            AppLog::info('open delivered board=' . $hardwareId . ' command_id=' . $receivedId);
        }

        $status = trim((string) ($payload['status'] ?? 'unknown'));
        $gateState = $this->nullableString($payload['gate_state'] ?? null);
        $connectivity = $payload['connectivity'] ?? [];
        if (!is_array($connectivity)) {
            $connectivity = [];
        }
        $previousConnectivity = json_decode((string) ($board['last_connectivity_json'] ?? ''), true);
        if (!is_array($previousConnectivity)) {
            $previousConnectivity = [];
        }
        $incomingOther = $connectivity['other'] ?? null;
        if (is_array($incomingOther) && $incomingOther !== []) {
            $connectivity['other'] = WifiScan::uniqueBySsid($incomingOther);
        } elseif (!empty($payload['scanned']) && is_array($incomingOther)) {
            $connectivity['other'] = [];
        } elseif (isset($previousConnectivity['other']) && is_array($previousConnectivity['other'])) {
            $connectivity['other'] = $previousConnectivity['other'];
        }
        $current = is_array($connectivity['current'] ?? null) ? $connectivity['current'] : [];
        if (isset($payload['radio_stats']) && is_array($payload['radio_stats'])) {
            $current['radio_stats'] = $payload['radio_stats'];
            $connectivity['current'] = $current;
            $drops = (int) ($payload['radio_stats']['queue_drop_open'] ?? 0)
                + (int) ($payload['radio_stats']['queue_drop_hb'] ?? 0)
                + (int) ($payload['radio_stats']['queue_drop_config'] ?? 0)
                + (int) ($payload['radio_stats']['queue_drop_ota'] ?? 0);
            if ($drops > 0) {
                AppLog::info('queue drop board=' . $hardwareId
                    . ' open=' . (int) ($payload['radio_stats']['queue_drop_open'] ?? 0)
                    . ' hb=' . (int) ($payload['radio_stats']['queue_drop_hb'] ?? 0)
                    . ' config=' . (int) ($payload['radio_stats']['queue_drop_config'] ?? 0)
                    . ' ota=' . (int) ($payload['radio_stats']['queue_drop_ota'] ?? 0));
            }
        }
        $relayedBy = trim((string) ($payload['relayed_by'] ?? $current['gateway'] ?? ''));
        if ($relayedBy !== '') {
            AppLog::info('heartbeat relayed board=' . $hardwareId . ' via=' . $relayedBy);
        }
        $wifiSsid = WifiSsid::orNull($current['ssid'] ?? ($payload['wifi_ssid'] ?? null));
        $wifiRssi = isset($current['rssi']) ? (int) $current['rssi'] : (isset($payload['wifi_rssi']) ? (int) $payload['wifi_rssi'] : null);
        $reportedVersion = $this->nullableString($payload['config_version'] ?? null);
        if ($reportedVersion !== null && strcasecmp($status, 'lora') === 0) {
            AppLog::info('lora config reported board=' . $hardwareId . ' version=' . $reportedVersion);
        }
        $radioStats = is_array($payload['radio_stats'] ?? null) ? $payload['radio_stats'] : [];
        $chunkTotal = (int) ($radioStats['chunk_total'] ?? 0);
        if ($chunkTotal > 0) {
            AppLog::info(
                'lora chunk board=' . $hardwareId
                . ' kind=' . (string) ($radioStats['chunk_kind'] ?? 'blob')
                . ' ' . (int) ($radioStats['chunk_got'] ?? 0)
                . '/' . $chunkTotal
            );
        }

        $reportedFirmware = $this->nullableString($payload['firmware_version'] ?? null);
        $reportedFallbackFirmware = $this->nullableString($payload['fallback_firmware_version'] ?? null);
        $uptimeSeconds = $this->nullableUptime($payload['uptime_seconds'] ?? null);
        $otaVersion = $this->nullableString($payload['ota_version'] ?? null);
        $otaOffset = $this->nullableOtaOffset($payload['ota_offset'] ?? null);
        if (FirmwareAlreadyRunning::matches($reportedFirmware, (string) ($board['target_firmware_version'] ?? ''))) {
            $otaVersion = null;
            $otaOffset = null;
        }

        if (!empty($payload['scanned'])) {
            $this->boardDAO->clearScanRequested($boardId);
        }

        $this->boardDAO->recordHeartbeat(
            $boardId,
            $status !== '' ? $status : 'unknown',
            $gateState,
            $wifiSsid,
            $wifiRssi,
            json_encode($connectivity, JSON_UNESCAPED_SLASHES) ?: '{}',
            $reportedVersion,
            $reportedFirmware,
            $reportedFallbackFirmware,
            $uptimeSeconds,
            $otaVersion,
            $otaOffset
        );

        $board = $this->boardDAO->findById($boardId) ?? $board;
        $config = null;
        if ($this->configVersionService->shouldSendConfig(
            $board['config_updated_at'] ?? null,
            $board['reported_config_version'] ?? null
        )) {
            $config = $this->buildConfig($board);
            if (LoraDownlinkConfig::isLoraUplink($board)) {
                AppLog::info(
                    'lora config downlink board=' . $hardwareId
                    . ' bytes=' . strlen((string) json_encode($config, JSON_UNESCAPED_SLASHES))
                    . ' version=' . (string) ($config['version'] ?? '')
                );
            }
        }

        $pending = $this->openCommandDAO->findPendingForBoard($boardId);
        if ($pending !== null) {
            $this->openCommandDAO->markDelivered((int) $pending['id'], $boardId);
        }
        $reboot = !empty($board['reboot_requested']);
        if ($reboot) {
            $this->boardDAO->clearRebootRequested($boardId);
        }
        $board['open_pending'] = $pending !== null;
        $wifiInterval = FleetSettings::wifiHeartbeatIntervalSeconds();
        $loraInterval = FleetSettings::loraHeartbeatIntervalSeconds();
        $interval = FleetSettings::heartbeatIntervalSecondsForBoard($board);

        $payload = [
            'success' => true,
            'message' => 'Heartbeat recorded.',
            'data' => [
                'config' => $config,
                'firmware' => $reboot ? null : $this->firmwareService->pendingPayloadForBoard($board),
                'open' => $pending !== null,
                'command_id' => $pending !== null ? (int) $pending['id'] : null,
                'scan' => !empty($board['scan_requested']),
                'reboot' => $reboot,
                'heartbeat_interval_seconds' => $interval,
                'wifi_heartbeat_interval_seconds' => $wifiInterval,
                'lora_heartbeat_interval_seconds' => $loraInterval,
            ],
            'error' => null,
        ];

        return $this->openCommandDAO->whileLongRunning(static fn () => $payload);
    }

    private function buildConfig(array $board): array
    {
        $networks = [];
        if (!empty($board['site_id'])) {
            foreach ($this->wifiNetworkDAO->findBySiteId((int) $board['site_id']) as $network) {
                $networks[] = [
                    'ssid' => $network['ssid'],
                    'password' => $network['password'],
                    'security' => WifiSecurity::normalize($network['security'] ?? '') ?? WifiSecurity::WPA2,
                ];
            }
        }

        $config = array_merge([
            'version' => $board['config_updated_at'],
            'friendly_name' => $board['friendly_name'],
            'site_id' => $board['site_id'] !== null ? (int) $board['site_id'] : null,
            'pulse_duration_ms' => PulseDuration::resolveFromBoard($board),
            'wifi_networks' => $networks,
            'lora_only' => !empty($board['lora_only']),
        ], FleetSettings::forBoardConfig());

        if (!empty($board['site_id'])) {
            $site = $this->siteDAO->findById((int) $board['site_id']);
            if ($site !== null) {
                if (empty($site['lora_key'])) {
                    $this->siteDAO->ensureLoraKey((int) $site['id'], LoraKeys::randomHex());
                    $site = $this->siteDAO->findById((int) $site['id']) ?? $site;
                }
                $config['lora_key_id'] = (int) ($site['lora_key_id'] ?? 1);
                $siteKey = strtoupper(trim((string) ($site['lora_key'] ?? '')));
                $cred = strtoupper(trim((string) ($board['lora_credential'] ?? '')));
                if ($cred !== '' && $siteKey !== '') {
                    $wrapped = LoraKeys::wrapSiteKey($cred, $siteKey);
                    if ($wrapped !== null) {
                        $config['lora_site_key_wrapped'] = $wrapped;
                    }
                }
            }
        }

        return $config;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function nullableUptime(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $seconds = (int) $value;
        if ($seconds < 0) {
            return 0;
        }
        if ($seconds > 4294967295) {
            return 4294967295;
        }

        return $seconds;
    }

    private function nullableOtaOffset(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $offset = (int) $value;
        return $offset < 0 ? 0 : $offset;
    }

    private function fail(string $message, string $code): array
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => ['code' => $code, 'details' => []],
        ];

        return $this->openCommandDAO->whileLongRunning(static fn () => $payload);
    }
}
