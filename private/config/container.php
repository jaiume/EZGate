<?php

declare(strict_types=1);

use App\DAO\BoardDAO;
use App\DAO\FirmwareVersionDAO;
use App\DAO\ManagedPdo;
use App\DAO\OpenCommandDAO;
use App\DAO\SiteDAO;
use App\DAO\WifiNetworkDAO;
use App\Services\ConfigService;
use App\Twig\UtcTimeExtension;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;

$builder = new ContainerBuilder();

$builder->addDefinitions([
    ManagedPdo::class => function () {
        $host = (string) ConfigService::get('database.host');
        $name = (string) ConfigService::get('database.name');
        $charset = (string) ConfigService::get('database.charset', 'utf8mb4');
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);

        return new ManagedPdo(
            $dsn,
            (string) ConfigService::get('database.user'),
            (string) ConfigService::get('database.pass'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    },

    SiteDAO::class => function (ContainerInterface $container) {
        return new SiteDAO($container->get(ManagedPdo::class));
    },
    WifiNetworkDAO::class => function (ContainerInterface $container) {
        return new WifiNetworkDAO($container->get(ManagedPdo::class));
    },
    BoardDAO::class => function (ContainerInterface $container) {
        return new BoardDAO($container->get(ManagedPdo::class));
    },
    OpenCommandDAO::class => function (ContainerInterface $container) {
        return new OpenCommandDAO($container->get(ManagedPdo::class));
    },
    FirmwareVersionDAO::class => function (ContainerInterface $container) {
        return new FirmwareVersionDAO($container->get(ManagedPdo::class));
    },

    Twig::class => function () {
        $twig = Twig::create(BASE_DIR . '/templates', [
            'cache' => BASE_DIR . '/var/cache/twig',
            'auto_reload' => true,
        ]);
        $twig->getEnvironment()->addExtension(new UtcTimeExtension());
        $twig->getEnvironment()->addGlobal('app_name', ConfigService::get('app.name', 'EZGate'));
        return $twig;
    },
]);

return $builder->build();
