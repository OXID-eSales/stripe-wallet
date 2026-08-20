# Sprint 133 · Phase C — completion report

**Date:** 2026-08-20
**Scope delivered:** F18, F19, F20 (Stories 18-20). **Story 17 (F17) skipped by request.**
**Commits:** stripe `5fbafec` · payment-base `d18f2a8`
**CI:** green on 7.4 and 7.5 — 1509 unit tests (+10), 80 integration.

## What landed

**S18 (F18) — activation events stop hiding failures.** `deletePaymentMethod()`
swallowed every exception, so a failed cleanup left a removed payment method in
`oxpayments`, visible in admin, with no trace; it now logs. `onDeactivate()`'s
entire body sat behind `isAdmin()`, so the documented CLI path
(`oe-console oe:module:deactivate`) skipped the file-cache reset; the gate is gone.

`deactivatePaymentMethods()` was **deleted rather than implemented**, which is the
opposite of what the sprint assumed. Its empty body under an action-promising name
was the defect — but switching `oxactive` off there would have been worse:
`ensureStripePaymentMethods()` deliberately leaves `oxactive` untouched on
re-activation "to preserve admin changes", so the payment methods would have
stayed off after the next activate. Payment methods surviving deactivation is
intentional; that is now documented where the misleading call site used to be.

**S19 (F19) — a missing publishable key is reported, not just blank.** New
`PublishableKeyProvider` returns `null` instead of `''`, logs the missing key once
per request with the exact setting to fill (`sStripeTestPk` / `sStripeLivePk`),
and `ViewConfig` memoises the unavailable state and exposes
`isStripePaymentAvailable()` for templates. The footer/embedded widget now refuses
an empty key instead of handing it to `window.Stripe()`.

**S20 (F20) — naming trap documented, DTO given one canonical style.**
`getShopName()` no longer substitutes the literal `'OXID eShop'` for the
merchant's own name (it reaches Stripe as session branding); `ShopName::of()`
returns `''` and lets callers decide. On `FraudCheckResponse` the readonly
properties are declared canonical and the four getters that mirror a property 1:1
are deprecated with their replacement named; the behavioural accessors
(`isSuccessful()`, `isScreened()`) stay. Deprecation rather than removal because
payment-base v1.1.0 is tagged and published.

## Two more corrections to the review

5. **F19 was half wrong.** The review said an empty key "presents to the customer
   as a checkout that simply does not work" with no error. In fact
   `stripe_order_controller.js` *does* guard an empty key — it logs to console and
   shows a translated "Stripe configuration error. Please contact support."
   message. What was genuinely missing: **nothing was ever logged server-side**, so
   the merchant had no way to learn why checkout was dead; the failed container
   lookup was **retried on every template call** because the memo field stayed
   null; and the **embedded/footer path** did not guard, passing `''` into
   `window.Stripe()`.
6. **F20's "contradiction" was not one.** `ShopAdapterInterface::isTestMode()` is
   documented as the **shop's** test/development mode, and OXID's `blDebugMode` is
   a faithful implementation of that contract.
   `ModuleConfigurationServiceInterface::isTestMode()` is the **Stripe key** mode.
   Two legitimate contracts that share an unfortunate name — not a wrong
   implementation. Renaming the interface method would break the PayPal and Mollie
   adapters, which implement it too, so it belongs to a payment-base major
   version. Both sides now say so explicitly instead.

## Coverage limit worth stating

`Events` uses `Registry` and `DatabaseProvider` statically, so its behaviour is
exercised by the gated integration suite (`Integration/Module/ModuleLifecycleTest`,
group `requires-oxid-container`), not by unit tests. What the new unit test pins is
the *shape*: no method promising work it does not do, and no cache-clear hidden
behind an admin-only branch. The logging inside `deletePaymentMethod()` is not
unit-covered.

## Remaining

**F17 only** — the 776-line `StripeOrderController` extraction and its
service-location-instead-of-DI problem, skipped by request. `Controller/Admin/ModuleConfiguration.php`
(506 lines) has the same shape and is part of that story.
