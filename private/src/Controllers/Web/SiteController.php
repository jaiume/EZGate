<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\SiteService;
use App\Support\PulseDuration;
use App\Support\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class SiteController
{
    public function __construct(
        private Twig $twig,
        private SiteService $siteService
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getUri()->getQuery();
        return Redirector::to('/' . ($query !== '' ? '?' . $query : ''));
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $result = $this->siteService->createSite((string) ($body['name'] ?? ''));
        if (!$result['success']) {
            return Redirector::to('/?error=' . urlencode($result['message']));
        }

        return Redirector::to('/?notice=' . urlencode($result['message']));
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->siteService->getSite((int) $args['id']);
        if (!$result['success']) {
            return Redirector::to('/?error=' . urlencode($result['message']));
        }

        return $this->twig->render($response, 'sites/edit.twig', [
            'site' => $result['data']['site'],
            'wifi_networks' => $result['data']['wifi_networks'],
            'notice' => $request->getQueryParams()['notice'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $pulse = PulseDuration::fromForm($body);
        $path = '/sites/' . $args['id'];
        if (!$pulse['ok']) {
            return Redirector::to($path . '?error=' . urlencode($pulse['message']));
        }
        $result = $this->siteService->updateSite((int) $args['id'], (string) ($body['name'] ?? ''), $pulse['value']);
        if (!$result['success']) {
            return Redirector::to($path . '?error=' . urlencode($result['message']));
        }

        return Redirector::to($path . '?notice=' . urlencode($result['message']));
    }

    public function addWifi(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $result = $this->siteService->addWifi(
            (int) $args['id'],
            (string) ($body['ssid'] ?? ''),
            (string) ($body['password'] ?? ''),
            (string) ($body['security'] ?? '')
        );
        $path = $this->safeReturn((string) ($body['return_to'] ?? ''), '/sites/' . $args['id']);
        if (!$result['success']) {
            return Redirector::to($path . (str_contains($path, '?') ? '&' : '?') . 'error=' . urlencode($result['message']));
        }

        return Redirector::to($path . (str_contains($path, '?') ? '&' : '?') . 'notice=' . urlencode($result['message']));
    }

    public function deleteWifi(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->siteService->deleteWifi((int) $args['id'], (int) $args['wifiId']);
        $path = '/sites/' . $args['id'];
        if (!$result['success']) {
            return Redirector::to($path . '?error=' . urlencode($result['message']));
        }

        return Redirector::to($path . '?notice=' . urlencode($result['message']));
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->siteService->deleteSite((int) $args['id']);
        if (!$result['success']) {
            return Redirector::to('/sites/' . $args['id'] . '?error=' . urlencode($result['message']));
        }

        return Redirector::to('/?notice=' . urlencode($result['message']));
    }

    private function safeReturn(string $path, string $default): string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $default;
        }
        return $path;
    }
}
