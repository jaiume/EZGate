<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HardwareId;
use PHPUnit\Framework\TestCase;

final class HardwareIdTest extends TestCase
{
    public function testNormalizesColonMac(): void
    {
        $this->assertSame('240AC4123456', HardwareId::normalize('24:0a:c4:12:34:56'));
    }

    public function testRejectsShortValues(): void
    {
        $this->assertNull(HardwareId::normalize('TESTBOARD1'));
        $this->assertNull(HardwareId::normalize('AABBCC'));
    }

    public function testUsbMacMatchesArduinoEfusePrint(): void
    {
        $this->assertSame('563412C40A24', HardwareId::fromUsbMac('24:0A:C4:12:34:56'));
    }

    public function testCandidatesIncludeBothByteOrders(): void
    {
        $this->assertSame(
            ['563412C40A24', '240AC4123456'],
            HardwareId::candidates('24:0a:c4:12:34:56')
        );
    }
}
