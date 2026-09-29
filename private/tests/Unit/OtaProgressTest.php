<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OtaProgress;
use PHPUnit\Framework\TestCase;

final class OtaProgressTest extends TestCase
{
    public function testEmptyWhenNotPending(): void
    {
        $board = OtaProgress::attach([
            'firmware_pending' => false,
            'ota_offset' => 51200,
            'target_firmware_size' => 971536,
            'target_firmware_version' => '1.0.15',
            'ota_version' => '1.0.15',
        ]);

        $this->assertNull($board['ota_progress_label']);
        $this->assertNull($board['ota_percent']);
    }

    public function testFormatsBytesAndPercent(): void
    {
        $board = OtaProgress::attach([
            'firmware_pending' => true,
            'ota_offset' => 51200,
            'ota_version' => '1.0.15',
            'target_firmware_version' => '1.0.15',
            'target_firmware_size' => 971536,
        ]);

        $this->assertSame(51200, $board['ota_bytes_sent']);
        $this->assertSame(971536, $board['ota_bytes_total']);
        $this->assertSame(5, $board['ota_percent']);
        $this->assertSame('51,200 / 971,536 bytes (5%)', $board['ota_progress_label']);
    }

    public function testIgnoresOffsetForADifferentVersion(): void
    {
        $board = OtaProgress::attach([
            'firmware_pending' => true,
            'ota_offset' => 51200,
            'ota_version' => '1.0.14',
            'target_firmware_version' => '1.0.15',
            'target_firmware_size' => 971536,
        ]);

        $this->assertNull($board['ota_progress_label']);
    }

    public function testPendingWithoutOffsetHasTotalOnly(): void
    {
        $board = OtaProgress::attach([
            'firmware_pending' => true,
            'target_firmware_version' => '1.0.15',
            'target_firmware_size' => 971536,
        ]);

        $this->assertSame(971536, $board['ota_bytes_total']);
        $this->assertNull($board['ota_percent']);
        $this->assertNull($board['ota_progress_label']);
    }
}
