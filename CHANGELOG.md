# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed
- **Manual capture and refund from the admin (CFG-1 S8, found by a Stripe test-mode run from the admin app).** An admin
  capture now records the captured amount on the contract (it wrote the transaction row and moved the state, but the
  contract kept 0.00 captured until a webhook that may never come), and an admin refund now writes its row to the
  transaction table with Stripe's refund id as reference (only the `charge.refunded` webhook wrote one, without the id), so
  the admin's Payment tab lists every capture and refund with its provider reference. After upgrading clear the container
  cache.

### Removed
- **CFG-1: no GraphQL path changes the module's settings any more** (they change in the classic admin only):
  `adminStripeConfigModify`, `adminStripeWebhookRegister` and `adminStripeWebhookClearAll`, with `AdminStripeConfigInput`,
  `AdminStripeConfigPayload`, `AdminStripeWebhookPayload`, `AdminStripeConfig.secretsEditable`, the modifier, change
  validator, audit and result classes, and the write side of the settings and webhook-state seams. Kept: `adminStripeConfig`,
  `adminStripeWebhookStatus`, `AdminPaymentContract.stripe` and payment-base's capture / refund / void. After upgrading:
  `bin/oe-console oe:graphql:cache-clear`.

### Added
- **Stripe admin API (PAY-3)** for `oxid-admin-graphql` (optional, see `docs/for_developer/06-admin-api.md`):
  `adminStripeConfig`, `adminStripeWebhookStatus` (read-only, see Removed), and `AdminPaymentContract.stripe` (payment intent, charge, status, dashboard link; fails
  closed when Stripe cannot be reached). Rights: `ADMIN_PAYMENT_CONFIG` / `ADMIN_PAYMENT_VIEW`.
- Provider wiring of payment-base's PAY-1 admin payment API: Stripe's column of the contract-state Help, the reasons and
  description the panel accepts, live capture/refund bounds, and readable refusals (`AdminActionRefusedException`) for the
  amount and text gate of the Payment tab; the classic tab behaves as before.
- **PAY-8:** `AdminStripeConfig.classicSettingsUrl` (read-only, no secret): the classic admin's settings page of this module,
  so the new admin can link to the Connect onboarding (`ModuleConfigurationServiceInterface::getClassicSettingsUrl()`).
- `ConfigurationValidator::getKeyPairError()` (`KeyPairValidatorInterface`).

### Requires
- payment-base with the PAY-1 provider API (next release after 1.5.0; until then CI pins payment-base's branch
  `b-7.4.x-admin-payment-plugins`) and, for the admin API itself, payment-base >= 1.5.

### Changed
- **Docs (138h).** The developer guide's security model (§7) states what the headless channel leaves behind and where
  it does not (hashed contract token, log policy, URLs, browser storage, errors by code, secret scanning); the merchant
  guide lists the log policy and the PayPal webhook id next to what stays in the shop.
- **Guest checkout over GraphQL (140b).** `stripeCheckoutStart`, `stripeCheckoutReturn` and `stripeCheckoutCancel` no longer
  carry `#[Logged]`: the right `PAYMENT_CHECKOUT` alone guards them, so an anonymous token with the right is accepted and one
  without it is refused (logged-in behaviour unchanged). `stripeCheckoutStart` gains the optional argument
  `guest: GuestCheckoutInput` (`email`, `invoiceAddress: GuestAddressInput!`, `deliveryAddress: GuestAddressInput`), the
  input type is payment-base's (`GuestCheckoutInputFactory`, defined once for all provider modules); a refusal of the
  details (`guest_details_required`) surfaces as payment-base's `GuestDetailsRefused`, `account_exists` as
  `StripeCheckoutError`, both with `extensions.errorCode`. The payment
  needs no argument: the mutation already names Stripe itself. Return and cancel stay authorised by the contract token
  alone. `bin/graph-ql-cli-test.sh guest` runs the guest start. Known gap (payment-base 140a): the order ships to the
  invoice address, a basket's delivery address is not applied. The input types moved into payment-base (this module's own
  copy, `GuestCheckoutInputFactory`, is removed; `NamespaceMapper` maps no type namespace any more): the same type names
  defined in two active provider modules would clash, so there is exactly one definition.

### Documentation
- `oe:graphql:cache-clear` after activating or updating the module: setup step and troubleshooting row for
  `Cannot query field "…"` in the merchant guide `02-headless-storefronts.md`, §1 and §11 of the headless developer guide,
  `bin/graph-ql-cli-test.md`; `bin/graph-ql-cli-test.sh` clears the schema cache before its first query
  (`CACHE_CLEAR_CMD`, `SKIP_CACHE_CLEAR=1`); the three `stripe-tests-oxid7.*.yml` workflows run it after the module
  activation.

