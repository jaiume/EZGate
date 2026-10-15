<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ConfigVersionService;

class BoardPresenter
{
    public static function forList(array $board, string $cutoff): array
    {
        $connectivity = [];
        if (!empty($board['last_connectivity_json'])) {
            $decoded = json_decode((string) $board['last_connectivity_json'], true);
            if (is_array($decoded)) {
                $connectivity = $decoded;
            }
        }
        $current = is_array($connectivity['current'] ?? null) ? $connectivity['current'] : [];
        $others = is_array($connectivity['other'] ?? null) ? $connectivity['other'] : [];
        $stats = is_array($current['radio_stats'] ?? null) ? $current['radio_stats'] : [];
        if (isset($connectivity['radio_stats']) && is_array($connectivity['radio_stats'])) {
            $stats = $connectivity['radio_stats'];
        }
        $linkType = strtolower(trim((string) ($current['type'] ?? $board['last_status'] ?? '')));
        $rssi = $linkType === 'lora'
            ? self::rssiValue($current['parent_rssi'] ?? ($stats['parent_rssi'] ?? null))
            : self::rssiValue($current['rssi'] ?? ($board['last_wifi_rssi'] ?? null));

        $board['id'] = (int) $board['id'];
        $board['site_id'] = isset($board['site_id']) && $board['site_id'] !== '' ? (int) $board['site_id'] : null;
        $board['online'] = !empty($board['last_heartbeat_at']) && $board['last_heartbeat_at'] >= $cutoff;
        $board['link_type'] = $current['type'] ?? $board['last_status'];
        $board['link_label'] = self::linkLabel($board['link_type'] ?? null);
        $board['signal_rssi'] = $rssi;
        $board['portal_route'] = self::portalRoute($board, $current);
        $board['other_options'] = WifiScan::uniqueBySsid($others);
        $board['wifi_ssid'] = WifiSsid::orNull($board['last_wifi_ssid'] ?? ($current['ssid'] ?? null));
        $board['firmware_version'] = $board['reported_firmware_version'] ?? null;
        $board['reported_fallback_firmware_version'] = FirmwareVersionReader::accepted(
            $board['reported_fallback_firmware_version'] ?? null
        );
        $board['scan_requested'] = !empty($board['scan_requested']);
        $board['reboot_requested'] = !empty($board['reboot_requested']);
        $board['firmware_pending'] = FirmwareAlreadyRunning::shouldOffer(
            $board['reported_firmware_version'] ?? null,
            (string) ($board['target_firmware_version'] ?? '')
        );
        $board['config_pending'] = (new ConfigVersionService())->shouldSendConfig(
            $board['config_updated_at'] ?? null,
            $board['reported_config_version'] ?? null
        );
        $board['uptime_label'] = Uptime::label($board['last_uptime_seconds'] ?? null);
        $board['pulse_ms'] = (int) ($board['relay_count'] ?? 1) === 1
            ? PulseDuration::resolve(
                PulseDuration::stored($board['primary_relay_pulse_ms'] ?? null),
                PulseDuration::stored($board['site_pulse_duration_ms'] ?? null)
            )
            : null;
        $board['has_lora'] = !array_key_exists('has_lora', $board) || (int) $board['has_lora'] === 1;
        $board['relay_count'] = (int) ($board['relay_count'] ?? 1);
        $board['wiegand_count'] = (int) ($board['wiegand_count'] ?? 0);
        $board['board_type_name'] = (string) ($board['board_type_name'] ?? '');
        $board['lora_only'] = !empty($board['lora_only']);
        $board['radio_stats'] = $stats !== [] ? $stats : null;
        $board['open_status'] = $board['open_status'] ?? null;

        return OtaProgress::attach($board);
    }

    /**
     * @param array<string, mixed>|null $command
     */
    public static function openStatus(?array $command): ?string
    {
        if ($command === null) {
            return null;
        }
        $status = strtolower(trim((string) ($command['status'] ?? '')));
        if ($status === 'expired') {
            return 'expired';
        }
        if ($status === 'completed') {
            return 'executed';
        }
        if (!empty($command['delivered_at'])) {
            return 'delivered';
        }

        return 'sent';
    }

    /**
     * @param array<string, mixed> $board
     * @param array<string, mixed> $current
     * @return list<array{kind: string, label: string, detail?: string}>
     */
    private static function portalRoute(array $board, array $current): array
    {
        $name = trim((string) ($board['friendly_name'] ?: $board['hardware_id']));
        $hops = [['kind' => 'board', 'label' => $name !== '' ? $name : (string) $board['hardware_id']]];
        $type = strtolower(trim((string) ($current['type'] ?? $board['last_status'] ?? '')));
        $ssid = WifiSsid::orNull($current['ssid'] ?? ($board['last_wifi_ssid'] ?? null));

        if ($type === 'lora') {
            $lora = ['kind' => 'lora', 'label' => 'LoRa'];
            $rssi = self::rssiValue($current['parent_rssi'] ?? ($current['radio_stats']['parent_rssi'] ?? null));
            if ($rssi !== null) {
                $lora['detail'] = $rssi . ' dBm';
            }
            $hops[] = $lora;
            $parent = $current['parent'] ?? $current['gateway'] ?? $current['parent_hardware_id'] ?? null;
            if (is_array($parent)) {
                $parentLabel = (string) ($parent['friendly_name'] ?? $parent['hardware_id'] ?? $parent['id'] ?? '');
                if ($parentLabel !== '') {
                    $hops[] = ['kind' => 'board', 'label' => $parentLabel];
                }
            } elseif (is_string($parent) && trim($parent) !== '') {
                $hops[] = ['kind' => 'board', 'label' => trim($parent)];
            }
            $hops[] = ['kind' => 'portal', 'label' => 'Portal'];
            return $hops;
        }

        if ($type === 'wifi' || $ssid !== null) {
            $rssi = self::rssiValue($current['rssi'] ?? ($board['last_wifi_rssi'] ?? null));
            $wifi = $ssid !== null ? 'WiFi ' . $ssid : 'WiFi';
            $hop = ['kind' => 'wifi', 'label' => $wifi];
            if ($rssi !== null) {
                $hop['detail'] = $rssi . ' dBm';
            }
            $hops[] = $hop;
            $hops[] = ['kind' => 'portal', 'label' => 'Portal'];
            return $hops;
        }

        return $hops;
    }

    private static function rssiValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private static function linkLabel(mixed $type): ?string
    {
        $normalized = strtolower(trim((string) $type));
        return match ($normalized) {
            'wifi' => 'WiFi',
            'ethernet' => 'Ethernet',
            'lora' => 'LoRa',
            default => null,
        };
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
