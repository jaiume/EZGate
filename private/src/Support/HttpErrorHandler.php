<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ConfigService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Throwable;

class HttpErrorHandler
{
    public function __construct(private Twig $twig)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        $status = $exception instanceof HttpException ? ($exception->getCode() ?: 500) : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        $errorId = bin2hex(random_bytes(4));
        if ($logErrors) {
            $this->writeLog(sprintf(
                '[%s] %s in %s:%d',
                $errorId,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            ));
        }

        $path = $request->getUri()->getPath();
        $wantsJson = str_starts_with($path, '/api')
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

        $response = new Response();
        if ($wantsJson) {
            $payload = [
                'success' => false,
                'message' => $status === 404 ? 'Not found.' : 'An unexpected error occurred.',
                'data' => null,
                'error' => [
                    'code' => $status === 404 ? 'NOT_FOUND' : 'UNEXPECTED_ERROR',
                    'id' => $errorId,
                    'details' => [],
                ],
            ];
            $response->getBody()->write((string) json_encode($payload));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($status);
        }

        try {
            return $this->twig->render($response, 'error.twig', [
                'status' => $status,
                'error_id' => $errorId,
                'app_name' => ConfigService::get('app.name', 'EZGate'),
            ])->withStatus($status);
        } catch (Throwable) {
            $response->getBody()->write('An unexpected error occurred. Reference ' . $errorId);
            return $response->withStatus($status)->withHeader('Content-Type', 'text/plain');
        }
    }

    private function writeLog(string $error): void
    {
        $path = BASE_DIR . '/' . ltrim((string) ConfigService::get('logging.path', 'var/logs/app.log'), '/');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        error_log('[' . date('c') . '] ' . $error . PHP_EOL, 3, $path);
    }
}
