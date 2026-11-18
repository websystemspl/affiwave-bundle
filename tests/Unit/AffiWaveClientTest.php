<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Unit;

use AffiWave\Bundle\Api\AffiWaveClient;
use AffiWave\Bundle\Config\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AffiWaveClientTest extends TestCase
{
    /**
     * @return array{AffiWaveClient, object}
     */
    private function client(string $url, bool $debug): array
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 200]));

        return [new AffiWaveClient($http, new Settings(apiUrl: $url, apiKey: 'k'), $logger, $debug), $logger];
    }

    public function testNonHttpsUrlWarnsOnceOutsideDebug(): void
    {
        [$client, $logger] = $this->client('http://affiwave.example', false);

        $client->refund('a');
        $client->refund('b');

        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('https', $logger->warnings[0]);
    }

    public function testNoWarningForHttpsOrInDebug(): void
    {
        [$secure, $secureLogger] = $this->client('https://affiwave.example', false);
        [$debug, $debugLogger] = $this->client('http://affiwave.localhost', true);

        $secure->refund('a');
        $debug->refund('a');

        self::assertSame([], $secureLogger->warnings);
        self::assertSame([], $debugLogger->warnings);
    }
}