- Developer guide `05-headless-graphql-checkout.md`: §13 "How this relates to the OXID third-party-payments models"
  (standard / express / own workflow table, why `placeOrder` is refused for our baskets, basket removal on contract
  commit instead of `BeforeBasketRemoveOnPlaceOrder`) and §14 "Versions" (graphql-base 12.x / storefront 4.x; what to check
  before 13 / 5), each statement checked against the code with the class named. The merchant README indexes the headless
  and security-header guides; two dead links there are fixed.

### Security
- Provider failures no longer put provider text into the GraphQL error (and so into `oxideshop.log`): the handler
  answers fixed messages (`Failed to create Stripe checkout session`, `Stripe payment processing failed`),
  `StripeCheckoutError` answers "Stripe could not start the checkout" for `provider_failed`; Stripe's own text goes to the
  logger at `debug` with the contract id, the `error` log line carries the exception class only.

### Fixed
- Stripe Checkout Sessions (hosted and embedded) are created in the shop's active language
  (`locale`, via the new `StripeLocale` and `LanguageResolverInterface::getActiveLanguageAbbreviation()`).
  Without a locale Stripe followed the BROWSER's language, so a German storefront showed an English
  visitor an English payment form. Languages Stripe does not offer fall back to `auto`.
- A Stripe payment confirmed by `payment_intent.succeeded` (or `checkout.session.completed`) now records the
  contract's captured amount (`amount_received`, falling back to `amount`) and writes one `capture` audit row.
  Before, nothing on the webhook path set the captured amount, so the audit row was skipped
  (`Capture audit row skipped: contract has no captured amount`) and refund bounds saw nothing captured.
  A manual capture's own `capture` row is never duplicated. `WebhookContractFulfillmentHandlerInterface::handlePaymentSucceeded()`
  gains an optional trailing `?float $amountReceived`.
### Added
- `stripe:backfill-captured-amount [--apply]`: records the captured amount and the capture audit row on contracts that
  were fulfilled before the fix above. Dry run by default; `--apply` reads each PaymentIntent from Stripe and writes.
  Supported by `Service\ContractCaptureRecorder`, `StripePaymentIntentDto::$amountReceived` and
  `Service\CapturedAmountBackfillService`.
- Sealed client config provider (`ClientConfig\StripeClientConfigProvider`, tag `payment_base.client_config_provider`):
  exposes only the publishable key of the current mode (or nothing when unset or not a `pk_` key) to payment-base's
  `paymentClientConfig` query. Requires a payment-base release that ships `ClientConfigProviderInterface`.
  The provider also implements `AllowListedClientConfigProviderInterface` (payment-base 1.4.0+) and declares
  `publishableKey` as its only value name, so the guard refuses any other name whatever its value looks like.
- Headless checkout over GraphQL. See docs for developers
  (`docs/for_developer/05-headless-graphql-checkout.md`): the `stripeCheckoutStart` / `stripeCheckoutReturn` /
  `stripeCheckoutCancel` API with `uiMode` `hosted` | `embedded`, the complete call sequence of a checkout as the
  reference storefront (oxid-fe-user) performs it, the sealed publishable-key exchange (`paymentClientConfig`:
  ECDH P-256 + HKDF-SHA256 + AES-256-GCM), the webhook as the step that finishes the order, rights and contract
  token, the return-origin allow-list (`sPaymentBaseHeadlessReturnOrigins`), the error codes, and how to build
  another frontend or add another provider. For merchants (`docs/for_merchant/02-headless-storefronts.md`): what a
  headless storefront gets, embedded vs hosted presentation, the setup checklist, troubleshooting, limits.
  Corrects two older statements along the way: `cancelUrl` and `checkoutSessionId` are optional (not `String!`),
  and Stripe appends only `session_id` to the return URL.


## [v3.4.1] - 2026-10-09

### Fixed
- PaymentBase dependancy version

## [v3.4.0] - 2026-10-09

### Added
- A "Help" group at the end of the module Settings tab (MOL-10): payment-base's contract-state table
  (OXID Contract Status · Meaning) with Stripe's third column, the PaymentIntent status each state corresponds to
  (`Admin\StripeContractStateHelp`). The admin order Payment tab shows an "OXID Contract Status" row with a "?" that
  opens payment-base's Help layer (description + three-column table). Requires the matching payment-base version.
- Headless functionality

### Fixed
- Iframe mode painted the Place-Order button instead of the embedded Stripe sheet in every fresh
  browser session (no persisted Terms consent yet). The button is now never the shopper's control in
  iframe mode: the sheet mounts on load when consent is not gated, and the moment the Terms checkbox
  is ticked otherwise (Sprint 137, IFRAME-05).
- With the payment-base "skip" flags on and exactly one payment method and one delivery set, the
  Stripe order page dropped the shipping card entirely and so never named the carrier. It now shows
  the shipping heading and carrier name without the edit pencil, and leaves the payment card out,
  exactly as payment-base's own order template does for non-Stripe orders.
- A Content-Security-Policy `<meta>` rendered inside `<body>` was ignored by browsers and logged an
  error on every Stripe-active page. Removed; CSP belongs in the shop's HTTP headers.

