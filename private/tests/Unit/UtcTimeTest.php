<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\UtcTime;
use PHPUnit\Framework\TestCase;

final class UtcTimeTest extends TestCase
{
    public function testConvertsMysqlUtcToIso(): void
    {
        $this->assertSame('2026-09-27T14:26:17Z', UtcTime::toIso('2026-09-27 14:26:17'));
    }

    public function testTreatsNaiveDatetimeAsUtc(): void
    {
        $this->assertSame('2026-09-27T00:00:00Z', UtcTime::toIso('2026-09-27T00:00:00'));
        $this->assertSame('2026-09-27T00:00:00Z', UtcTime::toIso('2026-09-27 00:00:00Z'));
    }

    public function testReturnsNullForEmpty(): void
    {
        $this->assertNull(UtcTime::toIso(null));
        $this->assertNull(UtcTime::toIso(''));
        $this->assertNull(UtcTime::toIso('   '));
    }

    public function testMarkupUsesTimeElementAndEmptyFallback(): void
    {
        $html = UtcTime::markup('2026-09-27 14:26:17');
        $this->assertStringContainsString('datetime="2026-09-27T14:26:17Z"', $html);
        $this->assertStringContainsString('data-utc', $html);
        $this->assertStringContainsString('2026-09-27 14:26:17 UTC', $html);
        $this->assertSame('Never', UtcTime::markup(null, 'Never'));
    }
}
