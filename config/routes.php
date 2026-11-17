<?php

declare(strict_types=1);

use AffiWave\Bundle\Controller\WebhookController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('affiwave_webhook', '/affiwave/webhook')
        ->controller(WebhookController::class)
        ->methods(['POST']);
};
