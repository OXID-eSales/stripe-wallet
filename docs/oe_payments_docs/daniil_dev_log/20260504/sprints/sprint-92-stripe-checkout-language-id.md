# Sprint 92: Stripe Checkout — Preserve User-Selected Language in Return/Cancel URLs

**Date:** 2026-05-04
**Branch:** `b-7.4.x`
**Ticket:** STRP-127

## Core Requirements

| Principle | Application |
|-----------|-------------|
| **TDD-first** | Failing tests first: language id appears in success/cancel URLs and reflects the active session language |
| **DevOps-first** | Pre-commit must pass (PHPCS, PHPStan max, PHPMD, PHPUnit Unit + Integration) |
| **SOLID / SRP** | Language resolution is a single responsibility — extracted into its own service, not inlined in handlers |
| **SOLID / DIP** | `StripePaymentHandler` depends on `LanguageResolverInterface`, not on `Registry::getLang()` directly |
| **LSP** | OXID implementation honors interface contract — always returns a non-negative `int`, never throws on missing session |
| **DRY** | Single source of truth for "active language id". Both Path A (legacy controller) and Path B (OPC handler) use the same service |
| **No overengineering** | We do **not** add a frontend lang forward (data attribute → JSON body → request param) yet. We add it only if QA shows `getBaseLanguage()` returning 0 in legitimate AJAX calls |

## Problem

The user's selected storefront language is lost after Stripe Checkout. When a customer browsing in EN (or any non-default language) completes or cancels payment on Stripe's hosted page, they return to the shop on the **default** language, not the language they were on.

### Root Cause — Two Code Paths, Only One Carries `languageId`

**Path A — `StripeOrderController::checkoutAjax`** (legacy, dispatches `StripeCheckoutSessionRequestEvent`)

- `src/Stripe/Controller/StripeOrderController.php:201` puts `'languageId' => $helper->getActiveLanguageId()` into the `EventContext`.
- `src/Stripe/Controller/ControllerRequestHelper.php:119` returns `(int) Registry::getLang()->getBaseLanguage()`.
- `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php:144-150` reads `languageId` from context and appends `&lang=N` to **both** success and cancel URLs.
- ✅ Language flows through.

**Path B — OPC footer → `OeCheckoutApi&fnc=processCheckout` → `StripePaymentHandler::processPayment`** (the production flow used by the Stimulus footer widget)

- `views/twig/widget/checkout/stripe-footer.html.twig:115-123` calls `fetch('/index.php?cl=OeCheckoutApi&fnc=processCheckout', …)` — **no `lang` param, no language in body**.
- `src/Stripe/PaymentHandler/StripePaymentHandler.php:209-241` builds URLs **without** a language argument:
  ```php
  $successUrl = $this->checkoutSessionService->buildSuccessUrl(
      $shopUrl, $contractId, $contractToken, $sessionId
      // no $languageId arg — defaults to 0
  );
  $cancelUrl = $this->checkoutSessionService->buildCancelUrl($shopUrl . 'index.php?cl=payment');
  // cancelUrl has no `&lang=` at all
  ```
- `src/Stripe/Service/CheckoutSessionService.php:211-218` declares `int $languageId = 0` — defaulting produces `&lang=0` in the URL.
- ❌ User always returns on language id 0 (shop default).

### Why `lang=0` Lands the User on the Default Language

In OXID, language id `0` is the shop's first/default language. Even if the user selected EN (id 1), the success URL forces them back to id 0. The cancel URL has no `lang=` parameter at all, so OXID resolves it via session/cookie — but in many setups this also collapses to default.

## Implementation Plan

### What Changes

| Action | File | Change |
|--------|------|--------|
| ADD | `src/Stripe/Service/LanguageResolverInterface.php` | New Stripe-local interface — `getActiveLanguageId(): int` |
| ADD | `src/Stripe/Service/OxidLanguageResolver.php` | OXID implementation reading `Registry::getLang()->getBaseLanguage()` |
| MODIFY | `services.yaml` | Register `OxidLanguageResolver` and bind to `LanguageResolverInterface` |
| MODIFY | `src/Stripe/PaymentHandler/StripePaymentHandler.php` | Inject `LanguageResolverInterface`; pass language id to `buildSuccessUrl`; append `&lang=N&shp=N` to cancel URL |
| MODIFY | `src/Stripe/Controller/ControllerRequestHelper.php` | Delegate `getActiveLanguageId()` to the resolver (DRY — one source of truth) |
| ADD | `tests/Unit/Stripe/Service/OxidLanguageResolverTest.php` | Unit tests for resolver |
| ADD | `tests/Unit/Stripe/PaymentHandler/StripePaymentHandlerLanguageTest.php` | Asserts language id propagates into both URLs |
| MODIFY | `tests/Unit/Stripe/Controller/ControllerRequestHelperTest.php` | Adjust to new dependency (resolver) |

