<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

class JsonResponder
{
    public static function fromService(array $result, int $okStatus = 200): ResponseInterface
    {
        $status = $okStatus;
        if (!$result['success']) {
            $code = $result['error']['code'] ?? '';
            $status = match ($code) {
                'UNAUTHORIZED' => 401,
                'NOT_FOUND' => 404,
                'VALIDATION_ERROR' => 400,
                default => 400,
            };
        }

        return self::write($result, $status);
    }

    public static function write(array $payload, int $status): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write((string) json_encode($payload));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
