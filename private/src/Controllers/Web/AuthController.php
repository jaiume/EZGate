<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\AuthService;
use App\Support\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class AuthController
{
    public function __construct(
        private Twig $twig,
        private AuthService $authService
    ) {
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->authService->isLoggedIn()) {
            return Redirector::to('/');
        }

        return $this->twig->render($response, 'auth/login.twig', [
            'error' => null,
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($this->authService->credentialsMatch($username, $password)) {
            $this->authService->login();
            return Redirector::to('/');
        }

        return $this->twig->render($response, 'auth/login.twig', [
            'error' => 'Invalid username or password.',
        ])->withStatus(401);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->authService->logout();
        return Redirector::to('/login');
    }
}
