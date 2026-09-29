<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\BoardService;
use App\Support\JsonResponder;
use App\Support\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class UsbController
{
    public function __construct(
        private Twig $twig,
        private BoardService $boardService
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->boardService->usbPage();

        return $this->twig->render($response, 'usb/index.twig', [
            'firmware_versions' => $result['data']['firmware_versions'] ?? [],
            'notice' => $request->getQueryParams()['notice'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    public function identify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $result = $this->boardService->identifyUsb((string) ($body['hardware_id'] ?? ''));
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return JsonResponder::fromService($result);
        }
        if (!$result['success']) {
            return Redirector::to('/usb?error=' . urlencode($result['message']));
        }

        return Redirector::to('/usb?notice=' . urlencode($result['message']));
    }
}