## [v3.3.0] - 2026-09-15

Note: the `v3.2.0` tag sits on a commit that is not part of any branch. Its content is in
`b-7.4.x` through an equivalent commit, so this release continues from there.

### Added
- Stripe Embedded Checkout: the footer widget and the standard order page mount the payment sheet
  inline when payment-base runs in iframe mode, with a page-global singleton and serialised
  mounting so a page can only ever hold one sheet.
- The module declares its iframe UI topology to the One-Page Checkout instead of carrying the
  `sPaymentHandlerUiTopology` setting.
- The single-payment and single-shipping decisions from payment-base 1.2 are honoured in this
  module's own order block and order template.

### Changed
- Idempotency is keyed by request and uses Stripe's native keys, with a required repository and a
  lock reaper; refund idempotency no longer keys by payment.
- Webhook guards fail closed instead of warning and continuing, the webhook secret is scoped per
  mode and the shop id is required.
- One currency resolver replaces the hardcoded `EUR` assumptions, and an unknown amount is
  reported as unknown instead of as zero.
- Radar reports the score it actually has instead of a forged clean one, and `confirmPayment`
  asks Stripe instead of always answering yes.
- Silent handler exits are logged.

### Fixed
- One checkout creates one Stripe session; a retried attempt retires the previous one.
- The checkout return no longer refuses a paid return and explains itself when it does refuse; the
  ownership check survives an absent user.
- `ShopOrderServiceInterface` is no longer bound to this module's adapter.
- The checkout footer shows a translated label and reads the failure key the OPC actually sends.

### Changed (branding)
- The admin module logo is the shared OXID module logo (`assets/img/logo.png`, the same file
  payment-base and the One-Page Checkout carry) instead of the Stripe wordmark. `stripe_logo.png`
  was removed — it was referenced nowhere but in the `thumbnail` entry, so no frontend output
  changes.

### Fixed (found by the standalone unit suite)
- `ShopCurrency::nameOrEmpty()` accepted `?object` while
  `Config::getActShopCurrencyObject()` ends in `reset($currencies)` — which returns `false` on a
  shop with no currency row. That path is display-only and explicitly must not break the page, so
  it now takes `object|false|null`.

### Changed (tests)
- The unit suite runs standalone: `tests/phpunit-unit.xml` with `tests/bootstrap-unit.php`, which
  boots composer's autoloader plus the few things only an activated module would otherwise
  provide (the `*_parent` classes, the shop's global functions, an in-memory `Config`). No shop,
  no database, 1578 tests. The CI job now uses the module's own PHPUnit instead of borrowing the
  shop's binary, and the test namespace moved from `autoload` to `autoload-dev`.
- `testGetCheckoutDataDefaultsCurrencyToEurWhenMissing` promised a default of `EUR` that
  `resolveCurrency()` deliberately does not give ("Never a hardcoded 'EUR', which mislabels the
  checkout footer on any non-EUR shop"). It only passed because the suite ran against a shop whose
  currency happened to be EUR; it now asserts the documented behaviour and is named accordingly.
- The workflows no longer pin payment-base with an explicit `as <version>` alias. payment-base
  declares `extra.branch-alias`, so the branch under test satisfies `>=v1.2` by itself and the
  workflows stop going stale on every payment-base release.

### Packaging
- `composer.json` prepared for publication: `oxid-esales/payment-base` raised to `>=v1.2` — the
  module uses `VoucherReleaseInterface`, which 1.1 does not have; the bound stays open upwards so
  a later release can raise the floor without a constraint rewrite — `doctrine/dbal` widened to
  `^2.13 || ^3.0`, and the undeclared runtime dependencies `ext-json`, `ext-mbstring`, `psr/log`
  and `symfony/console` added. `authors` and `support` added, and the unused `symfony/filesystem` and
  `mikey179/vfsstream` dropped from require-dev. The test namespace deliberately stays in the
  production `autoload` section: CI runs the unit suite through the shop's autoloader, which does
  not read a dependency's `autoload-dev`. `tests/` is kept out of the package by `.gitattributes`
  instead.
- `package.json` is marked `private`, names OXID eSales AG as author and carries
  `SEE LICENSE IN LICENSE` instead of the incorrect `MIT`.
- `composer.lock` and the generated `var/` shop configuration are no longer tracked — a module is
  a library, and shop state is not module source.
- `.gitattributes`: tests (including the 62 MB load-test suite), the generated HTML documentation
  browser, the frontend build chain, CI workflows, the development recipe and developer scripts
  are no longer part of the composer package.

## [v3.2.0] - 2026-08-11

Added Payment Base iframe feature support 

## [v3.1-rc.1] - 2026-07-02

Initial release of Stripe Payment Gateway for OXID eShop 7.4. Based on PaymentBase extension, providing seamless integration with Stripe's API for processing payments, refunds, and handling webhooks. This release includes support for one-time payments, subscriptions, and basic error handling. Future updates will focus on expanding features and improving performance.
