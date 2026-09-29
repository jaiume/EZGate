<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthService;
use App\Services\ConfigService;
use PHPUnit\Framework\TestCase;

final class TriggerTokenTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASE_DIR')) {
            define('BASE_DIR', dirname(__DIR__, 2));
        }
        ConfigService::reset();
    }

    public function testRejectsMissingAndWrongTokens(): void
    {
        $auth = new AuthService();
        $expected = (string) ConfigService::get('api.api_key', '');
        $this->assertNotSame('', $expected);
        $this->assertFalse($auth->apiKeyMatches(null));
        $this->assertFalse($auth->apiKeyMatches(''));
        $this->assertFalse($auth->apiKeyMatches($expected . 'x'));
        $this->assertTrue($auth->apiKeyMatches($expected));
    }
}