### What Does NOT Change

- `CheckoutSessionService::buildSuccessUrl` signature — keep `int $languageId = 0` default to preserve backward-compat for any external callers.
- `CheckoutSessionService::buildCancelUrl` signature — no breaking change. Caller assembles the lang-aware base URL before passing in.
- `ShopAdapterInterface` (vendor `payment-component`) — **not modified**. We deliberately do not pollute the shared interface with OXID-specific session-language semantics. Language resolution stays Stripe-local.
- Frontend (`stripe-footer.html.twig`) — not changed in this sprint. The fetch URL stays as-is. We rely on the OXID session cookie carrying the active language, which is the OXID-default behavior.
- `StripeCheckoutSessionHandler` — already correct on Path A; no change.
- `StripeOrderController` — language passed via `ControllerRequestHelper` continues to work; the helper's internal implementation is what swaps to the resolver.

### Why a New Interface and Not a Method on `ShopAdapterInterface`

`ShopAdapterInterface` is owned by `oxid-esales/payment-component` (a separate provider-agnostic package). Adding a method there would (1) require a coupled change in that package, (2) push OXID-specific session-language semantics onto a vendor-neutral contract, and (3) force every other PSP module to implement it. Keeping the resolver Stripe-local respects ISP and avoids cross-package churn.

### Why Not Frontend-Forwarded Language

The cleanest fix is server-side because the AJAX request to `/index.php?cl=OeCheckoutApi&fnc=processCheckout` is same-origin and carries the OXID session cookie — `Registry::getLang()->getBaseLanguage()` reads from session/cookie/request and should already return the user's active language. Adding a frontend round-trip is duplication unless QA proves session resolution is broken in this AJAX context. **No overengineering** — defer until evidence shows it's needed.

## TDD Plan

### Phase 1: RED — Failing Tests

**Unit: `OxidLanguageResolverTest`**
```
Test: testReturnsBaseLanguageIdAsInt()
  Arrange: stub Registry::getLang() to return base language id 1
  Act: $resolver->getActiveLanguageId()
  Assert: === 1

Test: testReturnsZeroWhenLanguageNotSet()
  Arrange: stub Registry::getLang() to return base language id 0 (shop default)
  Act: $resolver->getActiveLanguageId()
  Assert: === 0  // valid, "default language" is a real value
```

**Unit: `StripePaymentHandlerLanguageTest`** (new)
```
Test: testSuccessUrlContainsActiveLanguageId()
  Arrange: LanguageResolver returns 1, build a contract via mocked services
  Act: trigger processPayment / createCheckoutSession
  Assert: CheckoutSessionService::buildSuccessUrl was called with $languageId === 1

Test: testCancelUrlContainsActiveLanguageId()
  Arrange: LanguageResolver returns 1
  Act: trigger processPayment
  Assert: cancelUrl passed to CheckoutSessionService contains 'lang=1'
            AND contains 'shp=' with the active shop id

Test: testFallsBackToZeroWhenResolverReturnsZero()
  Arrange: LanguageResolver returns 0
  Act: trigger processPayment
  Assert: success and cancel URLs contain 'lang=0' (no exception, no breakage)
```

**Unit: `ControllerRequestHelperTest`** (modify existing)
```
Test: testGetActiveLanguageIdDelegatesToResolver()
  Arrange: helper constructed with LanguageResolver returning 2
  Act: $helper->getActiveLanguageId()
  Assert: === 2  AND  Registry is NOT touched
```

### Phase 2: GREEN — Implementation

