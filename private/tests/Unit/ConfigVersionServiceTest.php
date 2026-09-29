<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConfigVersionService;
use PHPUnit\Framework\TestCase;

final class ConfigVersionServiceTest extends TestCase
{
    private ConfigVersionService $service;

    protected function setUp(): void
    {
        $this->service = new ConfigVersionService();
    }

    public function testSendsWhenBoardHasNoVersion(): void
    {
        $this->assertTrue($this->service->shouldSendConfig('2026-09-27 12:00:00', null));
        $this->assertTrue($this->service->shouldSendConfig('2026-09-27 12:00:00', ''));
    }

    public function testSendsWhenPortalIsNewer(): void
    {
        $this->assertTrue($this->service->shouldSendConfig('2026-09-27 12:00:01', '2026-09-27 12:00:00'));
    }

    public function testDoesNotSendWhenBoardIsCurrent(): void
    {
        $this->assertFalse($this->service->shouldSendConfig('2026-09-27 12:00:00', '2026-09-27 12:00:00'));
    }

    public function testDoesNotSendWhenBoardIsNewer(): void
    {
        $this->assertFalse($this->service->shouldSendConfig('2026-09-27 12:00:00', '2026-09-27 12:00:01'));
    }

    public function testDoesNotSendWhenPortalHasNoVersion(): void
    {
        $this->assertFalse($this->service->shouldSendConfig(null, '2026-09-27 12:00:00'));
    }
}
