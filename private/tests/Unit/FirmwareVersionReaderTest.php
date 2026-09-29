<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FirmwareVersionReader;
use PHPUnit\Framework\TestCase;

final class FirmwareVersionReaderTest extends TestCase
{
    public function testSkipsEmptyMarkerThenReadsVersion(): void
    {
        $blob = FirmwareVersionReader::MARKER . "\0wpa2\0" . FirmwareVersionReader::MARKER . "1.0.25\0";
        $this->assertSame('1.0.25', FirmwareVersionReader::fromBytes($blob));
    }

    public function testReadsMarkedVersion(): void
    {
        $blob = "header\0" . FirmwareVersionReader::MARKER . "1.2.3-rc1\0trailer";
        $this->assertSame('1.2.3-rc1', FirmwareVersionReader::fromBytes($blob));
    }

    public function testReadsEspAppDescVersion(): void
    {
        $desc = "\x32\x54\xCD\xAB" . str_repeat("\0", 12) . str_pad('0.4.0', 32, "\0");
        $this->assertSame('0.4.0', FirmwareVersionReader::fromBytes('xxxx' . $desc . 'yyyy'));
    }

    public function testPrefersMarkerOverEspDesc(): void
    {
        $desc = "\x32\x54\xCD\xAB" . str_repeat("\0", 12) . str_pad('9.9.9', 32, "\0");
        $blob = $desc . FirmwareVersionReader::MARKER . "0.1.0\0";
        $this->assertSame('0.1.0', FirmwareVersionReader::fromBytes($blob));
    }

    public function testReturnsNullWhenMissing(): void
    {
        $this->assertNull(FirmwareVersionReader::fromBytes('not a firmware image'));
    }

    public function testRejectsTinyMarkedStubAsEspImage(): void
    {
        $blob = 'padding ' . FirmwareVersionReader::MARKER . "0.3.1";
        $this->assertSame('0.3.1', FirmwareVersionReader::fromBytes($blob));
        $this->assertFalse(FirmwareVersionReader::isEspAppImage($blob));
    }

    public function testRejectsLargeBlobWithoutEspMagic(): void
    {
        $blob = str_repeat('A', FirmwareVersionReader::MIN_APP_BYTES);
        $this->assertFalse(FirmwareVersionReader::isEspAppImage($blob));
    }

    public function testAcceptsEspMagicAndMinimumSize(): void
    {
        $blob = FirmwareVersionReader::ESP_IMAGE_MAGIC . str_repeat("\0", FirmwareVersionReader::MIN_APP_BYTES - 1);
        $this->assertTrue(FirmwareVersionReader::isEspAppImage($blob));
    }

    public function testAcceptsNormalVersionsAndRejectsIdfBanner(): void
    {
        $this->assertSame('1.0.24', FirmwareVersionReader::accepted('1.0.24'));
        $this->assertNull(FirmwareVersionReader::accepted('esp-idf: v4.4.7 38eeba213a'));
        $this->assertNull(FirmwareVersionReader::accepted('wpa2'));
        $this->assertNull(FirmwareVersionReader::accepted(''));
        $this->assertNull(FirmwareVersionReader::accepted(null));
    }
}