1. Add `LanguageResolverInterface` with `getActiveLanguageId(): int`.
2. Add `OxidLanguageResolver` implementing it via `(int) Registry::getLang()->getBaseLanguage()`.
3. Register the resolver in `services.yaml` and bind the interface.
4. Refactor `ControllerRequestHelper` to take `LanguageResolverInterface` via constructor; `getActiveLanguageId()` delegates.
5. Refactor `StripePaymentHandler::__construct` to take `LanguageResolverInterface`. In `createCheckoutSession()`:
   - resolve `$languageId = $this->languageResolver->getActiveLanguageId();`
   - resolve `$shopId = (int) $this->shopAdapter->getShopId();`
   - pass both to `buildSuccessUrl(...)`.
   - build cancel URL as `"$shopUrl" . "index.php?cl=payment&lang=$languageId&shp=$shopId"` and pass to `buildCancelUrl(...)`.
6. Update `services.yaml` with the new constructor argument for `StripePaymentHandler` and `ControllerRequestHelper`.

### Phase 3: REFACTOR

- Run `./bin/pre-commit-check.sh` — must pass.
- Manual smoke test (documented in QA section below).
- Confirm no other call site of `buildSuccessUrl` was broken (the default `int $languageId = 0` is preserved for safety).

## Definition of Done

- [ ] All new and modified unit tests pass; pre-commit clean.
- [ ] Manual smoke test: switch storefront to EN, perform Stripe checkout, complete payment → returns on EN. Repeat with cancel → returns on EN.
- [ ] Repeat smoke test with DE (default) → behaves as before (`lang=0`).
- [ ] No regression on Path A (`StripeOrderController::checkoutAjax`).
- [ ] PHPStan max passes; no new baseline entries.
- [ ] Code review confirms `Registry` is referenced **only** inside `OxidLanguageResolver` (not in the handler).

## QA Smoke Test Steps

1. Open shop in incognito → switch language to EN → add product → checkout to payment step.
2. Pick Stripe payment, click Pay → redirected to Stripe Checkout.
3. Complete payment with Stripe test card `4242 4242 4242 4242`.
4. **Expected:** return URL contains `lang=1` (or whichever id EN has) and the OXID success page renders in EN.
5. Repeat with cancel: on the Stripe page, click "← Back" instead.
6. **Expected:** return URL contains `lang=1` and OXID payment page renders in EN.
7. Switch storefront to DE; repeat 1–6 → URLs carry `lang=0`, pages render in DE.

## Sub-Sprints

| Sprint | Description | Status |
|--------|-------------|--------|
| 92a | RED — Resolver + handler + helper tests written, all failing | todo |
| 92b | GREEN — `LanguageResolverInterface` + `OxidLanguageResolver` + DI wiring | todo |
| 92c | GREEN — Refactor `ControllerRequestHelper` and `StripePaymentHandler` | todo |
| 92d | REFACTOR — Pre-commit + manual QA on EN and DE | todo |

## Out of Scope

- Frontend forwarding of language id from the Stimulus footer to `processCheckout` (deferred until evidence shows session-based resolution fails).
- Adding `getActiveLanguageId` to the vendor `ShopAdapterInterface` (cross-package concern, not justified).
- Currency-by-language switching at return time (separate concern).
- SEO-URL (`/en/...`) handling on return — out of scope; `lang=N` query param is sufficient for OXID to set the right language.
- Refactoring `CheckoutSessionService::buildCancelUrl` to accept structured parameters (would be a larger API change; the caller currently composes the URL).

## Risk & Rollback

- **Risk:** New constructor arguments on `StripePaymentHandler` and `ControllerRequestHelper` could break custom DI wiring in downstream installations. **Mitigation:** services.yaml is updated atomically; the new param is required only via DI, not a public API for end users.
- **Rollback:** Revert the sprint commit. The default `int $languageId = 0` in `CheckoutSessionService::buildSuccessUrl` ensures the pre-sprint behavior is preserved if the new wiring is removed.

## Decision Record

**Decision:** Resolve active language id server-side via a Stripe-local `LanguageResolverInterface`, injected into both `StripePaymentHandler` (Path B) and `ControllerRequestHelper` (Path A).

**Rejected alternatives:**
- *Inline `Registry::getLang()->getBaseLanguage()` in the handler* — violates DIP, untestable without OXID bootstrap.
- *Add the method to `ShopAdapterInterface`* — leaks OXID semantics into the vendor-neutral payment-component package; breaks ISP for other PSP modules.
- *Forward language from frontend in the JSON body* — duplicates session state already in the cookie; no evidence it's needed; rejected by "no overengineering".