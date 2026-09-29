<?php

declare(strict_types=1);

use App\Middleware\BaseUrlMiddleware;
use App\Middleware\SessionMiddleware;
use App\Services\ConfigService;
use App\Support\HttpErrorHandler;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

define('BASE_DIR', dirname(__DIR__) . '/private');

require BASE_DIR . '/vendor/autoload.php';

$container = require BASE_DIR . '/config/container.php';

AppFactory::setContainer($container);
$app = AppFactory::create();

$routes = require BASE_DIR . '/config/routes.php';
$routes($app);

$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));
$app->add($container->get(SessionMiddleware::class));

$errorMiddleware = $app->addErrorMiddleware(
    (bool) ConfigService::get('app.debug', false),
    true,
    true
);
$errorMiddleware->setDefaultErrorHandler($container->get(HttpErrorHandler::class));

// Slim runs middleware in reverse registration order. Add last so it runs first.
$app->add($container->get(BaseUrlMiddleware::class));

$app->run();
