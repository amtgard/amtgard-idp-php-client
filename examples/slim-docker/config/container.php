<?php

declare(strict_types=1);

use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Config\IdpClientFactory;
use Amtgard\IdpClient\Exception\IdpConfigurationException;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\IdpClient\Slim\IdpAuthController;
use Amtgard\IdpSlimExample\Controllers\ClientIamController;
use Amtgard\IdpSlimExample\Controllers\HealthController;
use Amtgard\IdpSlimExample\Controllers\HomeController;
use Amtgard\IdpSlimExample\Controllers\MeController;
use Amtgard\IdpSlimExample\Controllers\ResourcesController;
use Psr\Container\ContainerInterface;
use Slim\App;

return [
    App::class => function (ContainerInterface $container) {
        $app = \DI\Bridge\Slim\Bridge::create($container);
        (require dirname(__DIR__) . '/config/routes.php')($app);

        return $app;
    },

    IdpClient::class => fn () => IdpClientFactory::fromEnvVars(),

    SessionAuthStore::class => fn () => new SessionAuthStore(),

    IdpAuthController::class => function (ContainerInterface $container) {
        $app = $container->get(App::class);

        return new IdpAuthController(
            $container->get(IdpClient::class),
            $container->get(SessionAuthStore::class),
            postLoginRoute: 'home',
            postLogoutRoute: 'home',
            routeParser: $app->getRouteCollector()->getRouteParser(),
        );
    },

    HealthController::class => fn () => new HealthController(),
    HomeController::class => function (ContainerInterface $container) {
        $clientIamConfigured = false;
        try {
            $container->get(IdpClient::class)->clientIam();
            $clientIamConfigured = true;
        } catch (IdpConfigurationException) {
        }

        return new HomeController(
            $container->get(SessionAuthStore::class),
            $clientIamConfigured,
        );
    },
    MeController::class => fn (ContainerInterface $container) => new MeController(
        $container->get(SessionAuthStore::class),
    ),
    ResourcesController::class => fn (ContainerInterface $container) => new ResourcesController(
        $container->get(IdpClient::class),
        $container->get(SessionAuthStore::class),
    ),

    ClientIamController::class => function (ContainerInterface $container) {
        $idp = $container->get(IdpClient::class);
        $clientIam = null;
        try {
            $clientIam = $idp->clientIam();
        } catch (IdpConfigurationException) {
        }

        return new ClientIamController($idp, $clientIam);
    },
];
