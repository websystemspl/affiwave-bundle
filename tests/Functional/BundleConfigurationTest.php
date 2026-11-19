<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Functional;

use AffiWave\Bundle\Attribution\ClickTokenResolver;
use AffiWave\Bundle\Config\Settings;
use AffiWave\Bundle\Conversion\ConversionReporter;
use AffiWave\Bundle\Coupon\PromotionCodes;
use AffiWave\Bundle\Entity\AffiWaveConversion;
use AffiWave\Bundle\Entity\AffiWaveCoupon;
use AffiWave\Bundle\Tests\BundleTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class BundleConfigurationTest extends BundleTestCase
{
    public function testDefaultsAndDisabledWithoutKeys(): void
    {
        $this->boot();

        $settings = $this->service(Settings::class);
        self::assertFalse($settings->isEnabled());
        self::assertSame(5.0, $settings->httpTimeout);
        self::assertFalse($settings->couponsEnabled);
        self::assertSame([], $settings->couponCurrencies);
        self::assertFalse($this->service(PromotionCodes::class)->hasActive());
    }

    public function testEnabledWhenApiUrlAndKeyArePresent(): void
    {
        $this->boot(['api_url' => 'https://affiwave.example', 'api_key' => 'key', 'coupons' => ['currencies' => 'pln, eur']]);

        $settings = $this->service(Settings::class);
        self::assertTrue($settings->isEnabled());
        self::assertSame(['PLN', 'EUR'], $settings->couponCurrencies);
        self::assertTrue($this->service(ConversionReporter::class)->isEnabled());
    }

    public function testExplicitDisableWins(): void
    {
        $this->boot(['api_url' => 'https://affiwave.example', 'api_key' => 'key', 'enabled' => false]);

        self::assertFalse($this->service(Settings::class)->isEnabled());
    }

    public function testDeprecatedProgramIdIsAcceptedAndIgnored(): void
    {
        $this->boot(['api_url' => 'https://affiwave.example', 'api_key' => 'key', 'program_id' => 7]);

        self::assertTrue($this->service(Settings::class)->isEnabled());
        self::assertFalse($this->kernel->getContainer()->hasParameter('affiwave.program_id'));
    }

    public function testEnabledTrueWithoutKeyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->boot(['enabled' => true]);
    }

    public function testEntitiesAreMappedAndTablesCreated(): void
    {
        $this->boot();

        $tables = $this->em->getConnection()->createSchemaManager()->listTableNames();
        self::assertContains('affiwave_conversion', $tables);
        self::assertContains('affiwave_coupon', $tables);
        self::assertNotNull($this->em->getMetadataFactory()->getMetadataFor(AffiWaveConversion::class));
        self::assertNotNull($this->em->getMetadataFactory()->getMetadataFor(AffiWaveCoupon::class));
    }

    public function testWebhookRouteIsImported(): void
    {
        $this->boot();

        $router = $this->service('router');
        self::assertSame('/affiwave/webhook', $router->generate('affiwave_webhook'));
        $router->getContext()->setMethod('POST');
        self::assertSame('affiwave_webhook', $router->match('/affiwave/webhook')['_route'] ?? null);
    }

    public function testClickCookieListenerIsRegisteredOnlyWhenEnabled(): void
    {
        $this->boot(['click' => ['query_parameter' => 'ref', 'listener' => false]]);

        self::assertFalse($this->kernel->getContainer()->has(\AffiWave\Bundle\Attribution\ClickCookieListener::class));
        $resolver = $this->service(ClickTokenResolver::class);
        $request = \Symfony\Component\HttpFoundation\Request::create('/?ref='.str_repeat('a', 32));
        self::assertSame(str_repeat('a', 32), $resolver->resolve($request));
    }
}
