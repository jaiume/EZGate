<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UtilityService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class UtilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearCachedBaseUrl();
    }

    protected function tearDown(): void
    {
        $this->clearCachedBaseUrl();
    }

    public function testGetBaseUrlIsEmptyUntilRemembered(): void
    {
        $this->assertSame('', UtilityService::getBaseUrl());
    }

    public function testRememberBaseUrlUsesTheRequestHost(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://ezgate-dev.stuckbendix.com/api/heartbeat'
        );

        UtilityService::rememberBaseUrl($request);

        $this->assertSame('https://ezgate-dev.stuckbendix.com/', UtilityService::getBaseUrl());
    }

    public function testRememberBaseUrlKeepsNonDefaultPort(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost:8080/login'
        );

        UtilityService::rememberBaseUrl($request);

        $this->assertSame('http://localhost:8080/', UtilityService::getBaseUrl());
    }

    private function clearCachedBaseUrl(): void
    {
        $property = new \ReflectionProperty(UtilityService::class, 'cachedBaseUrl');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
