<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Functional;

use AffiWave\Bundle\Api\AffiWaveClient;
use AffiWave\Bundle\Command\RetryConversionsCommand;
use AffiWave\Bundle\Command\SyncCouponsCommand;
use AffiWave\Bundle\Config\Settings;
use AffiWave\Bundle\Conversion\Conversion;
use AffiWave\Bundle\Conversion\ConversionReporter;
use AffiWave\Bundle\Coupon\StripeCouponSynchronizer;
use AffiWave\Bundle\Entity\AffiWaveConversion;
use AffiWave\Bundle\Entity\ConversionStatus;
use AffiWave\Bundle\Repository\AffiWaveConversionRepository;
use AffiWave\Bundle\Tests\BundleTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CommandsTest extends BundleTestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    /** @var list<array{method: string, url: string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->boot(['api_url' => 'https://affiwave.example', 'api_key' => 'k', 'coupons' => ['enabled' => true]]);
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): AffiWaveClient
    {
        $http = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new AffiWaveClient($http, $this->service(Settings::class));
    }

    private static function json(mixed $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    private function reporter(AffiWaveClient $client): ConversionReporter
    {
        return new ConversionReporter($client, $this->em, new AffiWaveConversionRepository($this->em));
    }

    private function retryCommand(AffiWaveClient $client): CommandTester
    {
        return new CommandTester(new RetryConversionsCommand(new AffiWaveConversionRepository($this->em), $this->reporter($client)));
    }

    private function seedFailed(): void
    {
        $failing = $this->reporter($this->client([self::json([], 503), self::json([], 503), self::json(['error' => 'unknown_token'], 422)]));
        foreach (['in_a', 'in_b', 'in_c'] as $order) {
            $failing->report(new Conversion($order, 5000, 'EUR', self::TOKEN));
        }
        $this->requests = [];
    }

    private function statusOf(string $order): ?ConversionStatus
    {
        $this->em->clear();

        return $this->em->getRepository(AffiWaveConversion::class)->findOneBy(['externalOrderId' => $order])?->getStatus();
    }

    public function testRetryDeliversFailedConversionsOnly(): void
    {
        $this->seedFailed();
        self::assertSame(ConversionStatus::Failed, $this->statusOf('in_a'));
        self::assertSame(ConversionStatus::Rejected, $this->statusOf('in_c'));

        $tester = $this->retryCommand($this->client([self::json(['duplicate' => false, 'conversion_id' => 1], 201), self::json(['duplicate' => true], 200)]));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertCount(2, $this->requests);
        self::assertSame(ConversionStatus::Reported, $this->statusOf('in_a'));
        self::assertSame(ConversionStatus::Duplicate, $this->statusOf('in_b'));
        self::assertSame(ConversionStatus::Rejected, $this->statusOf('in_c'));
        self::assertStringContainsString('Delivered: 2', $tester->getDisplay());
    }

    public function testRetryRespectsLimitAndDryRun(): void
    {
        $this->seedFailed();

        $dry = $this->retryCommand($this->client([]));
        $dry->execute(['--dry-run' => true]);
        self::assertSame([], $this->requests);
        self::assertStringContainsString('in_a', $dry->getDisplay());
        self::assertStringContainsString('2 conversion(s) would be sent', $dry->getDisplay());
        self::assertSame(ConversionStatus::Failed, $this->statusOf('in_a'));

        $limited = $this->retryCommand($this->client([self::json(['duplicate' => false, 'conversion_id' => 1], 201)]));
        $limited->execute(['--limit' => '1']);
        self::assertCount(1, $this->requests);
        self::assertSame(ConversionStatus::Reported, $this->statusOf('in_a'));
        self::assertSame(ConversionStatus::Failed, $this->statusOf('in_b'));
    }

    public function testRetryKeepsStillFailingConversions(): void
    {
        $this->seedFailed();

        $tester = $this->retryCommand($this->client([self::json([], 503), self::json([], 503)]));
        $tester->execute([]);

        self::assertSame(ConversionStatus::Failed, $this->statusOf('in_a'));
        self::assertStringContainsString('failed (will retry): 2', $tester->getDisplay());
    }

    public function testRetryDoesNothingWhenDisabled(): void
    {
        $this->seedFailed();
        $client = new AffiWaveClient(new MockHttpClient(), new Settings());
        $tester = new CommandTester(new RetryConversionsCommand(new AffiWaveConversionRepository($this->em), $this->reporter($client)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('disabled', $tester->getDisplay());
    }

    /** @param list<MockResponse> $responses */
    private function syncCommand(array $responses): CommandTester
    {
        return new CommandTester(new SyncCouponsCommand(
            $this->client($responses),
            $this->service('event_dispatcher'),
            $this->service(StripeCouponSynchronizer::class),
        ));
    }

    /** @return array<string, mixed> */
    private static function apiCoupon(int $id, string $code = 'PARTNER10', string $status = 'active'): array
    {
        return ['id' => $id, 'code' => $code, 'discountType' => 'percentage', 'discountValue' => '10.00', 'currency' => 'PLN',
            'status' => $status, 'usageLimit' => null, 'expiresAt' => null, 'updatedAt' => '2026-11-01T10:00:00+00:00', 'createdAt' => '2026-10-01T10:00:00+00:00'];
    }

    public function testCouponSyncPullsChangedCouponsIntoStripe(): void
    {
        $tester = $this->syncCommand([self::json([self::apiCoupon(1), self::apiCoupon(2, 'SECOND5')])]);

        $exit = $tester->execute(['--since' => '2026-10-30T00:00:00+00:00']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('/api/coupon_codes', $this->requests[0]['url']);
        self::assertStringContainsString('changedSince=2026-10-30T00:00:00%2B00:00', $this->requests[0]['url']);
        self::assertCount(2, $this->stripe()->callsTo('createPromotionCode'));
        self::assertStringContainsString('created=2', $tester->getDisplay());
    }

    public function testCouponSyncIsIdempotentAndPaginates(): void
    {
        $page1 = [];
        for ($i = 1; $i <= 50; ++$i) {
            $page1[] = self::apiCoupon($i, 'C'.$i);
        }
        $tester = $this->syncCommand([self::json($page1), self::json([self::apiCoupon(51, 'C51')])]);
        $tester->execute(['--all' => true]);

        self::assertCount(2, $this->requests);
        self::assertStringContainsString('page=2', $this->requests[1]['url']);
        self::assertStringNotContainsString('changedSince', $this->requests[0]['url']);
        self::assertCount(51, $this->stripe()->callsTo('createPromotionCode'));

        $again = $this->syncCommand([self::json([self::apiCoupon(1, 'C1')])]);
        $again->execute([]);
        self::assertStringContainsString('unchanged=1', $again->getDisplay());
        self::assertCount(51, $this->stripe()->callsTo('createPromotionCode'));
    }

    public function testCouponSyncFailsOnApiErrors(): void
    {
        $tester = $this->syncCommand([self::json(['error' => 'insufficient_scope'], 403)]);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('reports:read', $tester->getDisplay());
    }

    public function testRetryTakesTheLeastRecentlyAttemptedFirst(): void
    {
        $this->seedFailed(); // in_a, in_b failed, in_c rejected
        // in_a (lower id) was attempted more recently than in_b.
        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE affiwave_conversion SET last_attempt_at = '2026-11-01 10:00:00' WHERE external_order_id = 'in_a'");
        $connection->executeStatement("UPDATE affiwave_conversion SET last_attempt_at = '2026-10-01 10:00:00' WHERE external_order_id = 'in_b'");
        $this->em->clear();

        $tester = $this->retryCommand($this->client([]));
        $tester->execute(['--dry-run' => true]);
        $lines = array_values(array_filter(explode("\n", $tester->getDisplay()), static fn (string $l): bool => str_starts_with($l, 'in_')));

        self::assertStringStartsWith('in_b', $lines[0]);
        self::assertStringStartsWith('in_a', $lines[1]);
    }

    public function testCouponSyncRejectsABadSinceDate(): void
    {
        self::assertSame(Command::INVALID, $this->syncCommand([])->execute(['--since' => 'not a date']));
    }
}
