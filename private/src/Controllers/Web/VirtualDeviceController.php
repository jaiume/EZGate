<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\VirtualDeviceService;
use App\Support\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class VirtualDeviceController
{
    public function __construct(
        private VirtualDeviceService $virtualDeviceService
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Redirector::to('/');
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $boardId = (int) ($body['board_id'] ?? 0);
        $idRaw = trim((string) ($body['id'] ?? ''));
        $id = $idRaw === '' ? null : (int) $idRaw;
        $relays = [];
        $pulses = [];
        $posted = $body['relays'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }
        foreach ($posted as $relay) {
            $number = (int) $relay;
            if ($number > 0) {
                $relays[] = $number;
                $mode = (string) ($body['pulse_mode'][$number] ?? $body['pulse_mode'][(string) $number] ?? 'inherit');
                if ($mode === 'custom') {
                    $raw = $body['pulse_duration_ms'][$number] ?? $body['pulse_duration_ms'][(string) $number] ?? '';
                    $pulses[$number] = is_numeric($raw) ? (int) $raw : null;
                } else {
                    $pulses[$number] = null;
                }
            }
        }
        $readerRaw = trim((string) ($body['wiegand_reader'] ?? ''));
        $reader = $readerRaw === '' ? null : (int) $readerRaw;
        $result = $this->virtualDeviceService->save(
            $id,
            $boardId,
            (string) ($body['friendly_name'] ?? ''),
            $relays,
            $reader,
            $pulses
        );
        $back = $this->back($body, (int) ($result['data']['board_id'] ?? $boardId));
        $sep = str_contains($back, '?') ? '&' : '?';
        if (!$result['success']) {
            return Redirector::to($back . $sep . 'error=' . urlencode($result['message']));
        }

        return Redirector::to($back . $sep . 'notice=' . urlencode($result['message']));
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $result = $this->virtualDeviceService->delete((int) $args['id']);
        $back = $this->back($body, (int) ($result['data']['board_id'] ?? 0));
        $sep = str_contains($back, '?') ? '&' : '?';
        if (!$result['success']) {
            return Redirector::to($back . $sep . 'error=' . urlencode($result['message']));
        }

        return Redirector::to($back . $sep . 'notice=' . urlencode($result['message']));
    }

    public function trigger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $result = $this->virtualDeviceService->trigger((int) $args['id']);
        $boardId = (int) ($result['data']['board_id'] ?? 0);
        $back = $this->back($body, $boardId);
        $params = [];
        if (($back === '/' || $back === '/inventory') && $boardId > 0) {
            $params['open'] = (string) $boardId;
        }
        $params[$result['success'] ? 'notice' : 'error'] = $result['message'];
        $hash = (int) $args['id'] > 0 ? '#device-' . (int) $args['id'] : '';

        return Redirector::to($back . '?' . http_build_query($params) . $hash);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function back(array $body, int $boardId): string
    {
        $returnTo = (string) ($body['return_to'] ?? '');
        $boardPath = $boardId > 0 ? '/boards/' . $boardId : '';
        if ($returnTo === '/' || $returnTo === '/inventory' || ($boardPath !== '' && $returnTo === $boardPath)) {
            return $returnTo;
        }

        return $boardPath !== '' ? $boardPath : '/';
    }
}
