<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ConfigService;

class FleetSettings
{
    public static function internetHost(): string
    {
        return self::hostPrefer('wifi.internet_host', 'app.internet_host', '8.8.8.8');
    }

    public static function ntpServer1(): string
    {
        return self::hostPrefer('wifi.ntp_server_1', 'app.ntp_server_1', 'pool.ntp.org');
    }

    public static function ntpServer2(): string
    {
        return self::hostPrefer('wifi.ntp_server_2', 'app.ntp_server_2', 'time.google.com');
    }

    public static function otaChunkBytes(): int
    {
        return self::chunkBytesPrefer('wifi.ota_chunk_bytes', 'app.ota_chunk_bytes', 51200);
    }

    public static function otaLoraChunkBytes(): int
    {
        return self::chunkBytesPrefer('lora.ota_chunk_bytes', 'app.ota_lora_chunk_bytes', 192);
    }

    public static function otaChunkBytesForBoard(array $board): int
    {
        if (LoraDownlinkConfig::isLoraUplink($board)) {
            return self::otaLoraChunkBytes();
        }

        return self::otaChunkBytes();
    }

    /**
     * @return array<string, int|bool|string>
     */
    public static function forBoardConfig(): array
    {
        return array_merge([
            'internet_host' => self::internetHost(),
            'ntp_server_1' => self::ntpServer1(),
            'ntp_server_2' => self::ntpServer2(),
            'ota_chunk_bytes' => self::otaChunkBytes(),
            'ota_lora_chunk_bytes' => self::otaLoraChunkBytes(),
            'wifi_roam_rssi_dbm' => self::signedIntKnobPrefer(
                'wifi.roam_rssi_dbm',
                'app.wifi_roam_rssi_dbm',
                -80,
                -100,
                -50
            ),
            'wifi_roam_hysteresis_dbm' => self::intKnobPrefer(
                'wifi.roam_hysteresis_dbm',
                'app.wifi_roam_hysteresis_dbm',
                8,
                0,
                30
            ),
        ], self::loraKnobs());
    }

    /**
     * @return array<string, int|bool>
     */
    public static function loraKnobs(): array
    {
        return [
            'lora_frequency_hz' => self::intKnob('lora.frequency_hz', 915000000, 100000000, 1000000000),
            'isolation_misses' => self::intKnob('lora.isolation_misses', 3, 1, 20),
            'lora_hop_limit' => self::intKnob('lora.hop_limit', 3, 1, 8),
            'lora_tx_retries' => self::intKnob('lora.tx_retries', 3, 0, 8),
            'lora_discovery_delay_min_ms' => self::intKnob('lora.discovery_delay_min_ms', 50, 0, 2000),
            'lora_discovery_delay_max_ms' => self::intKnob('lora.discovery_delay_max_ms', 250, 0, 5000),
            'lora_stagger_jitter_ms' => self::intKnob('lora.stagger_jitter_ms', 200, 0, 5000),
            'lora_route_expiry_intervals' => self::intKnob('lora.route_expiry_intervals', 3, 1, 20),
            'ota_max_per_gateway' => self::intKnob('lora.ota_max_per_gateway', 1, 1, 8),
            'ota_airtime_budget_ms' => self::intKnob('lora.ota_airtime_budget_ms', 400, 0, 5000),
            'lora_log_radio' => self::boolKnob('lora.log_radio', true),
        ];
    }

    public static function wifiHeartbeatIntervalSeconds(): int
    {
        return self::intKnobPrefer(
            'wifi.heartbeat_interval_seconds',
            'app.heartbeat_interval_seconds',
            5,
            2,
            120
        );
    }

    public static function loraHeartbeatIntervalSeconds(): int
    {
        return self::intKnobPrefer(
            'lora.heartbeat_interval_seconds',
            'app.heartbeat_interval_seconds',
            5,
            2,
            120
        );
    }

    public static function heartbeatIntervalSeconds(): int
    {
        return self::wifiHeartbeatIntervalSeconds();
    }

    public static function heartbeatIntervalSecondsForBoard(array $board): int
    {
        if (LoraDownlinkConfig::isLoraUplink($board)) {
            return self::loraHeartbeatIntervalSeconds();
        }

        return self::wifiHeartbeatIntervalSeconds();
    }

    public static function openCommandTtlSeconds(): int
    {
        return self::wifiHeartbeatIntervalSeconds() * 4;
    }

    public static function openCommandTtlSecondsForBoard(array $board): int
    {
        return self::heartbeatIntervalSecondsForBoard($board) * 4;
    }

    private static function intKnobPrefer(
        string $preferred,
        string $legacy,
        int $fallback,
        int $min,
        int $max
    ): int {
        $raw = ConfigService::get($preferred, null);
        if ($raw === null || $raw === '') {
            return self::intKnob($legacy, $fallback, $min, $max);
        }

        $value = (int) $raw;
        if ($value <= 0) {
            return self::intKnob($legacy, $fallback, $min, $max);
        }

        return max($min, min($max, $value));
    }

    private static function signedIntKnobPrefer(
        string $preferred,
        string $legacy,
        int $fallback,
        int $min,
        int $max
    ): int {
        $raw = ConfigService::get($preferred, null);
        if ($raw === null || $raw === '') {
            return self::signedIntKnob($legacy, $fallback, $min, $max);
        }

        return max($min, min($max, (int) $raw));
    }

    private static function hostPrefer(string $preferred, string $legacy, string $fallback): string
    {
        $host = trim((string) ConfigService::get($preferred, ''));
        if ($host !== '') {
            return $host;
        }

        return self::host($legacy, $fallback);
    }

    private static function chunkBytesPrefer(string $preferred, string $legacy, int $fallback): int
    {
        $raw = ConfigService::get($preferred, null);
        if ($raw === null || $raw === '') {
            return self::chunkBytes($legacy, $fallback);
        }

        $value = (int) $raw;
        if ($value <= 0) {
            return self::chunkBytes($legacy, $fallback);
        }

        return max(32, min(524288, $value));
    }

    private static function intKnob(string $key, int $fallback, int $min, int $max): int
    {
        $value = (int) ConfigService::get($key, $fallback);
        if ($value <= 0) {
            $value = $fallback;
        }

        return max($min, min($max, $value));
    }

    private static function signedIntKnob(string $key, int $fallback, int $min, int $max): int
    {
        $value = (int) ConfigService::get($key, $fallback);

        return max($min, min($max, $value));
    }

    private static function boolKnob(string $key, bool $fallback): bool
    {
        $value = ConfigService::get($key, $fallback);
        if (is_bool($value)) {
            return $value;
        }
        $text = strtolower(trim((string) $value));
        if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $fallback;
    }

    private static function host(string $key, string $fallback): string
    {
        $host = trim((string) ConfigService::get($key, $fallback));
        return $host !== '' ? $host : $fallback;
    }

    private static function chunkBytes(string $key, int $fallback): int
    {
        $value = (int) ConfigService::get($key, $fallback);
        if ($value <= 0) {
            $value = $fallback;
        }

        return max(32, min(524288, $value));
    }
}
