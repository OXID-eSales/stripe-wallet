# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
