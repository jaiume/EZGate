<?php

declare(strict_types=1);

namespace App\Support;

class WifiScan
{
    /**
     * Keep one row per SSID, using the strongest RSSI.
     *
     * @param list<array<string, mixed>> $networks
     * @return list<array<string, mixed>>
     */
    public static function uniqueBySsid(array $networks): array
    {
        $best = [];
        foreach ($networks as $network) {
            if (!is_array($network)) {
                continue;
            }
            $ssid = WifiSsid::from($network['ssid'] ?? '');
            if ($ssid === '') {
                continue;
            }
            $network['ssid'] = $ssid;
            $rssi = isset($network['rssi']) && $network['rssi'] !== '' && $network['rssi'] !== null
                ? (int) $network['rssi']
                : -127;
            if (!isset($best[$ssid]) || $rssi > (int) ($best[$ssid]['rssi'] ?? -127)) {
                $network['rssi'] = $rssi;
                $best[$ssid] = $network;
            }
        }

        $list = array_values($best);
        usort($list, static function (array $a, array $b): int {
            return ((int) ($b['rssi'] ?? -127)) <=> ((int) ($a['rssi'] ?? -127));
        });

        return $list;
    }
}
