<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WifiSsid;
use PHPUnit\Framework\TestCase;

final class WifiSsidTest extends TestCase
{
    public function testKeepsLeadingAndTrailingSpaces(): void
    {
        $this->assertSame('McLachlan ', WifiSsid::from('McLachlan '));
        $this->assertSame(' McLachlan', WifiSsid::from(' McLachlan'));
        $this->assertSame(' ', WifiSsid::from(' '));
        $this->assertSame(' ', WifiSsid::orNull(' '));
    }

    public function testStripsSignalStrengthLabel(): void
    {
        $this->assertSame('McLachlan', WifiSsid::from('McLachlan (-84 dBm)'));
        $this->assertSame('McLachlan ', WifiSsid::from('McLachlan  (-70 dBm)'));
        $this->assertSame('McLachlan', WifiSsid::from('McLachlan'));
        $this->assertSame('McLachlan ', WifiSsid::from('McLachlan '));
    }

    public function testEmptyBecomesNull(): void
    {
        $this->assertSame('', WifiSsid::from(null));
        $this->assertSame('', WifiSsid::from(''));
        $this->assertNull(WifiSsid::orNull(null));
        $this->assertNull(WifiSsid::orNull(''));
    }
}
