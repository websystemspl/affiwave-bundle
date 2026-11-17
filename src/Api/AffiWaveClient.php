<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Api;

use AffiWave\Bundle\Config\Settings;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin HTTP client of the AffiWave API (Bearer key). Transport problems (timeout, DNS, TLS) are thrown as
 * {@see TransportExceptionInterface}; every HTTP answer, errors included, comes back as an {@see ApiResult}.
 */
class AffiWaveClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Settings $settings,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isEnabled();
    }

    /**
     * `POST /api/conversion`.
     *
     * @param array<string, mixed> $payload
     *
     * @throws TransportExceptionInterface
     */
    public function createConversion(array $payload): ApiResult
    {
        return $this->request('POST', '/api/conversion', ['json' => $payload]);
    }

    /**
     * `POST /api/conversion/refund`.
     *
     * @throws TransportExceptionInterface
     */
    public function refund(string $externalOrderId): ApiResult
    {
        return $this->request('POST', '/api/conversion/refund', ['json' => ['external_order_id' => $externalOrderId]]);
    }

    /**
     * `GET /api/coupon_codes` (50 per page; needs the `reports:read` scope).
     *
     * @throws TransportExceptionInterface
     */
    public function listCouponCodes(?\DateTimeInterface $changedSince = null, int $page = 1): ApiResult
    {
        $query = ['page' => $page];
        if (null !== $changedSince) {
            $query['changedSince'] = $changedSince->format(\DATE_ATOM);
        }

        return $this->request('GET', '/api/coupon_codes', ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws TransportExceptionInterface
     */
    private function request(string $method, string $path, array $options): ApiResult
    {
        $timeout = $this->settings->httpTimeout;
        $response = $this->httpClient->request($method, $this->settings->apiUrl().$path, $options + [
            'auth_bearer' => $this->settings->apiKey(),
            'headers' => ['Accept' => 'application/json'],
            'timeout' => $timeout,
            'max_duration' => $timeout * 1.6,
        ]);

        $status = $response->getStatusCode();
        $raw = $response->getContent(false);
        $decoded = json_decode($raw, true);

        return new ApiResult($status, \is_array($decoded) ? $decoded : [], $raw);
    }
}
