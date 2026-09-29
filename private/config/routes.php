<?php

declare(strict_types=1);

use App\Controllers\Api\FirmwareController as FirmwareApiController;
use App\Controllers\Api\HeartbeatController;
use App\Controllers\Api\SetupController;
use App\Controllers\Api\StatusController;
use App\Controllers\Api\TriggerController;
use App\Controllers\Web\AuthController;
use App\Controllers\Web\BoardController;
use App\Controllers\Web\DashboardController;
use App\Controllers\Web\FirmwareController;
use App\Controllers\Web\HelpController;
use App\Controllers\Web\InventoryController;
use App\Controllers\Web\SiteController;
use App\Controllers\Web\UsbController;
use App\Middleware\CsrfMiddleware;
use App\Middleware\WebAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $app->get('/login', [AuthController::class, 'showLogin'])->add(CsrfMiddleware::class);
    $app->post('/login', [AuthController::class, 'login'])->add(CsrfMiddleware::class);
    $app->post('/logout', [AuthController::class, 'logout'])->add(CsrfMiddleware::class);

    $app->group('', function (RouteCollectorProxy $web): void {
        $web->get('/', [DashboardController::class, 'index']);
        $web->get('/dashboard/live', [DashboardController::class, 'live']);
        $web->get('/inventory', [InventoryController::class, 'index']);
        $web->post('/inventory/usb', [UsbController::class, 'identify']);
        $web->get('/usb', [UsbController::class, 'index']);
        $web->post('/usb/identify', [UsbController::class, 'identify']);
        $web->get('/boards', [BoardController::class, 'index']);
        $web->post('/boards', [BoardController::class, 'create']);
        $web->get('/boards/{id:[0-9]+}', [BoardController::class, 'edit']);
        $web->post('/boards/{id:[0-9]+}', [BoardController::class, 'update']);
        $web->post('/boards/{id:[0-9]+}/trigger', [BoardController::class, 'trigger']);
        $web->post('/boards/{id:[0-9]+}/scan', [BoardController::class, 'scan']);
        $web->post('/boards/{id:[0-9]+}/reboot', [BoardController::class, 'reboot']);
        $web->post('/boards/{id:[0-9]+}/reprovision', [BoardController::class, 'reprovision']);
        $web->post('/boards/{id:[0-9]+}/wipe', [BoardController::class, 'wipe']);
        $web->post('/boards/{id:[0-9]+}/delete', [BoardController::class, 'delete']);
        $web->post('/boards/{id:[0-9]+}/move', [BoardController::class, 'move']);
        $web->post('/boards/{id:[0-9]+}/usb-load', [BoardController::class, 'usbLoad']);
        $web->post('/boards/{id:[0-9]+}/lora-only', [BoardController::class, 'loraOnly']);
        $web->get('/boards/{id:[0-9]+}/lora-credential', [BoardController::class, 'loraCredential']);
        $web->get('/boards/{id:[0-9]+}/usb-config', [BoardController::class, 'usbConfig']);

        $web->get('/sites', [SiteController::class, 'index']);
        $web->post('/sites', [SiteController::class, 'create']);
        $web->get('/sites/{id:[0-9]+}', [SiteController::class, 'edit']);
        $web->post('/sites/{id:[0-9]+}', [SiteController::class, 'update']);
        $web->post('/sites/{id:[0-9]+}/wifi', [SiteController::class, 'addWifi']);
        $web->post('/sites/{id:[0-9]+}/wifi/{wifiId:[0-9]+}/delete', [SiteController::class, 'deleteWifi']);
        $web->post('/sites/{id:[0-9]+}/delete', [SiteController::class, 'delete']);

        $web->get('/firmware', [FirmwareController::class, 'index']);
        $web->post('/firmware', [FirmwareController::class, 'create']);
        $web->get('/firmware/{id:[0-9]+}/file', [FirmwareController::class, 'file']);
        $web->get('/firmware/{id:[0-9]+}/usb-manifest', [FirmwareController::class, 'usbManifest']);
        $web->get('/firmware/{id:[0-9]+}/usb/{part:bootloader|partitions|boot_app0}', [FirmwareController::class, 'usbPart']);
        $web->post('/firmware/push', [FirmwareController::class, 'push']);
        $web->post('/firmware/{id:[0-9]+}/push', [FirmwareController::class, 'push']);
        $web->post('/firmware/{id:[0-9]+}/delete', [FirmwareController::class, 'delete']);
        $web->get('/help', [HelpController::class, 'index']);
    })->add(CsrfMiddleware::class)->add(WebAuthMiddleware::class);

    $app->group('/api', function (RouteCollectorProxy $api): void {
        $api->get('/setup/sites', [SetupController::class, 'sites']);
        $api->post('/setup/complete', [SetupController::class, 'complete']);
        $api->post('/heartbeat', [HeartbeatController::class, 'heartbeat']);
        $api->post('/trigger', [TriggerController::class, 'trigger']);
        $api->get('/status', [StatusController::class, 'status']);
        $api->get('/firmware/download', [FirmwareApiController::class, 'download']);
    });
};
