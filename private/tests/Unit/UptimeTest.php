<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Uptime;
use PHPUnit\Framework\TestCase;

final class UptimeTest extends TestCase
{
    public function testZeroIsSeconds(): void
    {
        $this->assertSame('0s', Uptime::label(0));
    }

    public function testMinutesAndSeconds(): void
    {
        $this->assertSame('1m 5s', Uptime::label(65));
    }

    public function testHoursMinutes(): void
    {
        $this->assertSame('2h 3m', Uptime::label(7380));
    }

    public function testDaysHours(): void
    {
        $this->assertSame('1d 2h 3m 4s', Uptime::label(93784));
    }

    public function testNullAndInvalid(): void
    {
        $this->assertNull(Uptime::label(null));
        $this->assertNull(Uptime::label(''));
        $this->assertNull(Uptime::label('nope'));
        $this->assertNull(Uptime::label(-1));
    }
}
