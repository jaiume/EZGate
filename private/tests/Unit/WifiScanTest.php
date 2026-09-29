<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WifiScan;
use PHPUnit\Framework\TestCase;

final class WifiScanTest extends TestCase
{
    public function testKeepsStrongestApPerSsid(): void
    {
        $unique = WifiScan::uniqueBySsid([
            ['ssid' => 'McLachlan', 'rssi' => -95, 'security' => 'wpa2'],
            ['ssid' => 'WifiVentures', 'rssi' => -90, 'security' => 'wpa2'],
            ['ssid' => 'McLachlan', 'rssi' => -88, 'security' => 'wpa2'],
            ['ssid' => 'McLachlan', 'rssi' => -89, 'security' => 'wpa2'],
            ['ssid' => '', 'rssi' => -40],
        ]);

        $this->assertSame(['McLachlan', 'WifiVentures'], array_column($unique, 'ssid'));
        $this->assertSame(-88, $unique[0]['rssi']);
        $this->assertSame(-90, $unique[1]['rssi']);
    }

    public function testKeepsTrailingSpacesInSsid(): void
    {
        $unique = WifiScan::uniqueBySsid([
            ['ssid' => 'McLachlan', 'rssi' => -80, 'security' => 'wpa2'],
            ['ssid' => 'McLachlan ', 'rssi' => -70, 'security' => 'wpa2'],
            ['ssid' => ' ', 'rssi' => -60, 'security' => 'wpa2'],
        ]);

        $this->assertSame([' ', 'McLachlan ', 'McLachlan'], array_column($unique, 'ssid'));
    }

    public function testDoesNotTreatRssiLabelAsSsid(): void
    {
        $unique = WifiScan::uniqueBySsid([
            ['ssid' => 'McLachlan (-95 dBm)', 'rssi' => -95, 'security' => 'wpa2'],
            ['ssid' => 'McLachlan', 'rssi' => -88, 'security' => 'wpa2'],
        ]);

        $this->assertCount(1, $unique);
        $this->assertSame('McLachlan', $unique[0]['ssid']);
        $this->assertSame(-88, $unique[0]['rssi']);
    }
}
