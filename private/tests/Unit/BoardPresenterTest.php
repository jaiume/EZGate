<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BoardPresenter;
use PHPUnit\Framework\TestCase;

final class BoardPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASE_DIR')) {
            define('BASE_DIR', dirname(__DIR__, 2));
        }
    }

    public function testLoraSignalUsesParentRssi(): void
    {
        $board = BoardPresenter::forList([
            'id' => 2,
            'hardware_id' => 'EC0F0604A7AC',
            'friendly_name' => 'Alpha',
            'site_id' => 1,
            'last_status' => 'lora',
            'last_wifi_rssi' => null,
            'last_heartbeat_at' => '2026-09-29 03:00:00',
            'last_connectivity_json' => json_encode([
                'current' => [
                    'type' => 'lora',
                    'parent' => '60CF0604A7AC',
                    'parent_rssi' => -40,
                    'radio_stats' => [
                        'role' => 'leaf',
                        'parent_rssi' => -40,
                    ],
                ],
            ]),
        ], '2026-09-29 02:00:00');

        $this->assertSame('lora', $board['link_type']);
        $this->assertSame(-40, $board['signal_rssi']);
        $loraHop = null;
        foreach ($board['portal_route'] as $hop) {
            if (($hop['kind'] ?? '') === 'lora') {
                $loraHop = $hop;
                break;
            }
        }
        $this->assertNotNull($loraHop);
        $this->assertSame('-40 dBm', $loraHop['detail']);
    }

    public function testWifiSignalIgnoresLoraParentRssi(): void
    {
        $board = BoardPresenter::forList([
            'id' => 1,
            'hardware_id' => '60CF0604A7AC',
            'friendly_name' => 'Beta',
            'site_id' => 1,
            'last_status' => 'wifi',
            'last_wifi_rssi' => -67,
            'last_heartbeat_at' => '2026-09-29 03:00:00',
            'last_connectivity_json' => json_encode([
                'current' => [
                    'type' => 'wifi',
                    'ssid' => 'WifiVentures',
                    'rssi' => -67,
                ],
            ]),
        ], '2026-09-29 02:00:00');

        $this->assertSame(-67, $board['signal_rssi']);
    }

    public function testOnlineWhenHeartbeatIsAtOrAfterCutoff(): void
    {
        $atCutoff = BoardPresenter::forList($this->statusBoard('2026-09-29 03:00:00'), '2026-09-29 03:00:00');
        $afterCutoff = BoardPresenter::forList($this->statusBoard('2026-09-29 03:00:01'), '2026-09-29 03:00:00');

        $this->assertTrue($atCutoff['online']);
        $this->assertTrue($afterCutoff['online']);
    }

    public function testOfflineWhenHeartbeatIsOlderOrMissing(): void
    {
        $older = BoardPresenter::forList($this->statusBoard('2026-09-29 02:59:59'), '2026-09-29 03:00:00');
        $missing = BoardPresenter::forList($this->statusBoard(null), '2026-09-29 03:00:00');
        $empty = BoardPresenter::forList($this->statusBoard(''), '2026-09-29 03:00:00');

        $this->assertFalse($older['online']);
        $this->assertFalse($missing['online']);
        $this->assertFalse($empty['online']);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusBoard(?string $lastHeartbeatAt): array
    {
        return [
            'id' => 1,
            'hardware_id' => 'EC0F0604A7AC',
            'friendly_name' => 'Alpha',
            'site_id' => 1,
            'last_status' => 'wifi',
            'last_heartbeat_at' => $lastHeartbeatAt,
        ];
    }
}
