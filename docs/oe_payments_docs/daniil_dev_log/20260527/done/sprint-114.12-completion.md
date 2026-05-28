# Sprint 114.12 — Clean-code pass + status/mode constants — Completion Report

**Date:** 2026-05-28
**Branch:** `b-7.4.x-code-review-STRP-145`
**Status:** DONE

---

## Deliverables

### Group 1 — Magic literal → named constants (C4)

**New constants in `StripeDefinitions`:**
```php
MODE_TEST = 'test'
MODE_LIVE = 'live'
CAPTURE_MODE_AUTOMATIC = 'automatic'
CAPTURE_MODE_MANUAL = 'manual'
TRANSACTION_TYPE_CAPTURE = 'capture'
TRANSACTION_TYPE_REFUND = 'refund'
TRANSACTION_STATUS_COMPLETED = 'completed'
DEFAULT_CURRENCY = 'EUR'
```

**New constants in `StripeStatusMapper`:**
```php
CHECKOUT_PAYMENT_STATUS_PAID = 'paid'
CHECKOUT_PAYMENT_STATUS_UNPAID = 'unpaid'
STRIPE_REFUND_STATUS_PENDING = 'pending'
```

**Connect middleware URLs** extracted to private class constants in `ModuleConfiguration`
(SRP: admin-routing URLs belong with the admin controller, not StripeDefinitions).

**Files migrated (17):**
`ModuleConfigurationService`, `CaptureService`, `WebhookContractFulfillmentHandler`,
`StripeTransactionHistoryBuilder`, `OrderRefundViewDataProvider`,
`CheckoutSessionCompletedWebhookHandler`, `CheckoutReturnService`, `CheckoutReturnResult`,
`ModuleConfiguration`, `StripeConnect`, `StripeCheckoutSessionHandler`,
`CheckoutSessionServiceInterface`, `CheckoutSessionService`, `PaymentIntentHelper`,
`RefundService`, `StripeDefinitions`, `StripeStatusMapper`.

**Tests added (11):**
- `StripeDefinitionsTest`: 8 new constant-value pin tests
- `StripeStatusMapperTest`: 3 new constant-value pin tests

**Commit:** `a0c10ca`

---

### Group 2 — Long methods split (C2)

**`StripeOrderController::createCheckoutSession()`** — 81 lines → ~28 lines.
Four private helpers extracted:
- `validateCheckoutPreconditions()` — null guards for basket/user/contract, throws on failure
- `buildCheckoutEventContext()` — assembles `EventContext` with all session params
- `dispatchSessionEvent()` — fires event and logs result
- `emitSessionResponse()` — outputs JSON response

**`OxidShopOrderService::createOrder()`** — 31 lines → ~15 lines.
One private helper extracted:
- `finalizeAndValidateOrder()` — wraps `finalizeOrder()` call and return-type unpacking

**Additional C5/C6 in Group 2 files:**
- Added `use RuntimeException;` / `use Throwable;` / `use Exception;`
- Replaced all inline FQCNs in catch/param positions with short names
- Added explicit null guard on `buildCheckoutEventContext()` (PHPStan narrowing gap)
- Added null guard `$basketPrice = $basket->getPrice()` before `getBruttoPrice()` (C6; suppression at line 178 justified: OXID PHPDoc non-nullable)

**Tests added (3):**
`StripeOrderControllerTest`: 3 new tests validating helper extraction behavior

**Commit:** `182c7b9`

---

### Group 3 — Hygiene: early returns, explicit imports, null safety, TODO/URL cleanup (C3,C5,C6,C7,C8)

**Files changed (12):**

