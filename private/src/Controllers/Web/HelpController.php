<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class HelpController
{
    public function __construct(private Twig $twig)
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->twig->render($response, 'help/index.twig');
    }
}
