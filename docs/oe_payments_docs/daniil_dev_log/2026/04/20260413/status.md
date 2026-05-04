# Status — 2026-04-13

## Sprint 83: STRP-119 Transaction History Table on Admin Order Detail Page

### Objective

Add a transaction history table to the Stripe tab on the admin order detail page, showing all `oe_payments_transaction` records for the current contract/order. Displayed after the Payment Details fieldset.

### Core Principles Applied

| Principle | How It Applies |
|-----------|---------------|
| **TDD-first** | Sprint 83a writes all failing tests before any production code |
| **DevOps-first** | Sprint 83d validates full pre-commit pipeline (PHPCS, PHPStan, PHPMD, PHPUnit) |
| **SOLID / SRP** | ViewDataProvider fetches data, Controller delegates, Template renders — each has one job |
| **DRY** | Reuses `TransactionRepositoryInterface::findByContractId()` from payment-component — no new queries |
| **Liskov** | `TransactionRepositoryInterface` injected, not `DoctrineTransactionRepository` — any implementation works |
| **DI** | `TransactionRepositoryInterface` injected via constructor in ViewDataProvider, wired in services.yaml |
| **Clean Code** | Early returns, no else, thin controller method (~5 lines), meaningful names |
| **No overengineering** | No pagination, no filtering, no sorting controls — just a static table. No new repo methods. |

### Sub-Sprint Progress

| Sprint | Description | Status | Notes |
|--------|-------------|--------|-------|
| 83a | RED — Write failing unit tests (5 tests) | done | 3 ViewDataProvider + 2 TransactionHistory tests — all RED confirmed |
| 83b | GREEN — Backend (ViewDataProvider + Controller + DI) | done | 5/5 tests GREEN, 814 unit tests pass, 0 failures |
| 83c | GREEN — Frontend (Twig template + EN/DE translations) | done | Template + 8 EN keys + 8 DE keys added |
| 83d | REFACTOR — Pre-commit validation + completion report | done | 972 tests, 0 PHPStan errors, 0 PHPMD violations, COMMITABLE |

### Files Changed

| Action | File | Sprint |
|--------|------|--------|
| CREATE | `tests/Unit/Stripe/Controller/Admin/OrderRefundViewDataProviderTest.php` | 83a |
| CREATE | `tests/Unit/Stripe/Controller/Admin/OrderRefundTransactionHistoryTest.php` | 83a |
| MODIFY | `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | 83b |
| MODIFY | `src/Stripe/Controller/Admin/OrderRefund.php` | 83b |
| MODIFY | `services.yaml` | 83b |
| MODIFY | `views/twig/admin/stripe_order_refund.html.twig` | 83c |
| MODIFY | `views/admin_twig/en/stripe_lang.php` | 83c |
| MODIFY | `views/admin_twig/de/stripe_lang.php` | 83c |

### Test Baseline (Sprint 83)

- **Before:** 972 tests (unit+integration), 2604 assertions
- **After:** +5 new tests (3 ViewDataProvider + 2 TransactionHistory), +16 assertions
- **Final:** 972 tests, 2604 assertions, 0 failures — COMMITABLE

---

## Sprint 84: Transaction Recording in Authorization and Capture Flows

### Objective

Fix empty transaction history by adding `transactionRepository->save()` calls to authorization (checkout return) and capture flows. Previously only refund flow recorded transactions.

### Root Cause

`TransactionRepositoryInterface::save()` was never called during:
- Checkout return (authorization) — `StripeCheckoutReturnHandler`
- Payment capture — `CaptureService`

Only `logRefund()` was called during refunds (via `AbstractPaymentRefundService`).

### Changes

| Action | File | Change |
|--------|------|--------|
| MODIFY | `src/Stripe/Service/CaptureService.php` | +`TransactionRepositoryInterface` dependency, +`recordCaptureTransaction()` |
| MODIFY | `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` | +`TransactionRepositoryInterface` dependency, +`recordAuthorizationTransaction()` |
| MODIFY | `services.yaml` | Wire `$transactionRepository` to CaptureService + CheckoutReturnHandler |
| MODIFY | `tests/Unit/Stripe/Service/CaptureServiceTest.php` | +2 tests for transaction recording |
| MODIFY | `tests/Unit/.../StripeCheckoutReturnHandlerTest.php` | Updated constructor for new dependency |
| MODIFY | `tests/Unit/.../AddressHashRestorationTest.php` | Updated constructor for new dependency |
| MODIFY | `tests/Integration/.../ManualCaptureIntegrationTest.php` | Updated constructor for new dependency |

### Status: done

- All checks passed — COMMITABLE
- +2 new unit tests for capture transaction recording
- 0 PHPCS errors, 0 PHPStan errors, 0 PHPMD violations

---

## Sprint 85: Webhook Transaction Recording + OXPAID Investigation

### Objective

Add transaction recording to `PaymentIntentSucceededHandler` (webhook) so that captures triggered by Stripe Dashboard also get recorded when webhook is active.

### Root Cause — OXPAID 0000 for orders 609/610

**Webhooks are not reaching the dev server.** Evidence:
- Zero webhook events in `stripe_events_2026-04-13.log`
- Zero entries in `oe_payments_webhooklogs` since January
- `PaymentIntentSucceededHandler` never fires

This is **not a code bug** — the webhook URL `https://daniil.oxiddev.de/index.php?cl=StripeWebhookController` is correctly registered in `metadata.php` and `services.yaml`. Stripe simply cannot reach the dev server (infrastructure/firewall/DNS issue).

