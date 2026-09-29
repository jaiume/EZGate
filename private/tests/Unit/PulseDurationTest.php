<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConfigService;
use App\Support\PulseDuration;
use PHPUnit\Framework\TestCase;

final class PulseDurationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASE_DIR')) {
            define('BASE_DIR', dirname(__DIR__, 2));
        }
        ConfigService::reset();
    }

    public function testBoardOverrideWins(): void
    {
        $this->assertSame(250, PulseDuration::resolve(250, 800));
    }

    public function testSiteOverrideWhenBoardInherits(): void
    {
        $this->assertSame(800, PulseDuration::resolve(null, 800));
    }

    public function testAppDefaultWhenBothInherit(): void
    {
        $this->assertSame(PulseDuration::appDefault(), PulseDuration::resolve(null, null));
    }

    public function testFormInheritIsNull(): void
    {
        $parsed = PulseDuration::fromForm(['pulse_mode' => 'inherit', 'pulse_duration_ms' => '900']);
        $this->assertTrue($parsed['ok']);
        $this->assertNull($parsed['value']);
    }

    public function testFormCustomKeepsValue(): void
    {
        $parsed = PulseDuration::fromForm(['pulse_mode' => 'custom', 'pulse_duration_ms' => '750']);
        $this->assertTrue($parsed['ok']);
        $this->assertSame(750, $parsed['value']);
    }

    public function testFormCustomRejectsOutOfRange(): void
    {
        $parsed = PulseDuration::fromForm(['pulse_mode' => 'custom', 'pulse_duration_ms' => '10']);
        $this->assertFalse($parsed['ok']);
    }

    public function testStoredTreatsBlankAsInherit(): void
    {
        $this->assertNull(PulseDuration::stored(null));
        $this->assertNull(PulseDuration::stored(''));
        $this->assertSame(500, PulseDuration::stored('500'));
    }

    public function testResolveFromBoardUsesSiteColumn(): void
    {
        $this->assertSame(900, PulseDuration::resolveFromBoard([
            'pulse_duration_ms' => null,
            'site_pulse_duration_ms' => 900,
        ]));
        $this->assertSame(120, PulseDuration::resolveFromBoard([
            'pulse_duration_ms' => 120,
            'site_pulse_duration_ms' => 900,
        ]));
    }
}
