<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ConfigService;

class AppLog
{
    public static function info(string $message): void
    {
        $path = BASE_DIR . '/' . ltrim((string) ConfigService::get('logging.path', 'var/logs/app.log'), '/');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        error_log('[' . date('c') . '] ' . $message . PHP_EOL, 3, $path);
    }
}
