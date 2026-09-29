<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\SiteService;
use App\Support\FleetSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class DashboardController
{
    public function __construct(
        private Twig $twig,
        private SiteService $siteService
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->siteService->listSites();
        $sites = $result['data']['sites'] ?? [];
        $openId = (int) ($request->getQueryParams()['open'] ?? 0);
        $openSiteId = null;
        if ($openId > 0) {
            foreach ($sites as $site) {
                foreach ($site['boards'] as $board) {
                    if ((int) $board['id'] === $openId) {
                        $openSiteId = (int) $site['id'];
                        break 2;
                    }
                }
            }
        }

        return $this->twig->render($response, 'dashboard/index.twig', [
            'sites' => $sites,
            'open_id' => $openId,
            'open_site_id' => $openSiteId,
            'live_interval_seconds' => FleetSettings::heartbeatIntervalSeconds(),
            'notice' => $request->getQueryParams()['notice'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    public function live(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->siteService->listSites();

        return $this->twig->render($response, 'dashboard/live.twig', [
            'sites' => $result['data']['sites'] ?? [],
            'open_id' => 0,
            'open_site_id' => null,
        ])->withHeader('Cache-Control', 'no-store');
    }
}
