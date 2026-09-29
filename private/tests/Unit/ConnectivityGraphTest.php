<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ConnectivityGraph;
use PHPUnit\Framework\TestCase;

final class ConnectivityGraphTest extends TestCase
{
    public function testWifiBoardLinksThroughSsidToPortal(): void
    {
        $graph = ConnectivityGraph::forSite('Demo', [
            [
                'id' => 1,
                'hardware_id' => 'AAA',
                'friendly_name' => 'Alpha',
                'online' => true,
                'last_wifi_ssid' => 'HomeNet',
                'last_connectivity_json' => json_encode([
                    'current' => ['type' => 'wifi', 'ssid' => 'HomeNet', 'rssi' => -40],
                ]),
            ],
            [
                'id' => 2,
                'hardware_id' => 'BBB',
                'friendly_name' => 'Beta',
                'online' => false,
                'last_connectivity_json' => '{}',
            ],
        ]);

        $ids = array_column($graph['nodes'], 'id');
        $this->assertContains('portal', $ids);
        $this->assertContains('board:1', $ids);
        $this->assertContains('board:2', $ids);
        $this->assertContains('wifi:HomeNet', $ids);
        $this->assertTrue(self::hasEdge($graph['edges'], 'board:1', 'wifi:HomeNet', 'wifi'));
        $this->assertTrue(self::hasEdge($graph['edges'], 'wifi:HomeNet', 'portal', 'uplink'));
        $betaEdges = array_filter(
            $graph['edges'],
            static fn (array $edge): bool => $edge['from'] === 'board:2'
        );
        $this->assertCount(0, $betaEdges);
    }

    public function testLoraBoardLinksToParent(): void
    {
        $graph = ConnectivityGraph::forSite('Demo', [
            [
                'id' => 1,
                'hardware_id' => 'GW1',
                'friendly_name' => 'Gateway',
                'online' => true,
                'last_connectivity_json' => json_encode([
                    'current' => ['type' => 'wifi', 'ssid' => 'SiteWiFi'],
                ]),
            ],
            [
                'id' => 2,
                'hardware_id' => 'LF1',
                'friendly_name' => 'Leaf',
                'online' => true,
                'last_connectivity_json' => json_encode([
                    'current' => [
                        'type' => 'lora',
                        'parent' => 'GW1',
                        'gateway' => 'GW1',
                        'parent_rssi' => -41,
                    ],
                ]),
            ],
        ]);

        $this->assertTrue(self::hasEdge($graph['edges'], 'board:2', 'board:1', 'lora'));
        $lora = null;
        foreach ($graph['edges'] as $edge) {
            if (($edge['from'] ?? '') === 'board:2' && ($edge['kind'] ?? '') === 'lora') {
                $lora = $edge;
                break;
            }
        }
        $this->assertNotNull($lora);
        $this->assertSame(-41, $lora['rssi']);
    }

    /**
     * @param list<array<string, mixed>> $edges
     */
    private static function hasEdge(array $edges, string $from, string $to, string $kind): bool
    {
        foreach ($edges as $edge) {
            if (($edge['from'] ?? '') === $from && ($edge['to'] ?? '') === $to && ($edge['kind'] ?? '') === $kind) {
                return true;
            }
        }

        return false;
    }
}
