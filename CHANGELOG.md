# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [0.2.0] - 2026-11-19

### Changed

- The bundle no longer uses an AffiWave programme id. The programme is defined in AffiWave by the API key and the
  webhook (each bound to one programme), so the webhook no longer filters events by `program_id` and
  `Settings::programId()` is gone.

### Deprecated

- The `affiwave.program_id` option: accepted but ignored, removed in 1.0. Drop it and `AFFIWAVE_PROGRAM_ID` from your
  configuration.

### Removed

- The `affiwave.program_id` container parameter and the webhook answer `ignored: other_program`.

## [0.1.0] - 2026-11-18

### Added

- `AffiWaveBundle` with the `affiwave:` configuration (API URL/key, webhook secret, programme id, click and coupon options).
- Attribution: `ClickTokenResolver` (query beats cookie, 32 hex validation) and a `kernel.response` listener that sets a
  first-party `aw_click` cookie.
- Conversions: `Conversion` DTO, `ConversionReporter::report()` / `refund()` (never throws, idempotent by
  `external_order_id`), `AffiWaveConversion` entity (`affiwave_conversion`) and the `affiwave:conversions:retry` command.
- Stripe helpers: `StripeAmounts` (net amounts of invoices and Checkout sessions), `NetAmountResolverInterface`,
  `DiscountCodeResolver` and `StripeGatewayInterface` with a stripe-php implementation.
- Coupons: signed webhook endpoint (`/affiwave/webhook`), `CouponChangedEvent`, `StripeCouponSynchronizer`
  (AffiWave coupons -> Stripe coupons and promotion codes), `AffiWaveCoupon` entity (`affiwave_coupon`),
  `PromotionCodes::hasActive()` and the `affiwave:coupons:sync` fallback command.
- Doctrine mapping registered by the bundle; no migrations shipped.
- Currency exponents (`Money\Currency`): zero-decimal (JPY, KRW, ...) and three-decimal (KWD, BHD, ...) currencies in
  the conversion `amount` (always two decimals, three-decimal rounded) and in fixed-coupon `amount_off`.
- `conversions.max_attempts` (default 20): a conversion that keeps failing is given up as `rejected` with the reason in
  `last_error`; `affiwave:conversions:retry` takes the least recently attempted rows first (`last_attempt_at`).
- `ConversionReporter::refund()` marks the stored conversion `refunded`; a never delivered one is refunded locally
  without a request and never sent afterwards.
- Concurrent deliveries of one new coupon: optional `symfony/lock` per coupon, otherwise the mapping row is claimed
  before Stripe is called (`in_progress` result), plus idempotency keys on Stripe create calls.
- A readable error when `coupons.enabled` has no Stripe gateway; a warning when `api_url` is not HTTPS outside debug.

### Changed

- `customer_email` / `customer_ip` are sent only when valid (the first valid IP of a forwarded list), so AffiWave no
  longer rejects the whole conversion over them.
- `GET /api/coupon_codes` items carry no programme: the `program_id` filter applies to webhooks only.
