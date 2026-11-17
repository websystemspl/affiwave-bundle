<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Unit;

use AffiWave\Bundle\Config\Settings;
use AffiWave\Bundle\Webhook\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const BODY = '{"event":"coupon.created","data":{"coupon_id":1}}';

    private function verifier(string $secret = self::SECRET): WebhookSignatureVerifier
    {
        return new WebhookSignatureVerifier(new Settings(webhookSecret: $secret));
    }

    public static function sign(string $body, int $timestamp, string $secret = self::SECRET): string
    {
        return \sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, $secret));
    }

    public function testValidSignatureReturnsTheDecodedEvent(): void
    {
        $now = 1_800_000_000;
        $event = $this->verifier()->verify(self::BODY, self::sign(self::BODY, $now), $now);

        self::assertSame('coupon.created', $event['event']);
    }

    public function testSignatureWithinToleranceIsAccepted(): void
    {
        $now = 1_800_000_000;

        self::assertIsArray($this->verifier()->verify(self::BODY, self::sign(self::BODY, $now - 299), $now));
        self::assertIsArray($this->verifier()->verify(self::BODY, self::sign(self::BODY, $now + 299), $now));
    }

    public function testRotatedSecretsAreAcceptedWhenAnyV1Matches(): void
    {
        $now = 1_800_000_000;
        $header = \sprintf('t=%d,v1=%s,v1=%s', $now, str_repeat('0', 64), hash_hmac('sha256', $now.'.'.self::BODY, self::SECRET));

        self::assertIsArray($this->verifier()->verify(self::BODY, $header, $now));
    }

    public function testBadSignatureIsRejected(): void
    {
        $now = 1_800_000_000;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid AffiWave webhook signature.');

        $this->verifier()->verify(self::BODY, self::sign(self::BODY, $now, 'other_secret'), $now);
    }

    public function testTamperedBodyIsRejected(): void
    {
        $now = 1_800_000_000;
        $this->expectException(\InvalidArgumentException::class);

        $this->verifier()->verify(self::BODY.' ', self::sign(self::BODY, $now), $now);
    }

    public function testExpiredTimestampIsRejected(): void
    {
        $now = 1_800_000_000;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expired');

        $this->verifier()->verify(self::BODY, self::sign(self::BODY, $now - 301), $now);
    }

    public function testMissingOrMalformedHeaderIsRejected(): void
    {
        foreach ([null, '', 'garbage', 't=abc,v1=ff', 't=1800000000'] as $header) {
            try {
                $this->verifier()->verify(self::BODY, $header, 1_800_000_000);
                self::fail('Expected an exception for header '.var_export($header, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNonObjectBodyIsRejectedEvenWhenSigned(): void
    {
        $now = 1_800_000_000;
        $this->expectException(\InvalidArgumentException::class);

        $this->verifier()->verify('"text"', self::sign('"text"', $now), $now);
    }

    public function testMissingSecretMeansNotConfigured(): void
    {
        self::assertFalse($this->verifier('')->isConfigured());
        $this->expectException(\RuntimeException::class);

        $this->verifier('')->verify(self::BODY, self::sign(self::BODY, time()));
    }
}
