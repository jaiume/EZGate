<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConfigService;
use App\Support\FleetSettings;
use PHPUnit\Framework\TestCase;

final class FleetSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASE_DIR')) {
            define('BASE_DIR', dirname(__DIR__, 2));
        }
        ConfigService::reset();
    }

    public function testReadsHostsFromIni(): void
    {
        $cfg = FleetSettings::forBoardConfig();
        $this->assertSame('8.8.8.8', $cfg['internet_host']);
        $this->assertSame('pool.ntp.org', $cfg['ntp_server_1']);
        $this->assertSame('time.google.com', $cfg['ntp_server_2']);
        $this->assertSame(51200, $cfg['ota_chunk_bytes']);
        $this->assertSame(192, $cfg['ota_lora_chunk_bytes']);
        $this->assertSame(915000000, $cfg['lora_frequency_hz']);
        $this->assertArrayNotHasKey('heartbeat_interval_seconds', $cfg);
        $this->assertArrayNotHasKey('heartbeat_active_seconds', $cfg);
        $this->assertArrayNotHasKey('heartbeat_idle_seconds', $cfg);
        $this->assertSame(3, $cfg['isolation_misses']);
        $this->assertSame(3, $cfg['lora_hop_limit']);
        $this->assertSame(1, $cfg['ota_max_per_gateway']);
        $this->assertSame(400, $cfg['ota_airtime_budget_ms']);
        $this->assertTrue($cfg['lora_log_radio']);
        $this->assertSame(-80, $cfg['wifi_roam_rssi_dbm']);
        $this->assertSame(8, $cfg['wifi_roam_hysteresis_dbm']);
    }

    public function testReadsOtaChunkSizesFromIni(): void
    {
        $this->assertSame(51200, FleetSettings::otaChunkBytes());
        $this->assertSame(192, FleetSettings::otaLoraChunkBytes());
        $this->assertSame(51200, FleetSettings::otaChunkBytesForBoard([]));
        $this->assertSame(
            192,
            FleetSettings::otaChunkBytesForBoard([
                'last_connectivity_json' => '{"current":{"type":"lora"}}',
            ])
        );
    }

    public function testPollIntervalIsWifiHeartbeat(): void
    {
        $this->assertSame(5, FleetSettings::heartbeatIntervalSeconds());
        $this->assertSame(5, FleetSettings::wifiHeartbeatIntervalSeconds());
        $this->assertSame(5, FleetSettings::loraHeartbeatIntervalSeconds());
    }

    public function testOpenCommandTtlFollowsBoardMode(): void
    {
        $this->assertSame(20, FleetSettings::openCommandTtlSeconds());
        $this->assertSame(
            4 * FleetSettings::wifiHeartbeatIntervalSeconds(),
            FleetSettings::openCommandTtlSeconds()
        );
        $this->assertSame(20, FleetSettings::openCommandTtlSecondsForBoard([]));
        $this->assertSame(
            20,
            FleetSettings::openCommandTtlSecondsForBoard([
                'last_connectivity_json' => '{"current":{"type":"lora"}}',
            ])
        );
        $this->assertSame(
            FleetSettings::loraHeartbeatIntervalSeconds(),
            FleetSettings::heartbeatIntervalSecondsForBoard([
                'last_status' => 'lora',
            ])
        );
        $this->assertSame(
            FleetSettings::wifiHeartbeatIntervalSeconds(),
            FleetSettings::heartbeatIntervalSecondsForBoard([
                'last_status' => 'wifi',
            ])
        );
    }
}
