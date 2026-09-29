<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

class Redirector
{
    public static function to(string $path, int $status = 302): ResponseInterface
    {
        $response = new Response();
        return $response->withHeader('Location', $path)->withStatus($status);
    }
}
