# AffiWave bundle

Symfony bundle that connects a SaaS application to the [AffiWave](https://affiwave.com) affiliate platform:

- **Attribution**: reads the partner's `?aw_click=<token>` link, keeps the token in a first-party cookie and hands it to your code.
- **Conversions (S2S)**: reports every paid Stripe payment (net amount) to `POST /api/conversion`, idempotently, without ever throwing into your webhook.
- **Coupons**: receives `coupon.created` / `coupon.updated` webhooks and mirrors AffiWave partner coupons as Stripe coupons + promotion codes.

It is the generalised version of an integration that runs in production in a Symfony 8 / Stripe SaaS application.

## Requirements

- PHP 8.2+
- Symfony 7.4 or 8.x (`framework-bundle`, `http-client`, `console`)
- Doctrine ORM 3 with DoctrineBundle 2.12+ / 3.x (2.x without native lazy objects also needs `symfony/var-exporter`)
- `stripe/stripe-php` 19+ (optional): the built-in Stripe gateway for partner coupons and discount lookups

## Installation

```bash
composer require websystemspl/affiwave-bundle
composer require stripe/stripe-php   # for coupons / discount lookups
```

Register the bundle (Flex does it when the recipe is available):

```php
// config/bundles.php
return [
    // ...
    AffiWave\Bundle\AffiWaveBundle::class => ['all' => true],
];
```

Import the webhook route (only needed for coupons):

```yaml
# config/routes/affiwave.yaml
affiwave:
    resource: '@AffiWaveBundle/config/routes.php'
    # prefix: /integrations   # optional: the route is /affiwave/webhook, so /integrations/affiwave/webhook
```

The route has no firewall of its own: it is authenticated by the webhook signature. Make sure `/affiwave/webhook` is
reachable without a login (and without a CSRF check) in `security.yaml`.

### Database

The bundle registers two entities (`affiwave_conversion`, `affiwave_coupon`) but **ships no migrations**. Generate one in your application:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

Column names are explicit, so the result does not depend on your naming strategy. `affiwave_conversion` keeps the
buyer e-mail, IP and user agent you pass in `Conversion` (needed to retry a failed delivery) - mention it in your privacy policy.

## Configuration

```yaml
# config/packages/affiwave.yaml
affiwave:
    api_url: '%env(default::AFFIWAVE_API_URL)%'            # e.g. https://affiwave.example
    api_key: '%env(default::AFFIWAVE_API_KEY)%'            # scope conversions:write (+ reports:read for the coupon sync)
    webhook_secret: '%env(default::AFFIWAVE_WEBHOOK_SECRET)%'   # whsec_... shown once when the webhook is created
    http_timeout: 5
    click:
        query_parameter: aw_click   # must equal the attribution parameter of the programme in AffiWave
        cookie_name: aw_click
        cookie_days: 30             # = cookie duration of the programme
        listener: true              # set the cookie on any response to a request carrying ?aw_click=
    conversions:
        max_attempts: 20            # failed deliveries before a conversion is given up (status "rejected")
    stripe:
        secret_key: '%env(default::STRIPE_SECRET_KEY)%'   # for the built-in gateway
    coupons:
        enabled: true               # mirror AffiWave coupons as Stripe promotion codes
        duration: once              # once | forever | repeating
        duration_months: 1          # for "repeating"
        vat_rate: 23                # fixed gross -> net amount_off; 0 = no conversion
        currencies: [PLN, EUR]      # accepted currencies of fixed coupons; empty = any
```

```dotenv
AFFIWAVE_API_URL=https://affiwave.example
AFFIWAVE_API_KEY=
AFFIWAVE_WEBHOOK_SECRET=
```

`enabled` defaults to "on when `api_url` and `api_key` are both non-empty at runtime", so an empty env var switches the
whole integration off: every call becomes a no-op (`ReportOutcome::Disabled`). `enabled: true` with a missing URL or key
is a configuration error. The webhook endpoint depends on `webhook_secret` only (empty = HTTP 503).

There is no programme id setting: in AffiWave the API key and the webhook are each bound to one programme, so the key
only sees that programme's data and the webhook only receives its events. The `program_id` option of 0.1 is still
accepted but ignored (deprecated, removed in 1.0).

## Usage

The cookie is `Secure` when `Request::isSecure()` says so. Behind a TLS-terminating proxy or load balancer, configure
`framework.trusted_proxies` and `trusted_headers`, otherwise the cookie is set without the `Secure` flag.
`api_url` should be `https://`: outside `kernel.debug` the client logs a warning (once per process) for any other scheme,
because the API key travels in every request.

### 1. Remember the partner at sign-up

The cookie is set by the bundle. Your code only stores the token next to your own account/organization entity:

```php
use AffiWave\Bundle\Attribution\ClickTokenResolver;

public function register(Request $request, ClickTokenResolver $clicks, EntityManagerInterface $em): Response
{
    // ...
    $organization->setAffiwaveClickToken($clicks->resolve($request)); // ?string: link wins over the cookie, 32 hex chars or null

    $em->flush();
    $response = $this->redirectToRoute('dashboard');
    $clicks->forget($response); // optional: the next sign-up from this browser is not the partner's

    return $response;
}
```

Store the referral date too if you want to ignore invoices paid before the referral. Deciding *which* payments belong to
a referred customer is your application's job: the bundle reports whatever you hand to it.

### 2. Report every paid payment (net amount)

Report **each** paid invoice (first payment, renewals, add-ons) and each one-time payment. The commission base is the
**net** amount (after discounts, without tax), in minor units; `external_order_id` makes the call idempotent.

```php
use AffiWave\Bundle\Conversion\Conversion;
use AffiWave\Bundle\Conversion\ConversionReporter;
use AffiWave\Bundle\Stripe\DiscountCodeResolver;
use AffiWave\Bundle\Stripe\NetAmountResolverInterface;

final class StripeWebhookController
{
    public function __construct(
        private ConversionReporter $affiwave,
        private NetAmountResolverInterface $netAmounts,
        private DiscountCodeResolver $discounts,
        private OrganizationRepository $organizations,
    ) {}

    // inside your Stripe webhook handler, after signature verification:
    private function onEvent(\Stripe\Event $event): void
    {
        switch ($event->type) {
            case 'invoice.paid':            // or invoice.payment_succeeded
                $invoice = $event->data->object;
                $org = $this->organizations->findByStripeCustomer($invoice->customer);
                if (null === $org || $invoice->amount_paid <= 0) {
                    return;
                }
                $this->affiwave->report(new Conversion(
                    externalOrderId: $invoice->id,
                    amountMinor: $this->netAmounts->fromInvoice($invoice),
                    currency: $invoice->currency,
                    clickToken: $org->getAffiwaveClickToken(),
                    couponCode: $this->discounts->couponCodeFromInvoice($invoice),
                    occurredAt: (new \DateTimeImmutable())->setTimestamp($invoice->status_transitions->paid_at ?? $invoice->created),
                    customerEmail: $org->getOwnerEmail(),
                ));
                break;

            case 'checkout.session.completed':
                $session = $event->data->object;
                if ('payment' !== $session->mode || 'paid' !== $session->payment_status) {
                    return; // mode=subscription is reported through its invoices
                }
                $this->affiwave->report(new Conversion(
                    externalOrderId: $session->id,
                    amountMinor: $this->netAmounts->fromCheckoutSession($session),
                    currency: $session->currency,
                    clickToken: $this->tokenOf($session),     // e.g. from client_reference_id / metadata you set at checkout
                    couponCode: $this->discounts->couponCodeFromSession($session),
                    customerEmail: $session->customer_details->email ?? null,
                ));
                break;

            case 'charge.refunded':
                // $this->affiwave->refund($externalOrderId);   // POST /api/conversion/refund
                break;
        }
    }
}
```

`report()` never throws. It returns a `ReportResult` whose `outcome` is one of:

| Outcome | Meaning |
| --- | --- |
| `Reported` | booked by AffiWave (HTTP 201) |
| `Duplicate` | AffiWave already had this `external_order_id` (HTTP 200, `duplicate: true`) |
| `AlreadyReported` | delivered earlier, no request was made |
| `Rejected` | permanent refusal (400/422: `unknown_token`, `invalid_amount`, ...); stored, never retried |
| `Failed` | 5xx, 429, `exchange_rate_unavailable`, timeout, 401/403 - stored, retried by the cron command; after `conversions.max_attempts` attempts the row is given up as `Rejected` (reason in `last_error`) |
| `Refunded` | result of `refund()`; the stored row becomes `refunded` and is never sent (again) |
| `Skipped` | nothing to attribute (no valid click token and no coupon) or amount <= 0; **nothing is stored** |
| `Disabled` | integration off |

A click token wins over a coupon (only `click_token` is then sent). Amounts are sent as strings with two decimals
(`9900` -> `"99.00"`); the currency is the payment currency, AffiWave converts it to the programme currency itself.
The currency exponent is respected: zero-decimal currencies (JPY, KRW, ...) are sent as `"1000.00"` for `1000`, and
three-decimal ones (KWD, BHD, ...) are rounded to two places (`12345` -> `"12.35"`), as the API accepts at most two.
`customer_email` is sent only when it is a valid address, `customer_ip` only when it is a valid IP (the first valid entry
of an `X-Forwarded-For` style list is used); invalid values are dropped rather than getting the conversion rejected with 400.

`refund($externalOrderId)` calls `POST /api/conversion/refund` and marks the stored row `refunded`. A conversion AffiWave
never received (still pending, failed or rejected) is only marked locally, with no request, and is never sent later.

If your prices are gross and you do not use Stripe Tax, implement `NetAmountResolverInterface` and alias it, so the VAT
is computed the way your application does it:

```yaml
services:
    AffiWave\Bundle\Stripe\NetAmountResolverInterface: '@App\Billing\MyNetAmountResolver'
```

`StripeAmounts::netFromInvoice()` / `netFromCheckoutSession()` are plain static helpers behind the default resolver
(`total_excluding_tax`, else `total` minus the taxes; `amount_total` minus `total_details.amount_tax`).

### 3. Partner coupons and `allow_promotion_codes`

With `coupons.enabled` the bundle creates, for every AffiWave coupon, a Stripe coupon plus a promotion code with the
same code (metadata `affiwave_coupon_id`). Show the promotion code field in Checkout only while at least one AffiWave
coupon is usable:

```php
use AffiWave\Bundle\Coupon\PromotionCodes;

$params = [/* ... */];
if ($promotionCodes->hasActive()) {
    $params['allow_promotion_codes'] = true;
}
$session = $stripe->checkout->sessions->create($params);
```

Rules of the synchronisation:

- code, amount, type, currency, usage limit and expiry are immutable in Stripe: a change deactivates the old promotion code
  and creates a new coupon + promotion code; a status change only toggles the code;
- `fixed` coupons arrive **gross**; with `coupons.vat_rate` > 0 the Stripe `amount_off` is the net equivalent. A fixed
  coupon works in its own currency only, and the currency exponent is respected (JPY has no decimals; three-decimal
  currencies such as KWD get an amount divisible by 10, as Stripe requires);
- a code outside `^[A-Za-z0-9-]{1,500}$` (and any other refusal a retry cannot fix) is stored in
  `affiwave_coupon.sync_error` and the webhook still answers 200, so AffiWave does not retry forever;
- a Stripe outage answers 5xx, so AffiWave retries;
- events older than the last applied one are skipped; a repeated event changes nothing. Ordering uses the event
  `created_at` (one-second resolution): two *different* states of one coupon that carry the same timestamp are applied in
  arrival order, so the later delivery wins. Identical states are no-ops, and the `affiwave:coupons:sync` fallback
  converges on the state AffiWave holds;
- two deliveries of the same **new** coupon at once: with `symfony/lock` (a `lock.factory` service, i.e. `framework.lock`
  configured) they are serialised per coupon; without it the mapping row is inserted before Stripe is called and the loser
  of the unique constraint answers 200 `result: in_progress`. Stripe create calls carry an idempotency key
  (coupon id + conditions + attempt number, so a retry after a Stripe error is not served the stored error). With the
  lock-free path the Doctrine entity manager of the losing request is closed by the constraint violation, which is fine
  for a webhook request but means a cron run should not overlap a webhook for the same new coupon.

The webhook answers: `400` bad signature or body, `503` no secret configured, `500` failure worth retrying, `200`
accepted (other event types are acknowledged and ignored).

Listen to `AffiWave\Bundle\Coupon\CouponChangedEvent` (dispatched before the Stripe sync, with or without
`coupons.enabled`) to keep coupons somewhere else too. To use your own Stripe client instead of the built-in gateway,
implement `AffiWave\Bundle\Stripe\StripeGatewayInterface` and alias it.

`DiscountCodeResolver::couponCodeFromInvoice()` / `couponCodeFromSession()` return the AffiWave code behind a payment's
discount (only codes that come from AffiWave; a coupon you made by hand is never reported as one). Webhook payloads carry
discount ids only: those are resolved from `affiwave_coupon` first and through the gateway when needed.

### 4. Cron

```cron
*/10 * * * *  php bin/console affiwave:conversions:retry --limit=100
17 * * * *    php bin/console affiwave:coupons:sync --since="-2 days"
```

- `affiwave:conversions:retry [--limit=100] [--dry-run]` re-sends `pending` and `failed` conversions, the least recently
  attempted first, so old stuck rows cannot starve new ones (AffiWave deduplicates by `external_order_id`).
- `affiwave:coupons:sync [--since=...|--all]` reads `GET /api/coupon_codes?changedSince=` (50 per page; API key scope
  `reports:read`) and feeds the same path as the webhook - the fallback for missed webhooks (AffiWave disables an endpoint
  after a run of failures). The API key is bound to the programme, so the sync only sees that programme's coupons.

## Setup on the AffiWave side

1. In the programme, set the **attribution parameter** to `aw_click` (the same as `click.query_parameter`) and the cookie
   duration to `click.cookie_days`; add your application's domain to the **accepted domains**.
2. Create an **API key** for the programme with the `conversions:write` scope (add `reports:read` for `affiwave:coupons:sync`) and put it in `AFFIWAVE_API_KEY`.
3. Create a **webhook** to `https://your-app.example/affiwave/webhook` with the `coupon.created` and `coupon.updated`
   events; copy the signing secret (`whsec_...`, shown once) to `AFFIWAVE_WEBHOOK_SECRET`.
4. Your landing pages must forward `?aw_click=` to the application (or the application itself must be the landing).

The webhook is signed like Stripe's: `X-AffiWave-Signature: t=<ts>,v1=<hex hmac_sha256(secret, "<t>.<raw body>")>`,
5 minute tolerance.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse src --level 6
```

Tests use an in-memory SQLite database, `MockHttpClient` and a fake Stripe gateway - no network.

## License

MIT
