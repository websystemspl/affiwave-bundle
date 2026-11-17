<?php

declare(strict_types=1);

use AffiWave\Bundle\Api\AffiWaveClient;
use AffiWave\Bundle\Attribution\ClickTokenResolver;
use AffiWave\Bundle\Command\RetryConversionsCommand;
use AffiWave\Bundle\Command\SyncCouponsCommand;
use AffiWave\Bundle\Config\Settings;
use AffiWave\Bundle\Controller\WebhookController;
use AffiWave\Bundle\Conversion\ConversionReporter;
use AffiWave\Bundle\Coupon\PromotionCodes;
use AffiWave\Bundle\Repository\AffiWaveConversionRepository;
use AffiWave\Bundle\Repository\AffiWaveCouponRepository;
use AffiWave\Bundle\Stripe\DefaultNetAmountResolver;
use AffiWave\Bundle\Stripe\DiscountCodeResolver;
use AffiWave\Bundle\Stripe\NetAmountResolverInterface;
use AffiWave\Bundle\Stripe\StripeGatewayInterface;
use AffiWave\Bundle\Webhook\WebhookSignatureVerifier;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->set(Settings::class)->args([
        '$apiUrl' => param('affiwave.api_url'),
        '$apiKey' => param('affiwave.api_key'),
        '$webhookSecret' => param('affiwave.webhook_secret'),
        '$programId' => param('affiwave.program_id'),
        '$enabled' => param('affiwave.enabled'),
        '$httpTimeout' => param('affiwave.http_timeout'),
        '$couponsEnabled' => param('affiwave.coupons.enabled'),
        '$couponDuration' => param('affiwave.coupons.duration'),
        '$couponDurationMonths' => param('affiwave.coupons.duration_months'),
        '$couponVatRate' => param('affiwave.coupons.vat_rate'),
        '$couponCurrencies' => param('affiwave.coupons.currencies'),
    ]);

    $services->set(AffiWaveClient::class)->args([service('http_client'), service(Settings::class)]);

    $services->set(ClickTokenResolver::class)
        ->args([param('affiwave.click.query_parameter'), param('affiwave.click.cookie_name')])
        ->public();

    $services->set(AffiWaveConversionRepository::class);
    $services->set(AffiWaveCouponRepository::class);

    $services->set(ConversionReporter::class)->public();

    $services->set(PromotionCodes::class)->public();
    $services->set(DiscountCodeResolver::class)
        ->args([service(PromotionCodes::class), service(StripeGatewayInterface::class)->nullOnInvalid(), service('logger')->nullOnInvalid()])
        ->public();

    $services->set(DefaultNetAmountResolver::class);
    $services->alias(NetAmountResolverInterface::class, DefaultNetAmountResolver::class)->public();

    $services->set(WebhookSignatureVerifier::class);
    $services->set(WebhookController::class)->public()->tag('controller.service_arguments');

    $services->set(RetryConversionsCommand::class);
    $services->set(SyncCouponsCommand::class);
};
