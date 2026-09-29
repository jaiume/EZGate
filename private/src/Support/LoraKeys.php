<?php

declare(strict_types=1);

namespace App\Support;

class LoraKeys
{
    public static function randomHex(int $bytes = 16): string
    {
        return strtoupper(bin2hex(random_bytes($bytes)));
    }

    public static function wrapSiteKey(string $boardCredentialHex, string $siteKeyHex): ?string
    {
        $key = self::binaryKey($boardCredentialHex);
        $plain = self::binaryKey($siteKeyHex);
        if ($key === null || $plain === null) {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || $tag === '') {
            return null;
        }

        return strtoupper(bin2hex($iv . $cipher . $tag));
    }

    public static function unwrapSiteKey(string $boardCredentialHex, string $wrappedHex): ?string
    {
        $key = self::binaryKey($boardCredentialHex);
        $blob = @hex2bin(strtoupper(trim($wrappedHex)));
        if ($key === null || $blob === false || strlen($blob) !== 44) {
            return null;
        }
        $iv = substr($blob, 0, 12);
        $cipher = substr($blob, 12, 16);
        $tag = substr($blob, 28, 16);
        $plain = openssl_decrypt($cipher, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false || strlen($plain) !== 16) {
            return null;
        }

        return strtoupper(bin2hex($plain));
    }

    public static function binaryKey(string $hex): ?string
    {
        $hex = strtoupper(trim($hex));
        if (!preg_match('/^[0-9A-F]{32}$/', $hex)) {
            return null;
        }
        $raw = hex2bin($hex);

        return $raw !== false && strlen($raw) === 16 ? $raw : null;
    }
}
