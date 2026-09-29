<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FirmwareAlreadyRunning;
use PHPUnit\Framework\TestCase;

final class FirmwareAlreadyRunningTest extends TestCase
{
    public function testMatchesSameVersion(): void
    {
        $this->assertTrue(FirmwareAlreadyRunning::matches('0.2.0', '0.2.0'));
    }

    public function testTrimsWhitespace(): void
    {
        $this->assertTrue(FirmwareAlreadyRunning::matches(' 0.2.0 ', '0.2.0'));
    }

    public function testDifferentVersionIsAllowed(): void
    {
        $this->assertFalse(FirmwareAlreadyRunning::matches('0.2.0', '0.3.1'));
    }

    public function testUnknownReportedIsAllowed(): void
    {
        $this->assertFalse(FirmwareAlreadyRunning::matches(null, '0.2.0'));
        $this->assertFalse(FirmwareAlreadyRunning::matches('', '0.2.0'));
        $this->assertFalse(FirmwareAlreadyRunning::matches('0.2.0', ''));
    }

    public function testOffersNewerTarget(): void
    {
        $this->assertTrue(FirmwareAlreadyRunning::shouldOffer('1.0.1', '1.0.3'));
        $this->assertTrue(FirmwareAlreadyRunning::shouldOffer(null, '1.0.3'));
        $this->assertTrue(FirmwareAlreadyRunning::shouldOffer('', '1.0.3'));
    }

    public function testDoesNotOfferSameOrOlderTarget(): void
    {
        $this->assertFalse(FirmwareAlreadyRunning::shouldOffer('1.0.3', '1.0.3'));
        $this->assertFalse(FirmwareAlreadyRunning::shouldOffer('1.0.3', '1.0.2'));
        $this->assertFalse(FirmwareAlreadyRunning::shouldOffer('1.0.1', ''));
    }
}
