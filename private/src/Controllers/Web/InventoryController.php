<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\BoardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class InventoryController
{
    public function __construct(
        private Twig $twig,
        private BoardService $boardService
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->boardService->listInventory();

        return $this->twig->render($response, 'inventory/index.twig', [
            'boards' => $result['data']['boards'] ?? [],
            'sites' => $result['data']['sites'] ?? [],
            'open_id' => (int) ($request->getQueryParams()['open'] ?? 0),
            'notice' => $request->getQueryParams()['notice'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }
}
