<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LoraChunk;
use PHPUnit\Framework\TestCase;

final class LoraChunkTest extends TestCase
{
    public function testCrc32IeeeVector(): void
    {
        $this->assertSame(0xCBF43926, LoraChunk::crc32('123456789'));
    }

    public function testHeaderFitsLoRaPlaintext(): void
    {
        $header = pack('C2V2vV', LoraChunk::KIND_CONFIG, 0, 10, 0, 4, 1);
        $this->assertSame(16, strlen($header));
        $this->assertSame(16, LoraChunk::HEADER_BYTES);
        $this->assertSame(LoraChunk::MAX_PLAIN - LoraChunk::HEADER_BYTES, LoraChunk::MAX_DATA);
        $this->assertLessThanOrEqual(LoraChunk::MAX_DATA, LoraChunk::maxData(192));
        $this->assertSame(LoraChunk::MAX_DATA, LoraChunk::maxData(500));
    }

    public function testSplitAndJoinConfigJson(): void
    {
        $json = '{"version":"2026-09-29 04:20:35","lora_only":false,"pulse_duration_ms":500,'
            . '"friendly_name":"TestBoard Alpha","lora_site_key_wrapped":"'
            . str_repeat('A', 88) . '","wifi_networks":[{"ssid":"WifiVentures","password":"'
            . str_repeat('x', 64) . '","security":"wpa2"}]}';
        $this->assertGreaterThan(180, strlen($json));

        $frames = LoraChunk::split($json, LoraChunk::KIND_CONFIG, 96);
        $this->assertGreaterThan(1, count($frames));
        $this->assertSame(LoraChunk::FLAG_LAST, $frames[array_key_last($frames)]['flags']);
        foreach ($frames as $frame) {
            $this->assertLessThanOrEqual(LoraChunk::MAX_PLAIN, strlen($frame['frame']));
            $this->assertSame(strlen($json), $frame['total']);
        }

        $joined = LoraChunk::join(array_column($frames, 'frame'));
        $this->assertSame($json, $joined);
    }

    public function testSplitAndJoinUplinkScanJson(): void
    {
        $json = '{"hardware_id":"EC0F0604A7AC","status":"lora","scanned":true,"connectivity":{"other":['
            . '{"type":"wifi","ssid":"WifiVentures","rssi":-64,"security":"wpa2"},'
            . '{"type":"wifi","ssid":"' . str_repeat('Net', 20) . '","rssi":-82,"security":"wpa2"}'
            . ']}}';
        $this->assertGreaterThan(180, strlen($json));
        $frames = LoraChunk::split($json, LoraChunk::KIND_UPLINK, 96);
        $this->assertGreaterThan(1, count($frames));
        $this->assertSame($json, LoraChunk::join(array_column($frames, 'frame')));
    }

    public function testRejectsCorruptPayload(): void
    {
        $frames = LoraChunk::split('hello-config', LoraChunk::KIND_CONFIG, 8);
        $this->assertNotSame([], $frames);
        $bad = $frames;
        $bad[0]['frame'] = substr($bad[0]['frame'], 0, -1) . 'Z';
        $this->assertNull(LoraChunk::join(array_column($bad, 'frame')));
    }
}
