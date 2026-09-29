<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Http\Message\ServerRequestInterface;

class UtilityService
{
    private static ?string $cachedBaseUrl = null;

    public static function rememberBaseUrl(ServerRequestInterface $request): void
    {
        $uri = $request->getUri();
        $root = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();
        if ($port !== null && !in_array($port, [80, 443], true)) {
            $root .= ':' . $port;
        }

        $basePath = '';
        if (method_exists($uri, 'getBasePath')) {
            $basePath = rtrim((string) $uri->getBasePath(), '/');
        }

        self::$cachedBaseUrl = $basePath === ''
            ? $root . '/'
            : $root . $basePath . '/';
    }

    public static function getBaseUrl(): string
    {
        return self::$cachedBaseUrl ?? '';
    }

    public static function getHttpTimeouts(string $key, float $default): array
    {
        $timeout = (float) ConfigService::get($key, $default);
        if ($timeout <= 0) {
            $timeout = $default;
        }

        return [
            'timeout' => $timeout,
            'connect_timeout' => $timeout / 2,
        ];
    }
}
