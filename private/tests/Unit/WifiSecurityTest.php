<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WifiSecurity;
use PHPUnit\Framework\TestCase;

final class WifiSecurityTest extends TestCase
{
    public function testNormalizesKnownValues(): void
    {
        $this->assertSame('open', WifiSecurity::normalize('Open'));
        $this->assertSame('open', WifiSecurity::normalize(0));
        $this->assertSame('wpa2', WifiSecurity::normalize('wpa2-psk'));
        $this->assertSame('wpa3', WifiSecurity::normalize('WIFI_AUTH_WPA3_PSK'));
        $this->assertSame('wpa3', WifiSecurity::normalize(7));
    }

    public function testRejectsUnknownValues(): void
    {
        $this->assertNull(WifiSecurity::normalize('wep'));
        $this->assertNull(WifiSecurity::normalize(''));
    }

    public function testPrefersStrongerReportedType(): void
    {
        $this->assertSame('wpa3', WifiSecurity::prefer('wpa2', 'wpa3'));
        $this->assertSame('wpa2', WifiSecurity::prefer('open', 'wpa2'));
        $this->assertSame('wpa2', WifiSecurity::prefer('wpa2', 'open'));
        $this->assertSame('wpa2', WifiSecurity::prefer(null, 'wpa2'));
    }

    public function testSecuredRequiresPassword(): void
    {
        $this->assertSame('secret', WifiSecurity::passwordFor('wpa2', ' secret '));
        $this->assertNull(WifiSecurity::passwordFor('wpa3', ''));
        $this->assertNull(WifiSecurity::passwordFor('open', 'secret'));
    }
}
