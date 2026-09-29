<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\FirmwareService;
use App\Support\FleetSettings;
use App\Support\FirmwareDownloadSlice;
use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

class FirmwareController
{
    public function __construct(private FirmwareService $firmwareService)
    {
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $hardwareId = (string) ($request->getQueryParams()['hardware_id'] ?? '');
        $result = $this->firmwareService->fileForBoardDownload($hardwareId);
        if (!$result['success']) {
            return JsonResponder::fromService($result);
        }

        $data = $result['data'];
        $total = (int) $data['size'];
        $slice = FirmwareDownloadSlice::fromRequest(
            $request,
            $total,
            FleetSettings::otaChunkBytes()
        );
        $filename = str_replace(['"', "\r", "\n"], '', (string) $data['filename']);

        if ($slice->unsatisfiable()) {
            return $response
                ->withHeader('Content-Range', $slice->contentRange())
                ->withHeader('Accept-Ranges', 'bytes')
                ->withStatus(416);
        }

        if ($slice->partial) {
            $handle = fopen($data['path'], 'rb');
            if ($handle === false) {
                return JsonResponder::fromService([
                    'success' => false,
                    'message' => 'Firmware file is missing.',
                    'data' => null,
                    'error' => ['code' => 'NOT_FOUND', 'details' => []],
                ]);
            }
            if ($slice->offset > 0 && fseek($handle, $slice->offset) !== 0) {
                fclose($handle);
                return $response
                    ->withHeader('Content-Range', 'bytes */' . $total)
                    ->withHeader('Accept-Ranges', 'bytes')
                    ->withStatus(416);
            }
            $bytes = fread($handle, $slice->length);
            fclose($handle);
            if (!is_string($bytes) || strlen($bytes) !== $slice->length) {
                return JsonResponder::fromService([
                    'success' => false,
                    'message' => 'Firmware chunk could not be read.',
                    'data' => null,
                    'error' => ['code' => 'INTERNAL_ERROR', 'details' => []],
                ]);
            }

            $body = fopen('php://temp', 'wb+');
            if ($body === false) {
                return JsonResponder::fromService([
                    'success' => false,
                    'message' => 'Firmware chunk could not be read.',
                    'data' => null,
                    'error' => ['code' => 'INTERNAL_ERROR', 'details' => []],
                ]);
            }
            fwrite($body, $bytes);
            rewind($body);

            return $response
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Length', (string) $slice->length)
                ->withHeader('Content-Range', $slice->contentRange())
                ->withHeader('Accept-Ranges', 'bytes')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('X-Firmware-Version', (string) $data['version'])
                ->withHeader('X-Checksum-SHA256', (string) $data['checksum'])
                ->withBody(new Stream($body));
        }

        $handle = fopen($data['path'], 'rb');
        if ($handle === false) {
            return JsonResponder::fromService([
                'success' => false,
                'message' => 'Firmware file is missing.',
                'data' => null,
                'error' => ['code' => 'NOT_FOUND', 'details' => []],
            ]);
        }

        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', (string) $data['size'])
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('X-Firmware-Version', (string) $data['version'])
            ->withHeader('X-Checksum-SHA256', (string) $data['checksum'])
            ->withBody(new Stream($handle));
    }
}
