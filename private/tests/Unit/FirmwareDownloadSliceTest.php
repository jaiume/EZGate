<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FirmwareDownloadSlice;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class FirmwareDownloadSliceTest extends TestCase
{
    public function testFullFileWhenNoRange(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://ezgate-dev.stuckbendix.com/api/firmware/download?hardware_id=ABC'
        );
        $slice = FirmwareDownloadSlice::fromRequest($request, 969824, 51200);

        $this->assertFalse($slice->partial);
        $this->assertSame(0, $slice->offset);
        $this->assertSame(969824, $slice->length);
    }

    public function testQueryOffsetAndLength(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://ezgate-dev.stuckbendix.com/api/firmware/download?hardware_id=ABC&offset=51200&length=51200'
        );
        $slice = FirmwareDownloadSlice::fromRequest($request, 969824, 51200);

        $this->assertTrue($slice->partial);
        $this->assertSame(51200, $slice->offset);
        $this->assertSame(51200, $slice->length);
        $this->assertSame('bytes 51200-102399/969824', $slice->contentRange());
    }

    public function testLastChunkIsShort(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://ezgate-dev.stuckbendix.com/api/firmware/download?offset=960000&length=51200'
        );
        $slice = FirmwareDownloadSlice::fromRequest($request, 969824, 51200);

        $this->assertSame(960000, $slice->offset);
        $this->assertSame(9824, $slice->length);
    }

    public function testCapsLengthToMaxChunk(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://ezgate-dev.stuckbendix.com/api/firmware/download?offset=0&length=1000000'
        );
        $slice = FirmwareDownloadSlice::fromRequest($request, 969824, 51200);

        $this->assertSame(51200, $slice->length);
    }

    public function testRangeHeader(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://ezgate-dev.stuckbendix.com/api/firmware/download')
            ->withHeader('Range', 'bytes=0-51199');
        $slice = FirmwareDownloadSlice::fromRequest($request, 969824, 51200);

        $this->assertTrue($slice->partial);
        $this->assertSame(0, $slice->offset);
        $this->assertSame(51200, $slice->length);
    }

    public function testQueryWinsOverRangeHeader(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                'https://ezgate-dev.stuckbendix.com/api/firmware/download?offset=100&length=50'
            )
            ->withHeader('Range', 'bytes=0-51199');
        $slice = FirmwareDownloadSlice::fromRequest($request, 969824, 51200);

        $this->assertSame(100, $slice->offset);
        $this->assertSame(50, $slice->length);
    }

    public function testOffsetPastEndIsUnsatisfiable(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://ezgate-dev.stuckbendix.com/api/firmware/download?offset=969824&length=51200'
        );
        $slice = FirmwareDownloadSlice::fromRequest($request, 969824, 51200);

        $this->assertTrue($slice->unsatisfiable());
        $this->assertSame('bytes */969824', $slice->contentRange());
    }
}
