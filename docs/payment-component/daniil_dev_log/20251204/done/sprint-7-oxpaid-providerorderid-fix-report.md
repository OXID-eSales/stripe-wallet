# Sprint 7: OXPAID & Provider Order ID Fix - Report

**Date:** 2025-12-04
**Status:** Completed (All Phases)
**Branch:** b-7.4.x-auth-STRP-70

---

## Executive Summary

Fixed a critical bug where orders created via Stripe Checkout had `OXPAID = '0000-00-00 00:00:00'` even after successful payment. The root cause was a provider order ID mismatch: contracts stored checkout session IDs (`cs_test_...`) while webhooks sent payment intent IDs (`pi_...`), causing webhook lookups to fail.

---

## Problem Statement

### Symptoms
- Orders with `OXTRANSSTATUS = 'OK'` had `OXPAID = '0000-00-00 00:00:00'`
- E2E tests caught this but unit tests didn't
- Contract state remained `committed` instead of transitioning to `fulfilled`

### Root Cause Analysis

**Bug Location:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php:275`

```php
// BEFORE (buggy):
$event = new PaymentAuthorizedEvent(
    context: $context,
    authorizationId: $paymentIntentId,
    providerOrderId: $sessionId,           // ✗ cs_test_... (wrong!)
    amount: $session->amount_total / 100,
    currency: $currency
);
```

**Impact Chain:**
1. Contract stores `cs_test_...` as `providerOrderId`
2. Webhook arrives with `pi_...` (PaymentIntent ID)
3. `WebhookContractFulfillmentHandler::findByProviderOrderId('pi_...')` returns `null`
4. Contract not found → Falls back to legacy path
5. Legacy path updates OXPAID via OXTRANSID lookup, but contract never transitions to FULFILLED

---

## Implementation

### Phase 1: Bug Fix ✅

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

```php
// AFTER (fixed):
// Sprint 7 Fix: Use PaymentIntent ID as providerOrderId (not checkout session ID)
// This ensures webhook handler can find the contract when payment_intent.succeeded arrives
$event = new PaymentAuthorizedEvent(
    context: $context,
    authorizationId: $paymentIntentId,
    providerOrderId: $paymentIntentId,     // ✓ pi_... (correct!)
    amount: $session->amount_total / 100,
    currency: $currency
);
```

**One-line change at line 277.**

### Phase 2: Test Refactoring ✅

**Problem:** Existing `OxpaidWebhookUpdateTest` created orders with `OXTRANSID = pi_...` directly via SQL, bypassing the contract flow. This gave false confidence - tests passed but real checkout flow failed.

**Solution:**
1. Refactored tests to use contract state machine
2. Added `@runTestsInSeparateProcesses` annotation to handle Symfony DI container state isolation

**File:** `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php`

Key changes:
- Added contract creation helper methods
- Tests now create contract → transition to COMMITTED → process webhook → verify FULFILLED
- Added legacy fallback test for backward compatibility

```php
/**
 * @runTestsInSeparateProcesses
 */
