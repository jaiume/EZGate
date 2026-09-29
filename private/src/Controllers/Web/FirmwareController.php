<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\FirmwareService;
use App\Support\JsonResponder;
use App\Support\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;
use Slim\Views\Twig;

class FirmwareController
{
    public function __construct(
        private Twig $twig,
        private FirmwareService $firmwareService
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->firmwareService->listPage();
        return $this->twig->render($response, 'firmware/index.twig', [
            'versions' => $result['data']['versions'] ?? [],
            'fleet_tree' => $result['data']['fleet_tree'] ?? [],
            'notice' => $request->getQueryParams()['notice'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['binary'] ?? null;
        $result = $this->firmwareService->createFromUpload(
            $file instanceof UploadedFileInterface ? $file : null
        );
        if (!$result['success']) {
            return Redirector::to('/firmware?error=' . urlencode($result['message']));
        }

        return Redirector::to('/firmware?notice=' . urlencode($result['message']));
    }

    public function push(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $boardRaw = trim((string) ($body['board_id'] ?? ''));
        $siteRaw = trim((string) ($body['site_id'] ?? ''));
        $firmwareId = (int) ($args['id'] ?? 0);
        if ($firmwareId <= 0) {
            $firmwareId = (int) ($body['firmware_id'] ?? 0);
        }
        $fleet = $this->postedFlag($body['fleet'] ?? null);
        $unassigned = $this->postedFlag($body['unassigned'] ?? null);
        $boardIds = $this->intList($body['board_ids'] ?? null);
        $siteIds = $this->intList($body['site_ids'] ?? null);
        if ($fleet || $unassigned || $boardIds !== [] || $siteIds !== []) {
            $result = $this->firmwareService->pushSelected($firmwareId, $fleet, $siteIds, $boardIds, $unassigned);
        } else {
            $result = $this->firmwareService->push(
                $firmwareId,
                (string) ($body['scope'] ?? ''),
                $boardRaw === '' ? null : (int) $boardRaw,
                $siteRaw === '' ? null : (int) $siteRaw
            );
        }

        $path = $this->safeReturn((string) ($body['return_to'] ?? '/firmware'));
        $sep = str_contains($path, '?') ? '&' : '?';
        if (!$result['success']) {
            return Redirector::to($path . $sep . 'error=' . urlencode($result['message']));
        }

        return Redirector::to($path . $sep . 'notice=' . urlencode($result['message']));
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->firmwareService->deleteVersion((int) ($args['id'] ?? 0));
        if (!$result['success']) {
            return Redirector::to('/firmware?error=' . urlencode($result['message']));
        }

        return Redirector::to('/firmware?notice=' . urlencode($result['message']));
    }

    public function file(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->firmwareService->fileForId((int) $args['id']);
        if (!$result['success']) {
            if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
                return \App\Support\JsonResponder::fromService($result);
            }
            return Redirector::to('/firmware?error=' . urlencode($result['message']));
        }

        $data = $result['data'];
        $handle = fopen($data['path'], 'rb');
        if ($handle === false) {
            return Redirector::to('/firmware?error=' . urlencode('Firmware file is missing.'));
        }

        $filename = str_replace(['"', "\r", "\n"], '', (string) $data['filename']);

        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', (string) $data['size'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('X-Firmware-Version', (string) $data['version'])
            ->withHeader('X-Checksum-SHA256', (string) $data['checksum'])
            ->withBody(new Stream($handle));
    }

    public function usbManifest(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return JsonResponder::fromService($this->firmwareService->usbManifestForId((int) $args['id']));
    }

    public function usbPart(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->firmwareService->usbFileForId((int) $args['id'], (string) ($args['part'] ?? ''));
        if (!$result['success']) {
            if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
                return JsonResponder::fromService($result);
            }
            return Redirector::to('/firmware?error=' . urlencode($result['message']));
        }

        $data = $result['data'];
        $handle = fopen($data['path'], 'rb');
        if ($handle === false) {
            return Redirector::to('/firmware?error=' . urlencode('USB image part is missing.'));
        }

        $filename = str_replace(['"', "\r", "\n"], '', (string) $data['filename']);

        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', (string) $data['size'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('X-Firmware-Version', (string) $data['version'])
            ->withHeader('X-Checksum-SHA256', (string) $data['checksum'])
            ->withBody(new Stream($handle));
    }

    private function safeReturn(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/firmware';
        }
        return $path;
    }

    private function postedFlag(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return false;
        }
        if ($value === true) {
            return true;
        }
        $raw = strtolower(trim((string) $value));
        return $raw !== '0' && $raw !== 'false' && $raw !== 'off';
    }

    private function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }
}
