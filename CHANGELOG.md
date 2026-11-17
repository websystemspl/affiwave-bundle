# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [0.1.0] - Unreleased

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
