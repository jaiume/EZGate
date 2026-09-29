<?php

declare(strict_types=1);

namespace App\Services;

class AuthService
{
    public function credentialsMatch(string $username, string $password): bool
    {
        $expectedUser = (string) ConfigService::get('auth.username', '');
        $expectedPass = (string) ConfigService::get('auth.password', '');

        return hash_equals($expectedUser, $username)
            && hash_equals($expectedPass, $password);
    }

    public function apiKeyMatches(?string $apiKey): bool
    {
        $expected = (string) ConfigService::get('api.api_key', '');
        if ($expected === '' || $apiKey === null || $apiKey === '') {
            return false;
        }

        return hash_equals($expected, $apiKey);
    }

    public function login(): void
    {
        $_SESSION['ezgate_admin'] = true;
        session_regenerate_id(true);
    }

    public function logout(): void
    {
        unset($_SESSION['ezgate_admin']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['ezgate_admin']);
    }
}
