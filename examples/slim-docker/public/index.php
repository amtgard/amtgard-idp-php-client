<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\App;

$definitions = require dirname(__DIR__) . '/config/bootstrap.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions($definitions);
$container = $containerBuilder->build();

/** @var App $app */
$app = $container->get(App::class);
$app->run();
