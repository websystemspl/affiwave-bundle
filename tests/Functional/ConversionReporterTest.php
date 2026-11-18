<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Functional;

use AffiWave\Bundle\Api\AffiWaveClient;
use AffiWave\Bundle\Config\Settings;
use AffiWave\Bundle\Conversion\Conversion;
use AffiWave\Bundle\Conversion\ConversionReporter;
use AffiWave\Bundle\Conversion\ReportOutcome;
use AffiWave\Bundle\Entity\AffiWaveConversion;
use AffiWave\Bundle\Entity\ConversionStatus;
use AffiWave\Bundle\Repository\AffiWaveConversionRepository;
use AffiWave\Bundle\Tests\BundleTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConversionReporterTest extends BundleTestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];
    private object $logger;

    protected function setUp(): void
    {
        $this->boot();
        $this->logger = $this->memoryLogger();
    }

    /**
     * @param list<MockResponse|callable> $responses
     */
    private function reporter(array $responses, bool $enabled = true, int $maxAttempts = 20): ConversionReporter
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $next = array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);

            return $next instanceof MockResponse ? $next : $next($method, $url, $options);
        });
        $settings = new Settings(apiUrl: $enabled ? 'https://affiwave.example/' : '', apiKey: $enabled ? 'secret-key' : '', httpTimeout: 5);

        return new ConversionReporter(new AffiWaveClient($http, $settings), $this->em, new AffiWaveConversionRepository($this->em), $this->logger, $maxAttempts);
    }

    private static function json(array $body, int $status = 201): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }

    private function conversion(string $order = 'in_1', int $amount = 9900, ?string $token = self::TOKEN, ?string $coupon = null): Conversion
    {
        return new Conversion($order, $amount, 'pln', $token, $coupon, new \DateTimeImmutable('2026-11-01T10:00:00+01:00'), 'buyer@example.com', '203.0.113.7', 'UA/1.0');
    }

    private function stored(string $order = 'in_1'): ?AffiWaveConversion
    {
        $this->em->clear();

        return $this->em->getRepository(AffiWaveConversion::class)->findOneBy(['externalOrderId' => $order]);
    }

    public function testNewConversionIsReportedWithTheContractPayload(): void
    {
        $reporter = $this->reporter([self::json(['duplicate' => false, 'conversion_id' => 12, 'commission_amount' => '9.90'], 201)]);

        $result = $reporter->report($this->conversion());

        self::assertSame(ReportOutcome::Reported, $result->outcome);
        self::assertTrue($result->isSuccess());
        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://affiwave.example/api/conversion', $this->requests[0]['url']);
        self::assertContains('Authorization: Bearer secret-key', $this->requests[0]['options']['normalized_headers']['authorization'] ?? []);

        $body = json_decode((string) $this->requests[0]['options']['body'], true);
        self::assertSame([
            'click_token' => self::TOKEN,
            'amount' => '99.00',
            'currency' => 'PLN',
            'external_order_id' => 'in_1',
            'occurred_at' => '2026-11-01T10:00:00+01:00',
            'customer_email' => 'buyer@example.com',
            'customer_ip' => '203.0.113.7',
            'customer_user_agent' => 'UA/1.0',
        ], $body);

        $row = $this->stored();
        self::assertSame(ConversionStatus::Reported, $row?->getStatus());
        self::assertSame('12', $row->getConversionId());
        self::assertSame('9.90', $row->getCommissionAmount());
        self::assertSame(1, $row->getAttempts());
        self::assertNotNull($row->getReportedAt());
    }

    public function testAmountIsAStringInMainUnitsWithTwoDecimals(): void
    {
        self::assertSame('99.00', ConversionReporter::formatAmount(9900));
        self::assertSame('0.05', ConversionReporter::formatAmount(5));
        self::assertSame('12.30', ConversionReporter::formatAmount(1230));
        self::assertSame('1234567.89', ConversionReporter::formatAmount(123456789));
    }

    public function testDuplicateAnswerIsSuccess(): void
    {
        $reporter = $this->reporter([self::json(['duplicate' => true, 'conversion_id' => 5], 200)]);

        $result = $reporter->report($this->conversion());

        self::assertSame(ReportOutcome::Duplicate, $result->outcome);
        self::assertTrue($result->isSuccess());
        self::assertSame(ConversionStatus::Duplicate, $this->stored()?->getStatus());
    }

    public function testDeliveredConversionIsNotSentTwice(): void
    {
        $reporter = $this->reporter([self::json(['duplicate' => false, 'conversion_id' => 1])]);

        $reporter->report($this->conversion());
        $second = $reporter->report($this->conversion());

        self::assertSame(ReportOutcome::AlreadyReported, $second->outcome);
        self::assertCount(1, $this->requests);
        self::assertSame(1, $this->em->getRepository(AffiWaveConversion::class)->count([]));
    }

    public function testCouponIsSentWhenThereIsNoClickToken(): void
    {
        $reporter = $this->reporter([self::json(['duplicate' => false, 'conversion_id' => 1])]);

        $result = $reporter->report($this->conversion(token: null, coupon: ' PARTNER10 '));

        self::assertSame(ReportOutcome::Reported, $result->outcome);
        $body = json_decode((string) $this->requests[0]['options']['body'], true);
        self::assertSame('PARTNER10', $body['coupon']);
        self::assertArrayNotHasKey('click_token', $body);
    }

    public function testClickTokenWinsOverCoupon(): void
    {
        $reporter = $this->reporter([self::json(['duplicate' => false, 'conversion_id' => 1])]);

        $reporter->report($this->conversion(coupon: 'PARTNER10'));

        $body = json_decode((string) $this->requests[0]['options']['body'], true);
        self::assertSame(self::TOKEN, $body['click_token']);
        self::assertArrayNotHasKey('coupon', $body);
    }

    public function testNothingToAttributeOrNothingToPayIsSkippedWithoutStoring(): void
    {
        $reporter = $this->reporter([]);

        self::assertSame(ReportOutcome::Skipped, $reporter->report($this->conversion(token: null))->outcome);
        self::assertSame(ReportOutcome::Skipped, $reporter->report($this->conversion(token: 'not-a-token'))->outcome);
        self::assertSame(ReportOutcome::Skipped, $reporter->report($this->conversion(amount: 0))->outcome);
        self::assertSame(ReportOutcome::Skipped, $reporter->report($this->conversion(amount: -500))->outcome);
        self::assertSame(ReportOutcome::Skipped, $reporter->report($this->conversion(order: ''))->outcome);
        self::assertSame([], $this->requests);
        self::assertSame(0, $this->em->getRepository(AffiWaveConversion::class)->count([]));
    }

    public function testDisabledBundleIsANoOp(): void
    {
        $reporter = $this->reporter([], enabled: false);

        $result = $reporter->report($this->conversion());

        self::assertSame(ReportOutcome::Disabled, $result->outcome);
        self::assertFalse($reporter->isEnabled());
        self::assertSame([], $this->requests);
        self::assertSame(0, $this->em->getRepository(AffiWaveConversion::class)->count([]));
        self::assertSame(ReportOutcome::Disabled, $reporter->refund('in_1')->outcome);
    }

    public function testPermanentRejectionIsStoredAndNotRetried(): void
    {
        $reporter = $this->reporter([self::json(['error' => 'unknown_token', 'message' => 'No such click'], 422)]);

        $result = $reporter->report($this->conversion());

        self::assertSame(ReportOutcome::Rejected, $result->outcome);
        self::assertSame(422, $result->httpStatus);
        $row = $this->stored();
        self::assertSame(ConversionStatus::Rejected, $row?->getStatus());
        self::assertStringContainsString('unknown_token', (string) $row->getLastError());

        // Reporting the same order again does not hit the API.
        self::assertSame(ReportOutcome::Rejected, $reporter->report($this->conversion())->outcome);
        self::assertCount(1, $this->requests);
        self::assertNotSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{int, string|null}>
     */
    public static function transientStatuses(): iterable
    {
        yield 'server error' => [500, null];
        yield 'rate limited' => [429, null];
        yield 'exchange rate missing' => [503, 'exchange_rate_unavailable'];
        yield 'bad key' => [401, null];
        yield 'plan required' => [403, 'plan_required'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transientStatuses')]
    public function testTransientFailureIsKeptForRetry(int $status, ?string $error): void
    {
        $reporter = $this->reporter([self::json($error ? ['error' => $error] : [], $status), self::json(['duplicate' => false, 'conversion_id' => 3])]);

        $result = $reporter->report($this->conversion());

        self::assertSame(ReportOutcome::Failed, $result->outcome);
        self::assertSame(ConversionStatus::Failed, $this->stored()?->getStatus());

        // The retry path delivers it.
        $row = $this->stored();
        self::assertNotNull($row);
        self::assertSame(ReportOutcome::Reported, $reporter->deliver($row)->outcome);
        self::assertSame(2, $this->stored()?->getAttempts());
    }

    public function testBadRequestIsPermanent(): void
    {
        foreach ([[400, 'invalid_amount'], [422, 'amount_too_large']] as $i => [$status, $error]) {
            $reporter = $this->reporter([self::json(['error' => $error], $status)]);
            self::assertSame(ReportOutcome::Rejected, $reporter->report($this->conversion('in_'.$i))->outcome, $error);
        }
    }

    public function testTimeoutDoesNotThrowAndLeavesTheConversionForRetry(): void
    {
        $reporter = $this->reporter([new MockResponse('', ['error' => 'Operation timed out'])]);

        $result = $reporter->report($this->conversion());

        self::assertSame(ReportOutcome::Failed, $result->outcome);
        $row = $this->stored();
        self::assertSame(ConversionStatus::Failed, $row?->getStatus());
        self::assertStringContainsString('timed out', (string) $row->getLastError());
        self::assertNotSame([], $this->logger->records);
    }

    public function testRefundPostsTheOrderId(): void
    {
        $reporter = $this->reporter([self::json(['refunded' => true], 200)]);

        $result = $reporter->refund('in_1');

        self::assertSame(ReportOutcome::Refunded, $result->outcome);
        self::assertSame('https://affiwave.example/api/conversion/refund', $this->requests[0]['url']);
        self::assertSame(['external_order_id' => 'in_1'], json_decode((string) $this->requests[0]['options']['body'], true));
    }

    public function testZeroAndThreeDecimalCurrenciesAreSentAsTwoDecimalMainUnits(): void
    {
        self::assertSame('1000.00', ConversionReporter::formatAmount(1000, 'JPY'));
        self::assertSame('12.35', ConversionReporter::formatAmount(12345, 'KWD'));
        self::assertSame('12.34', ConversionReporter::formatAmount(12344, 'kwd'));
        self::assertSame('0.01', ConversionReporter::formatAmount(5, 'BHD'));
        self::assertSame('0.00', ConversionReporter::formatAmount(4, 'BHD'));

        $reporter = $this->reporter([self::json(['duplicate' => false, 'conversion_id' => 1]), self::json(['duplicate' => false, 'conversion_id' => 2])]);
        $reporter->report(new Conversion('jp_1', 123456, 'jpy', self::TOKEN));
        $reporter->report(new Conversion('kw_1', 12345, 'kwd', self::TOKEN));

        $first = json_decode((string) $this->requests[0]['options']['body'], true);
        $second = json_decode((string) $this->requests[1]['options']['body'], true);
        self::assertSame(['123456.00', 'JPY'], [$first['amount'], $first['currency']]);
        self::assertSame(['12.35', 'KWD'], [$second['amount'], $second['currency']]);
        self::assertMatchesRegularExpression('/^\d{1,12}(\.\d{1,2})?$/', $second['amount']);
    }

    public function testInvalidEmailAndIpAreOmittedInsteadOfRejectingTheConversion(): void
    {
        $reporter = $this->reporter([self::json(['duplicate' => false, 'conversion_id' => 1]), self::json(['duplicate' => false, 'conversion_id' => 2]), self::json(['duplicate' => false, 'conversion_id' => 3])]);

        $reporter->report(new Conversion('o1', 1000, 'EUR', self::TOKEN, customerEmail: 'not-an-email', customerIp: 'unknown'));
        $reporter->report(new Conversion('o2', 1000, 'EUR', self::TOKEN, customerEmail: ' ok@example.com ', customerIp: 'unknown, 198.51.100.4, 10.0.0.1'));
        $reporter->report(new Conversion('o3', 1000, 'EUR', self::TOKEN, customerIp: '2001:db8::1'));

        $bodies = array_map(static fn (array $r): array => json_decode((string) $r['options']['body'], true), $this->requests);
        self::assertArrayNotHasKey('customer_email', $bodies[0]);
        self::assertArrayNotHasKey('customer_ip', $bodies[0]);
        self::assertSame('ok@example.com', $bodies[1]['customer_email']);
        self::assertSame('198.51.100.4', $bodies[1]['customer_ip']);
        self::assertSame('2001:db8::1', $bodies[2]['customer_ip']);
    }

    public function testConversionIsGivenUpAfterMaxAttempts(): void
    {
        $reporter = $this->reporter([self::json([], 503), self::json([], 503), self::json([], 503)], maxAttempts: 2);

        self::assertSame(ReportOutcome::Failed, $reporter->report($this->conversion())->outcome);
        $row = $this->stored();
        self::assertNotNull($row);

        self::assertSame(ReportOutcome::Rejected, $reporter->deliver($row)->outcome);

        $row = $this->stored();
        self::assertSame(ConversionStatus::Rejected, $row?->getStatus());
        self::assertStringContainsString('max_attempts_exceeded', (string) $row->getLastError());
        self::assertStringContainsString('HTTP 503', (string) $row->getLastError());
        self::assertSame(2, $row->getAttempts());
        // Nothing more is sent for it.
        self::assertSame(ReportOutcome::Rejected, $reporter->deliver($row)->outcome);
        self::assertCount(2, $this->requests);
    }

    public function testRefundOfADeliveredConversionMarksItRefunded(): void
    {
        $reporter = $this->reporter([self::json(['duplicate' => false, 'conversion_id' => 1]), self::json(['refunded' => true], 200)]);
        $reporter->report($this->conversion());

        self::assertSame(ReportOutcome::Refunded, $reporter->refund('in_1')->outcome);
        self::assertSame(ConversionStatus::Refunded, $this->stored()?->getStatus());
        self::assertCount(2, $this->requests);

        // Refunding again, or re-reporting, sends nothing.
        self::assertSame(ReportOutcome::Refunded, $reporter->refund('in_1')->outcome);
        self::assertSame(ReportOutcome::Refunded, $reporter->report($this->conversion())->outcome);
        self::assertCount(2, $this->requests);
    }

    public function testRefundOfAnUndeliveredConversionIsLocalAndNeverSentLater(): void
    {
        $reporter = $this->reporter([self::json([], 503)]);
        $reporter->report($this->conversion());
        self::assertSame(ConversionStatus::Failed, $this->stored()?->getStatus());

        $result = $reporter->refund('in_1');

        self::assertSame(ReportOutcome::Refunded, $result->outcome);
        self::assertSame(ConversionStatus::Refunded, $this->stored()?->getStatus());
        self::assertCount(1, $this->requests, 'no refund request for an order AffiWave never got');
        self::assertSame([], (new AffiWaveConversionRepository($this->em))->findRetryable());
        $row = $this->stored();
        self::assertNotNull($row);
        self::assertSame(ReportOutcome::Refunded, $reporter->deliver($row)->outcome);
        self::assertCount(1, $this->requests);
    }

    public function testRefundFailuresDoNotThrow(): void
    {
        self::assertSame(ReportOutcome::Rejected, $this->reporter([self::json(['error' => 'unknown_conversion'], 422)])->refund('x')->outcome);
        self::assertSame(ReportOutcome::Failed, $this->reporter([self::json([], 503)])->refund('x')->outcome);
        self::assertSame(ReportOutcome::Failed, $this->reporter([new MockResponse('', ['error' => 'boom'])])->refund('x')->outcome);
    }
}