final class OxpaidWebhookUpdateTest extends IntegrationTestCase
{
    private function createContractAndOrder(string $paymentIntentId): array
    {
        $userId = $this->createTestUser();
        $contractId = $this->createContract($userId, $paymentIntentId);
        $orderId = $this->createOrderLinkedToContract($userId, $contractId, $paymentIntentId);
        $this->transitionContractToCommitted($contractId, $orderId);
        return [$contractId, $orderId];
    }
}
```

**New Test File:** `tests/Integration/Stripe/Webhook/ContractAwareOxpaidWebhookTest.php`
- `contractWithPaymentIntentIdUpdatesOxpaid()` - Tests the FIXED flow
- `contractWithCheckoutSessionIdFailsLookup()` - Demonstrates the bug behavior

### Phase 3: Data Migration ✅

**File:** `migration/data/Version20251204_FixContractProviderOrderId.php`

```php
public function up(Schema $schema): void
{
    // Fix contracts with checkout session ID by using PaymentIntent ID from order
    $this->addSql("
        UPDATE osc_payment_contract c
        JOIN oxorder o ON c.OXORDERID = o.OXID
        SET c.OXPROVIDERORDERID = o.OXTRANSID,
            c.OXUPDATED = NOW()
        WHERE c.OXPROVIDERORDERID LIKE 'cs\\_%'
          AND o.OXTRANSID LIKE 'pi\\_%'
    ");
}
```

This migration:
- Finds contracts where `OXPROVIDERORDERID` starts with `cs_` (wrong)
- Updates them to use the `OXTRANSID` from linked order (which has `pi_...`)
- Only updates if the order has a valid `pi_...` transaction ID

### Phase 4: WebhookLogService ✅

Architectural cleanup to route webhook log access through proper service layer:
```
Controller/Service → WebhookLogService → WebhookLogRepository → Database
```

**Files Created:**
- `src/Component/Service/WebhookLogServiceInterface.php` - Service interface
- `src/Component/Service/WebhookLogService.php` - Service implementation

**Files Modified:**
- `src/Component/Repository/WebhookLogRepositoryInterface.php` - Added `updateStatus()` method
- `src/Component/Repository/DoctrineWebhookLogRepository.php` - Implemented `updateStatus()`
- `src/Component/Repository/WebhookLogRepository.php` - Implemented `updateStatus()` (in-memory)
- `src/Stripe/Controller/Webhook/WebhookController.php` - Now uses WebhookLogService
- `src/Stripe/Service/WebhookProcessingService.php` - Now uses repository for status updates
- `services.yaml` - Registered WebhookLogService

**Service Interface:**
```php
interface WebhookLogServiceInterface
{
    public function logEventReceived(string $eventId, string $eventType, array $payload, string $provider = 'stripe'): WebhookLog;
    public function markEventProcessed(string $eventId, ?string $contractId = null): void;
    public function markEventFailed(string $eventId, string $errorMessage): void;
    public function eventExists(string $eventId): bool;
    public function findByEventId(string $eventId): ?WebhookLog;
}
```

---

## Test Results

### Unit Tests
```
Tests: 1098, Assertions: 2449
Status: OK ✅
```

### Integration Tests (OXPAID Group)
```
Tests: 8, Assertions: 25
Status: OK ✅
```

Tests included:
- `paymentIntentSucceededUpdatesOxpaidViaContract`
- `paymentIntentSucceededIsIdempotent`
- `chargeCapturedUpdatesOxpaidViaContract`
- `checkoutSessionCompletedUpdatesOxpaidViaContract`
- `paymentIntentRequiresCaptureShouldNotUpdateOxpaid`
- `legacyOrderWithoutContractStillWorks`
- `contractWithPaymentIntentIdUpdatesOxpaid`
- `contractWithCheckoutSessionIdFailsLookup`

### E2E Tests
```
Checkout flow: 1 passed ✅
Payment date validation: 2 passed ✅
```

---

## Files Changed

| File | Change Type | Description |
|------|-------------|-------------|
| `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` | Modified | Line 277: `$sessionId` → `$paymentIntentId` |
| `migration/data/Version20251204_FixContractProviderOrderId.php` | New | Migration to fix existing contracts |
| `tests/Integration/Stripe/Webhook/ContractAwareOxpaidWebhookTest.php` | New | TDD tests for contract-aware webhook flow |
| `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php` | Modified | Refactored to use contract state machine |
| `src/Component/Service/WebhookLogServiceInterface.php` | New | Service interface for webhook logging |
| `src/Component/Service/WebhookLogService.php` | New | Service implementation |
| `src/Component/Repository/WebhookLogRepositoryInterface.php` | Modified | Added `updateStatus()` method |
| `src/Component/Repository/DoctrineWebhookLogRepository.php` | Modified | Implemented `updateStatus()` |
| `src/Component/Repository/WebhookLogRepository.php` | Modified | Implemented `updateStatus()` (in-memory) |
| `src/Stripe/Controller/Webhook/WebhookController.php` | Modified | Uses WebhookLogService |
| `src/Stripe/Service/WebhookProcessingService.php` | Modified | Uses repository for status updates |
| `services.yaml` | Modified | Registered WebhookLogService |

---

## Architecture Diagram

### Fixed Flow (Sprint 7)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         STRIPE CHECKOUT FLOW (FIXED)                         │
└─────────────────────────────────────────────────────────────────────────────┘

Customer                    Shop                         Stripe
   │                         │                             │
   │  1. Click Pay           │                             │
   │────────────────────────>│                             │
   │                         │  2. Create Checkout Session │
   │                         │────────────────────────────>│
   │                         │                             │
   │                         │  3. Session + PI created    │
   │                         │<────────────────────────────│
   │  4. Redirect            │                             │
   │<────────────────────────│                             │
   │                         │                             │
   │  5. Complete payment    │                             │
   │─────────────────────────────────────────────────────>│
   │                         │                             │
   │  6. Return to shop      │                             │
   │<─────────────────────────────────────────────────────│
   │                         │                             │
   │                    ┌────┴────┐                        │
   │                    │ Handler │                        │
   │                    └────┬────┘                        │
   │                         │                             │
   │           7. PaymentAuthorizedEvent                   │
   │              providerOrderId = pi_... ✓               │
   │                         │                             │
   │                    ┌────┴────┐                        │
   │                    │Contract │                        │
   │                    │ COMMITTED│                        │
   │                    │ pi_...  │                        │
   │                    └────┬────┘                        │
   │                         │                             │
   │                         │  8. Webhook: pi_...         │
   │                         │<────────────────────────────│
   │                         │                             │
   │           9. findByProviderOrderId(pi_...)            │
   │              → Contract FOUND! ✓                      │
   │                         │                             │
   │                    ┌────┴────┐                        │
   │                    │Contract │                        │
   │                    │FULFILLED│                        │
   │                    └────┬────┘                        │
   │                         │                             │
   │           10. OXPAID = NOW() ✓                        │
   │                         │                             │
```

---

## Verification Commands

```bash
# Run unit tests
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit

# Run OXPAID integration tests
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --group oxpaid \
    --bootstrap=/var/www/source/bootstrap.php

# Run E2E tests
cd source/extensions/stripe/tests/e2e/playwright
npx playwright test tests/checkout/
npx playwright test tests/admin/payment-date-validation.spec.ts
```

---

## Risk Assessment

| Risk | Impact | Mitigation | Status |
|------|--------|------------|--------|
| Breaking existing orders | High | Migration only updates contracts with `cs_` prefix | ✅ Mitigated |
| Breaking webhook idempotency | Medium | Tested with duplicate webhooks | ✅ Tested |
| Breaking refund flow | Medium | Refund uses same PI lookup | ✅ Working |
| Test isolation issues | Low | Added `@runTestsInSeparateProcesses` | ✅ Fixed |

---

## Success Criteria - All Met ✅

1. ✅ `ContractAwareOxpaidWebhookTest::contractWithPaymentIntentIdUpdatesOxpaid()` passes
2. ✅ All existing unit tests pass (1098 tests)
3. ✅ E2E `payment-date-validation.spec.ts` passes (no orders with OK status and empty OXPAID)
4. ✅ New orders via checkout flow get OXPAID set correctly
5. ✅ All webhook tests use contract state machine
6. ✅ `OxpaidWebhookUpdateTest` refactored and passing with contract-aware flow

---

## Related Documentation

- Sprint 6 Report: `docs/payment-component/daniil_dev_log/20251204/done/sprint-6-contract-aware-webhooks-report.md`
- Sprint Plan: `docs/payment-component/daniil_dev_log/20251204/todo/sprint-7-oxpaid-providerorderid-fix.md`
- Architecture Overview: `docs/payment-component/00-overview.md`
- PUML Diagrams: `docs/payment-component/daniil_dev_log/20251204/puml/`
