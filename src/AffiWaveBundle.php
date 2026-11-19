<?php

declare(strict_types=1);

namespace AffiWave\Bundle;

use AffiWave\Bundle\Attribution\ClickCookieListener;
use AffiWave\Bundle\Config\Settings;
use AffiWave\Bundle\Coupon\StripeCouponSynchronizer;
use AffiWave\Bundle\Repository\AffiWaveCouponRepository;
use AffiWave\Bundle\Stripe\StripeGateway;
use AffiWave\Bundle\Stripe\StripeGatewayInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Client for the AffiWave affiliate platform: click attribution, S2S conversions, partner coupons.
 */
final class AffiWaveBundle extends AbstractBundle
{
    protected string $extensionAlias = 'affiwave';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->validate()
                ->ifTrue(static fn (array $v): bool => true === $v['enabled'] && (null === $v['api_url'] || '' === $v['api_url'] || null === $v['api_key'] || '' === $v['api_key']))
                ->thenInvalid('"affiwave.api_url" and "affiwave.api_key" are required when "affiwave.enabled" is true.')
            ->end()
            ->children()
                ->scalarNode('api_url')->defaultNull()->info('AffiWave base URL, e.g. https://affiwave.example (no trailing path).')->end()
                ->scalarNode('api_key')->defaultNull()->info('API key with the "conversions:write" scope (and "reports:read" for the coupon fallback sync).')->end()
                ->scalarNode('webhook_secret')->defaultNull()->info('Signing secret of the AffiWave webhook (whsec_...). Empty = the webhook endpoint answers 503.')->end()
                ->scalarNode('program_id')
                    ->defaultNull()
                    ->setDeprecated('websystemspl/affiwave-bundle', '0.2', 'The "%node%" option is ignored and will be removed in 1.0: the programme is defined in AffiWave by the API key and the webhook.')
                    ->info('Ignored (deprecated since 0.2). The API key and the webhook are bound to one programme in AffiWave.')
                ->end()
                ->booleanNode('enabled')->defaultNull()->info('Null = on when api_url and api_key are both non-empty at runtime.')->end()
                ->floatNode('http_timeout')->defaultValue(5)->info('Seconds for calls to the AffiWave API.')->end()
                ->arrayNode('stripe')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('secret_key')->defaultNull()->info('Secret key of the built-in Stripe gateway (coupons, discount lookups). Not needed with your own StripeGatewayInterface.')->end()
                        ->scalarNode('api_base')->defaultNull()->info('Stripe API base URL override (e.g. a stripe-mock). Null = api.stripe.com.')->end()
                    ->end()
                ->end()
                ->arrayNode('click')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('query_parameter')->defaultValue('aw_click')->end()
                        ->scalarNode('cookie_name')->defaultValue('aw_click')->end()
                        ->integerNode('cookie_days')->defaultValue(30)->min(1)->end()
                        ->booleanNode('listener')->defaultTrue()->info('Remember the click token in a first-party cookie.')->end()
                    ->end()
                ->end()
                ->arrayNode('conversions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_attempts')->defaultValue(20)->min(1)->info('Delivery attempts before a failing conversion is given up (status "rejected").')->end()
                    ->end()
                ->end()
                ->arrayNode('coupons')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->info('Mirror AffiWave coupons as Stripe promotion codes.')->end()
                        ->scalarNode('duration')->defaultValue('once')->info('Stripe coupon duration: once, forever or repeating.')->end()
                        ->integerNode('duration_months')->defaultValue(1)->min(1)->info('Months, for the "repeating" duration.')->end()
                        ->floatNode('vat_rate')->defaultValue(0)->min(0)->info('VAT percent used to turn a gross fixed amount into net. 0 = no conversion.')->end()
                        ->arrayNode('currencies')
                            ->beforeNormalization()
                                ->ifString()->then(static fn (string $v): array => array_values(array_filter(array_map('trim', explode(',', $v)), static fn (string $s): bool => '' !== $s)))
                            ->end()
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Currencies accepted for fixed-amount coupons. Empty = any.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $parameters = $container->parameters();
        $parameters->set('affiwave.api_url', $config['api_url']);
        $parameters->set('affiwave.api_key', $config['api_key']);
        $parameters->set('affiwave.webhook_secret', $config['webhook_secret']);
        $parameters->set('affiwave.enabled', $config['enabled']);
        $parameters->set('affiwave.http_timeout', $config['http_timeout']);
        $parameters->set('affiwave.click.query_parameter', $config['click']['query_parameter']);
        $parameters->set('affiwave.click.cookie_name', $config['click']['cookie_name']);
        $parameters->set('affiwave.click.cookie_days', $config['click']['cookie_days']);
        $parameters->set('affiwave.conversions.max_attempts', $config['conversions']['max_attempts']);
        $parameters->set('affiwave.coupons.enabled', $config['coupons']['enabled']);
        $parameters->set('affiwave.coupons.duration', $config['coupons']['duration']);
        $parameters->set('affiwave.coupons.duration_months', $config['coupons']['duration_months']);
        $parameters->set('affiwave.coupons.vat_rate', $config['coupons']['vat_rate']);
        $parameters->set('affiwave.coupons.currencies', $config['coupons']['currencies']);
        $parameters->set('affiwave.stripe.secret_key', $config['stripe']['secret_key']);
        $parameters->set('affiwave.stripe.api_base', $config['stripe']['api_base']);

        $container->import(\dirname(__DIR__).'/config/services.php');

        // The built-in gateway needs stripe-php; an application can alias its own StripeGatewayInterface instead.
        if (class_exists(\Stripe\StripeClient::class)) {
            $container->services()
                ->set('affiwave.stripe_gateway', StripeGateway::class)
                ->args([param('affiwave.stripe.secret_key'), param('affiwave.stripe.api_base')]);
            $container->services()->alias(StripeGatewayInterface::class, 'affiwave.stripe_gateway');
        }

        // Registered unless the config switches coupons off (an env placeholder counts as on).
        if (false !== $config['coupons']['enabled']) {
            $container->services()
                ->set(StripeCouponSynchronizer::class)
                ->args([
                    service(AffiWaveCouponRepository::class),
                    service(StripeGatewayInterface::class),
                    service('doctrine.orm.entity_manager'),
                    service(Settings::class),
                    service('logger')->nullOnInvalid(),
                    service('lock.factory')->nullOnInvalid(),
                ])
                ->public();
        }

        if ($config['click']['listener']) {
            $container->services()
                ->set(ClickCookieListener::class)
                ->args([service(Attribution\ClickTokenResolver::class), param('affiwave.click.cookie_name'), param('affiwave.click.cookie_days')])
                ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse']);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->hasDefinition(StripeCouponSynchronizer::class)
                    && !$container->hasDefinition(StripeGatewayInterface::class) && !$container->hasAlias(StripeGatewayInterface::class)) {
                    throw new \LogicException('affiwave.coupons.enabled needs a Stripe gateway: run "composer require stripe/stripe-php" or register your own implementation of '.StripeGatewayInterface::class.' (alias it to that interface).');
                }
            }
        });
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        // Entities only - the bundle ships no migrations; the application runs doctrine:migrations:diff.
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'AffiWaveBundle' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__.'/Entity',
                        'prefix' => 'AffiWave\\Bundle\\Entity',
                        'alias' => 'AffiWave',
                    ],
                ],
            ],
        ]);
    }
}
