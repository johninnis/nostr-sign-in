<?php

declare(strict_types=1);

use Innis\Nostr\SignIn\Tests\Integration\Support\ProbeController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import(dirname(__DIR__, 3).'/src/Presentation/Web/Controller/', 'attribute')
        ->prefix('/action');

    $routes->add('probe_identity', '/action/probe/identity')
        ->controller([ProbeController::class, 'identity'])
        ->methods(['GET']);

    $routes->add('probe_required', '/action/probe/required')
        ->controller([ProbeController::class, 'required'])
        ->methods(['GET']);

    $routes->add('probe_admin', '/action/probe/admin')
        ->controller([ProbeController::class, 'admin'])
        ->methods(['GET']);

    $routes->add('probe_page', '/probe/page')
        ->controller([ProbeController::class, 'page'])
        ->methods(['GET']);
};
