#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\DAO\FirmwareVersionDAO;
use App\Services\FirmwareService;

$container = require BASE_DIR . '/config/container.php';
/** @var FirmwareService $firmware */
$firmware = $container->get(FirmwareService::class);
/** @var FirmwareVersionDAO $versions */
$versions = $container->get(FirmwareVersionDAO::class);

$projectRoot = dirname(BASE_DIR);
$buildDir = $argv[1] ?? $projectRoot . '/firmware/.pio/build/heltec_wireless_stick_v3';

$app = $buildDir . '/firmware.bin';
$bootloader = $buildDir . '/bootloader.bin';
$partitions = $buildDir . '/partitions.bin';
$bootApp0 = $buildDir . '/boot_app0.bin';

if (!is_file($bootApp0)) {
    $patterns = [
        $projectRoot . '/firmware/.platformio/packages/framework-arduinoespressif32*/tools/partitions/boot_app0.bin',
    ];
    $home = (string) (getenv('HOME') ?: '');
    if ($home !== '') {
        $patterns[] = $home . '/.platformio/packages/framework-arduinoespressif32*/tools/partitions/boot_app0.bin';
    }
    foreach ($patterns as $pattern) {
        $matches = glob($pattern);
        if (is_array($matches) && $matches !== []) {
            $bootApp0 = $matches[0];
            break;
        }
    }
}

$result = $firmware->publishFromBuild($app, $bootloader, $partitions, $bootApp0);
echo $result['message'] . PHP_EOL;
if (!$result['success']) {
    exit(1);
}

foreach (['0.2.0', '0.3.1'] as $stub) {
    $row = $versions->findByVersion($stub);
    if ($row === null) {
        continue;
    }
    $deleted = $firmware->deleteVersion((int) $row['id']);
    echo $deleted['message'] . PHP_EOL;
}

echo 'Published ' . ($result['data']['version'] ?? '') . ' (' . ($result['data']['size'] ?? 0) . ' bytes).' . PHP_EOL;
