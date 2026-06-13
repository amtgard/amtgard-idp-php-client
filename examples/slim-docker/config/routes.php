<?php

declare(strict_types=1);

use Amtgard\IdpClient\Slim\IdpAuthController;
use Amtgard\IdpClient\Slim\SessionMiddleware;
use Amtgard\IdpSlimExample\Controllers\ClientIamController;
use Amtgard\IdpSlimExample\Controllers\HealthController;
use Amtgard\IdpSlimExample\Controllers\HomeController;
use Amtgard\IdpSlimExample\Controllers\MeController;
use Amtgard\IdpSlimExample\Controllers\ResourcesController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $app->get('/health', [HealthController::class, 'health'])->setName('health');
    $app->post('/api/check-authorization', [ResourcesController::class, 'checkAuthorization'])
        ->setName('api.check_authorization');
    $app->get('/api/client-iam/service-format', [ClientIamController::class, 'serviceFormat'])
        ->setName('api.client_iam.service_format');
    $app->post('/api/client-iam/compose-claim', [ClientIamController::class, 'composeClaim'])
        ->setName('api.client_iam.compose_claim');

    $app->group('', function (RouteCollectorProxy $group) {
        $group->get('/', [HomeController::class, 'index'])->setName('home');
        $group->get('/login', [IdpAuthController::class, 'login'])->setName('auth.login');
        $group->get('/oauth/callback', [IdpAuthController::class, 'callback'])->setName('auth.callback');
        $group->get('/logout', [IdpAuthController::class, 'logout'])->setName('auth.logout');
        $group->get('/me', [MeController::class, 'me'])->setName('me');
        $group->get('/resources/userinfo', [ResourcesController::class, 'userinfo'])->setName('resources.userinfo');
        $group->get('/resources/validate', [ResourcesController::class, 'validate'])->setName('resources.validate');
        $group->get('/resources/jwt', [ResourcesController::class, 'jwt'])->setName('resources.jwt');
        $group->post('/refresh', [ResourcesController::class, 'refresh'])->setName('refresh');
    })->add(SessionMiddleware::class);
};