### Changes

| Action | File | Change |
|--------|------|--------|
| MODIFY | `src/Stripe/WebhookHandler/PaymentIntentSucceededHandler.php` | +`TransactionRepositoryInterface`, +`recordCaptureTransaction()` |
| MODIFY | `services.yaml` | Wire `$transactionRepository` to webhook handler |
| MODIFY | `tests/Unit/.../PaymentIntentSucceededHandlerTest.php` | Updated constructor |
| MODIFY | `tests/Integration/.../WebhookContractTransitionTest.php` | Updated constructor |

### Status: done

- All checks passed — COMMITABLE

### Action Required (infrastructure)

1. Verify webhook endpoint in Stripe Dashboard → Developers → Webhooks
2. Ensure `https://daniil.oxiddev.de/index.php?cl=StripeWebhookController` is the configured URL
3. Check Stripe webhook delivery logs for HTTP errors
4. Test: place a new order through admin, capture from OUR admin panel — should show both auth + capture transactions

---

## Sprint 86: Stripe API-Based Transaction History (source of truth)

### Problem

Sprint 84/85 recorded transactions via our DB, but only for actions through our admin panel. Captures and refunds done from Stripe Dashboard don't show because webhooks aren't reaching the dev server.

### Fix

Replaced DB-based transaction history with **Stripe API-based** history. The `PaymentIntent` + `Charge` objects from Stripe contain all authorizations, captures, and refunds — regardless of where they were initiated.

### Changes

| Action | File | Change |
|--------|------|--------|
| MODIFY | `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | +`getStripeTransactionHistory(Order)` — builds history from Stripe API |
| MODIFY | `src/Stripe/Controller/Admin/OrderRefund.php` | `getTransactions()` now calls `getStripeTransactionHistory()` |
| MODIFY | `tests/Unit/.../OrderRefundTransactionHistoryTest.php` | Rewritten for new Stripe API-based approach |

### How It Works

1. Fetches `PaymentIntent` from Stripe API (already cached by existing `getPaymentIntent()`)
2. Extracts authorization from PI creation
3. Extracts capture from `Charge.captured` + `Charge.amount_captured`
4. Extracts refunds from `Charge.refunds.data[]`
5. Returns unified array for Twig rendering

### Status: done — COMMITABLE