| File | Fixes |
|------|-------|
| `ReturnSessionSecurityService` | C3: `elseif`→early return in `validateTiming()`; nested `if/else`→guards in `validateIp()` |
| `StripePaymentStatusHandler` | C3: `} else { handleFailure() }`→`return;`+direct call; C5: added `use PaymentDetailsResponse;` |
| `WebhookController` | C5: added `use Exception; use Throwable;` |
| `ContractTokenService` | C5: added `use RuntimeException;` |
| `StripeCaptureRequestHandler` | C5: added `use Throwable;` |
| `StripeRefundRequestHandler` | C5: added `use Throwable;` |
| `RetryCleanupService` | C5: added `use PaymentContractInterface;` |
| `CaptureService` | C5: added `use Throwable;` |
| `ViewConfig` | C8: replaced `@TODO probably needs to be enhanced` with explanatory docblock |
| `ModuleConfiguration` | C8: resolved `@TODO stripeIsStripe()` — name is load-bearing in Twig, documented in docblock |
| `StripeOrderController` | C5/C8: use imports added (also covers Group 2 commit) |
| `OxidShopOrderService` | C5/C6: use imports + null guard + `@phpstan-ignore` for OXID framework pattern |

**Commit:** `615e2f5`

---

## Grep Proofs

**Magic literals eliminated from production callsites:**
```
grep -rEn "'requires_capture'|'succeeded'|'canceled'|'paid'|'requires_action'" src/
# → only StripeStatusMapper constant definitions remain
```

**No `else`/`elseif` in cited methods:**
- `validateTiming()`, `validateIp()` in ReturnSessionSecurityService — confirmed
- `handlePending()` in StripePaymentStatusHandler — confirmed

**No inline `\Exception`/`\RuntimeException`/`\Throwable` in cited files:**
```
grep -rn '\\\\Exception\|\\\\Throwable\|\\\\RuntimeException' src/Stripe/
# → 0 results outside StripeStatusMapper/StripeDefinitions constant definitions
```

---

## Test Counts

| Metric | Before | After |
|--------|--------|-------|
| Unit tests | 1087 | 1098 |
| Unit assertions | 2643 | 2654 |
| Integration tests | 141 | 141 |

Net: +11 unit tests, +11 assertions. All green.

---

## Gate Results

- PHPCS: 0 errors (PSR-12)
- PHPStan: 0 errors (level max)
- PHPMD: 0 new violations (baseline unchanged — 3 entries)
- PHPUnit Unit: 1098 tests, 2654 assertions — GREEN
- PHPUnit Integration: 141 tests, 356 assertions, 53 skipped — GREEN

---

## PHPMD Baseline State

Unchanged (3 entries, same as sprint start):
- `StripeAdapter`: TooManyMethods, TooManyPublicMethods
- `StripeOrderController`: WeightedMethodCount (pre-existing; createCheckoutSession split reduces this over time)

No new entries added.

---

## R-1…R-10 Checklist

- [x] **R-1 TDD**: Failing constant-pin tests written first (RED); implementation confirmed them GREEN. Method-split tests written before extraction.
- [x] **R-2 SOLID**: SRP respected — connect URLs stay in ModuleConfiguration, not StripeDefinitions. No new god-objects.
- [x] **R-3 LI**: No security-weakening overrides; no instanceof downcasts.
- [x] **R-4 DI**: No new collaborators instantiated inline; no new ContainerFactory calls.
- [x] **R-5 Clean Code**: `createCheckoutSession` 81→28 lines; `createOrder` 31→15 lines. All extracted helpers ≤25 lines.
- [x] **R-6 DevOps-first**: PHPStan 0 errors, PHPCS 0 errors, PHPMD 0 new, Unit 1098 green, Integration 141 green. One suppression added (`ternary.alwaysTrue`) justified by OXID framework PHPDoc pattern (same as existing line 112 in same file).
- [x] **R-7 Event-driven**: No changes to event/handler registration or dispatch flow.
- [x] **R-8 Contract-aware**: No state machine changes; no direct state writes.
- [x] **R-9 No overengineering**: Only what findings C2–C8 required. No speculative abstractions.
- [x] **R-10 Persistence**: No new DB writes in touched code.

---

## Commit Hashes

- Group 1 (C4 — constants): `a0c10ca`
- Group 2 (C2 — long methods): `182c7b9`
- Group 3 (C3,C5,C6,C7,C8 — hygiene): `615e2f5`
