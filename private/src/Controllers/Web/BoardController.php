<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\BoardService;
use App\Support\JsonResponder;
use App\Support\PulseDuration;
use App\Support\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class BoardController
{
    public function __construct(
        private Twig $twig,
        private BoardService $boardService
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
        $result = $this->boardService->addToInventory((string) ($body['hardware_id'] ?? ''));
        $returnTo = $this->safeReturn((string) ($body['return_to'] ?? ''), '/');
        if (!$result['success']) {
            $sep = str_contains($returnTo, '?') ? '&' : '?';
            return Redirector::to($returnTo . $sep . 'error=' . urlencode($result['message']));
        }

        if (str_starts_with($returnTo, '/usb')) {
            return Redirector::to('/usb?notice=' . urlencode($result['message']));
        }
        if (str_starts_with($returnTo, '/inventory')) {
            return Redirector::to('/inventory?open=' . $result['data']['id'] . '&notice=' . urlencode($result['message']));
        }

        return Redirector::to('/boards/' . $result['data']['id']);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->boardService->getBoard((int) $args['id']);
        if (!$result['success']) {
            return Redirector::to('/?error=' . urlencode($result['message']));
        }

        return $this->twig->render($response, 'boards/edit.twig', [
            'board' => $result['data']['board'],
            'sites' => $result['data']['sites'],
            'notice' => $request->getQueryParams()['notice'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $siteRaw = trim((string) ($body['site_id'] ?? ''));
        $siteId = $siteRaw === '' ? null : (int) $siteRaw;
        $pulse = PulseDuration::fromForm($body);
        if (!$pulse['ok']) {
            return Redirector::to('/boards/' . $args['id'] . '?error=' . urlencode($pulse['message']));
        }
        $result = $this->boardService->updateBoard(
            (int) $args['id'],
            (string) ($body['friendly_name'] ?? ''),
            $siteId,
            $pulse['value']
        );

        $path = '/boards/' . $args['id'];
        if (!$result['success']) {
            return Redirector::to($path . '?error=' . urlencode($result['message']));
        }

        return Redirector::to($path . '?notice=' . urlencode($result['message']));
    }

    public function trigger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $result = $this->boardService->testTrigger($id);
        $body = (array) $request->getParsedBody();
        $returnTo = $this->safeReturn((string) ($body['return_to'] ?? ''), '/');
        if (!$result['success']) {
            $sep = str_contains($returnTo, '?') ? '&' : '?';
            return Redirector::to($returnTo . $sep . 'error=' . urlencode($result['message']));
        }

        $sep = str_contains($returnTo, '?') ? '&' : '?';
        return Redirector::to($returnTo . $sep . 'open=' . $id . '&notice=' . urlencode($result['message']));
    }

    public function scan(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $result = $this->boardService->requestWifiScan($id);
        $body = (array) $request->getParsedBody();
        $returnTo = $this->safeReturn((string) ($body['return_to'] ?? ''), '/');
        if (!$result['success']) {
            $sep = str_contains($returnTo, '?') ? '&' : '?';
            return Redirector::to($returnTo . $sep . 'error=' . urlencode($result['message']));
        }

        $sep = str_contains($returnTo, '?') ? '&' : '?';
        return Redirector::to($returnTo . $sep . 'open=' . $id . '&notice=' . urlencode($result['message']));
    }

    public function reboot(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $result = $this->boardService->requestReboot($id);
        $body = (array) $request->getParsedBody();
        $returnTo = $this->safeReturn((string) ($body['return_to'] ?? ''), '/');
        if (!$result['success']) {
            $sep = str_contains($returnTo, '?') ? '&' : '?';
            return Redirector::to($returnTo . $sep . 'error=' . urlencode($result['message']));
        }

        $sep = str_contains($returnTo, '?') ? '&' : '?';
        return Redirector::to($returnTo . $sep . 'open=' . $id . '&notice=' . urlencode($result['message']));
    }

    public function reprovision(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $result = $this->boardService->requestReprovision($id);
        $body = (array) $request->getParsedBody();
        $returnTo = $this->safeReturn((string) ($body['return_to'] ?? ''), '/');
        if (!$result['success']) {
            $sep = str_contains($returnTo, '?') ? '&' : '?';
            return Redirector::to($returnTo . $sep . 'error=' . urlencode($result['message']));
        }

        $sep = str_contains($returnTo, '?') ? '&' : '?';
        return Redirector::to($returnTo . $sep . 'open=' . $id . '&notice=' . urlencode($result['message']));
    }

    public function loraOnly(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        $on = !isset($body['lora_only']) || $body['lora_only'] === '1' || $body['lora_only'] === 'true' || $body['lora_only'] === 1;
        if (isset($body['lora_only']) && ($body['lora_only'] === '0' || $body['lora_only'] === 0 || $body['lora_only'] === 'false')) {
            $on = false;
        }
        $result = $this->boardService->setLoraOnly($id, $on);
        $returnTo = $this->safeReturn((string) ($body['return_to'] ?? ''), '/');
        $sep = str_contains($returnTo, '?') ? '&' : '?';
        if (!$result['success']) {
            return Redirector::to($returnTo . $sep . 'error=' . urlencode($result['message']));
        }

        return Redirector::to($returnTo . $sep . 'open=' . $id . '&notice=' . urlencode($result['message']));
    }

    public function loraCredential(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return JsonResponder::fromService($this->boardService->loraCredentialForUsb((int) $args['id']));
    }

    public function usbConfig(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return JsonResponder::fromService($this->boardService->usbConfigForBoard((int) $args['id']));
    }

    public function wipe(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $result = $this->boardService->wipeBoard($id);
        return $this->inventoryActionResponse($request, $id, $result);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $result = $this->boardService->deleteFromInventory($id);
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return JsonResponder::fromService($result);
        }

        $body = (array) $request->getParsedBody();
        $returnTo = $this->safeReturn((string) ($body['return_to'] ?? ''), '/inventory');
        $sep = str_contains($returnTo, '?') ? '&' : '?';
        if (!$result['success']) {
            return Redirector::to($returnTo . $sep . 'error=' . urlencode($result['message']));
        }

        return Redirector::to($returnTo . $sep . 'notice=' . urlencode($result['message']));
    }

    public function move(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $siteRaw = trim((string) ($body['site_id'] ?? ''));
        $siteId = $siteRaw === '' ? null : (int) $siteRaw;
        $id = (int) $args['id'];
        $result = $this->boardService->moveToSite($id, $siteId);
        if ($result['success'] && $siteId !== null) {
            if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
                return JsonResponder::fromService($result);
            }
            return Redirector::to('/?open=' . $id . '&notice=' . urlencode($result['message']));
        }
        return $this->inventoryActionResponse($request, $id, $result);
    }

    public function usbLoad(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $id = (int) $args['id'];
        $result = $this->boardService->recordUsbFirmwareLoad($id, (int) ($body['firmware_id'] ?? 0));
        return $this->inventoryActionResponse($request, $id, $result);
    }

    private function inventoryActionResponse(
        ServerRequestInterface $request,
        int $id,
        array $result
    ): ResponseInterface {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return JsonResponder::fromService($result);
        }

        $body = (array) $request->getParsedBody();
        $returnTo = $this->safeReturn((string) ($body['return_to'] ?? ''), '/usb');
        $sep = str_contains($returnTo, '?') ? '&' : '?';
        if (!$result['success']) {
            return Redirector::to($returnTo . $sep . 'error=' . urlencode($result['message']));
        }

        return Redirector::to($returnTo . $sep . 'open=' . $id . '&notice=' . urlencode($result['message']));
    }

    private function safeReturn(string $path, string $default): string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $default;
        }
        return $path;
    }
}
