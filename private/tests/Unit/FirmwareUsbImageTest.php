<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FirmwareUsbImage;
use PHPUnit\Framework\TestCase;

final class FirmwareUsbImageTest extends TestCase
{
    public function testSidecarFilenames(): void
    {
        $stored = 'fw_abc.bin';
        $this->assertSame('fw_abc', FirmwareUsbImage::prefix($stored));
        $this->assertSame('fw_abc.bootloader.bin', FirmwareUsbImage::sidecarFilename($stored, 'bootloader'));
        $this->assertSame('fw_abc.partitions.bin', FirmwareUsbImage::sidecarFilename($stored, 'partitions'));
        $this->assertSame('fw_abc.boot_app0.bin', FirmwareUsbImage::sidecarFilename($stored, 'boot_app0'));
        $this->assertNull(FirmwareUsbImage::sidecarFilename($stored, 'app'));
    }

    public function testHasFactoryRequiresAppAndSidecars(): void
    {
        $dir = sys_get_temp_dir() . '/ezg_usb_' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0777, true));
        $stored = 'fw_test.bin';
        try {
            $this->assertFalse(FirmwareUsbImage::hasFactory($dir, $stored));
            file_put_contents($dir . '/' . $stored, 'app');
            $this->assertFalse(FirmwareUsbImage::hasFactory($dir, $stored));
            file_put_contents($dir . '/fw_test.bootloader.bin', 'boot');
            file_put_contents($dir . '/fw_test.partitions.bin', 'part');
            file_put_contents($dir . '/fw_test.boot_app0.bin', 'ota');
            $this->assertTrue(FirmwareUsbImage::hasFactory($dir, $stored));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    public function testUsbAddresses(): void
    {
        $this->assertSame(0x0, FirmwareUsbImage::PARTS['bootloader']['address']);
        $this->assertSame(0x8000, FirmwareUsbImage::PARTS['partitions']['address']);
        $this->assertSame(0xE000, FirmwareUsbImage::PARTS['boot_app0']['address']);
        $this->assertSame(0x10000, FirmwareUsbImage::APP_ADDRESS);
    }
}
