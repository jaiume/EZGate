<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LoraDownlinkConfig;
use PHPUnit\Framework\TestCase;

final class LoraDownlinkConfigTest extends TestCase
{
    public function testDetectsLoraFromStatusAndJson(): void
    {
        $this->assertTrue(LoraDownlinkConfig::isLoraUplink(['last_status' => 'lora']));
        $this->assertTrue(LoraDownlinkConfig::isLoraUplink([
            'last_status' => 'unknown',
            'last_connectivity_json' => '{"current":{"type":"lora"}}',
        ]));
        $this->assertFalse(LoraDownlinkConfig::isLoraUplink([
            'last_status' => 'wifi',
            'last_connectivity_json' => '{"current":{"type":"wifi"}}',
        ]));
    }

    public function testCompactFitsLoRaDownlinkAndKeepsLoraOnly(): void
    {
        $compact = LoraDownlinkConfig::compact([
            'version' => '2026-09-29 04:06:13',
            'lora_only' => false,
            'pulse_duration_ms' => 500,
            'friendly_name' => 'TestBoard Alpha',
            'wifi_roam_rssi_dbm' => -80,
            'wifi_roam_hysteresis_dbm' => 8,
            'wifi_networks' => [['ssid' => 'WifiVentures', 'password' => str_repeat('x', 64)]],
            'lora_site_key_wrapped' => str_repeat('A', 88),
            'internet_host' => '8.8.8.8',
        ]);

        $json = json_encode($compact, JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($json);
        $this->assertLessThan(LoraDownlinkConfig::MAX_BYTES, strlen((string) $json));
        $this->assertSame('2026-09-29 04:06:13', $compact['version']);
        $this->assertFalse($compact['lora_only']);
        $this->assertSame(500, $compact['pulse_duration_ms']);
        $this->assertArrayNotHasKey('friendly_name', $compact);
        $this->assertArrayNotHasKey('wifi_roam_rssi_dbm', $compact);
        $this->assertArrayNotHasKey('wifi_networks', $compact);
        $this->assertArrayNotHasKey('lora_site_key_wrapped', $compact);
    }
}
