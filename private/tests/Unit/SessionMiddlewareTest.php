<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\SessionMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SessionMiddlewareTest extends TestCase
{
    public function testApiRoutesDoNotStartASession(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://ezgate-dev.stuckbendix.com/api/firmware/download?hardware_id=ABC&offset=0&length=51200'
        );

        $this->assertFalse(SessionMiddleware::shouldStart($request));
    }

    public function testWebRoutesStillStartASession(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://ezgate-dev.stuckbendix.com/firmware'
        );

        $this->assertTrue(SessionMiddleware::shouldStart($request));
    }
}
