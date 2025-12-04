# Sprint 7: OXPAID & Provider Order ID Fix

## Problem Statement

Orders created via Stripe Checkout flow have `OXPAID = '0000-00-00 00:00:00'` even after successful payment. The E2E tests catch this but unit tests don't.



### Core Principles

| Principle | Description                                                                                   |
|-----------|-----------------------------------------------------------------------------------------------|
| **TDD-FIRST** | Write failing tests BEFORE implementation (RED → GREEN → REFACTOR)                            |
| **SOLID** | Single Responsibility, Open/Closed, Liskov, Interface Segregation, DI                         |
| **Dependency Injection** | All dependencies injected via constructor                                                     |
| **Liskov Substitution** | Use interfaces as types instead of classes. Subclasses must be substitutable for base classes |
| **Clean Code** | Human readable, maintainable, self-documenting                                                |
| **No Over-Engineering** | Minimal changes to achieve the goal                                                           |
| **No Duplicate Code** | Reuse existing services and methods                                                           |
| **No Reinventing** | Check if solution already exists before creating                                              |

### Pre-Implementation Checklist

**BEFORE writing ANY new code:**

1. **Review existing architecture:**
   - Check `src/Component/` for existing interfaces and services
   - Check `src/Stripe/` for existing handlers and adapters
   - Review `services.yaml` for existing DI configuration

2. **Review existing code:**
   - Does a similar method already exist? → Extend it
   - Does a similar class exist? → Reuse or extend it
   - Is there an interface for this? → Implement it

3. **Consider alternatives:**
   - Can we add to existing class instead of new class?
   - Can we add parameters to existing method instead of new method?
   - Can we use existing Component layer services?

---

## Test Execution Commands (Docker)

### Unit Tests

```bash
# Run all unit tests
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit

# Run specific test group
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --group sprint-6

# Run single test file
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    /var/www/extensions/stripe/tests/Unit/Stripe/Service/WebhookContractAwareTest.php
```

### Integration Tests

```bash
# Run integration tests (requires bootstrap!)
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php

# Run specific integration test group
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --group sprint-6 \
    --bootstrap=/var/www/source/bootstrap.php
```

### Pre-Commit Check

```bash
# Run from host - checks PHPUnit, PHPStan, PHPMD, PHPCS
./source/extensions/stripe/bin/pre-commit-check.sh

# Run FULL check including integration tests
./source/extensions/stripe/bin/pre-commit-check.sh --full

# Skip PHPUnit (only static analysis)
./source/extensions/stripe/bin/pre-commit-check.sh --no-phpunit
```

### Playwright E2E Tests

```bash
# Run from playwright directory
cd source/extensions/stripe/tests/e2e/playwright
npx playwright test tests/webhook/

# Run with UI
npx playwright test --ui
```

---

## Root Cause Analysis

### Issue 1: Provider Order ID Mismatch

**Bug Location:** `StripeCheckoutReturnHandler.php:272-278`

```php
$event = new PaymentAuthorizedEvent(
    context: $context,
    authorizationId: $paymentIntentId,   // ✓ Correct: pi_...
    providerOrderId: $sessionId,          // ✗ BUG: Should be $paymentIntentId
    amount: $session->amount_total / 100,
    currency: $currency
);
```

**Impact Chain:**
1. Contract stores `cs_test_...` as `providerOrderId`
2. Webhook arrives with `pi_...` (PaymentIntent ID)
3. `WebhookContractFulfillmentHandler` lookup by `pi_...` fails
4. Contract not found → returns `null`
5. Falls back to legacy path
6. Legacy lookup by `OXTRANSID` should work BUT...
7. The order DOES have `OXTRANSID = pi_...`, so legacy SHOULD work

### Issue 2: Why Legacy Path Also Fails

The legacy path in `WebhookProcessingService::processLegacyPaymentSucceeded()`:
1. Finds order by `OXTRANSID = pi_...` ✓
2. Calls `updateOrderPaymentState()` on `osc_payment_order_state`
3. Calls `updateOrderPaidTimestamp()` ✓
4. BUT: `osc_payment_order_state` table may be missing for contract-based orders!

### Issue 3: Direct SQL Access (Architectural Debt)

7 instances of direct SQL bypassing repository layer:

| File | Line | Operation |
|------|------|-----------|
| `WebhookController.php` | 163 | INSERT osc_payment_webhooklogs |
| `WebhookProcessingService.php` | 558 | SELECT osc_payment_transaction |
| `WebhookProcessingService.php` | 666 | UPDATE oxorder OXPAID |
| `WebhookProcessingService.php` | 695 | UPDATE oxorder OXTRANSSTATUS |
| `WebhookProcessingService.php` | 725 | UPDATE oxorder OXTRANSID |
| `WebhookProcessingService.php` | 773 | INSERT osc_payment_webhooklogs |
| `WebhookProcessingService.php` | 804 | UPDATE osc_payment_webhooklogs |

### Issue 4: Unit Tests Bypass Contract State Machine (Critical Test Gap)

**Problem:** The existing `OxpaidWebhookUpdateTest` creates orders with `OXTRANSID = pi_...` directly via SQL INSERT, completely bypassing the contract flow. This makes the legacy lookup work in tests but fail in real E2E where contracts have `cs_test_...`.

**File:** `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php`

```php
// CURRENT (bypasses contract):
private function createTestOrderWithTransId(string $userId, string $paymentIntentId): string
{
    $this->connection->insert('oxorder', [
        // ...
        'OXTRANSID' => $paymentIntentId,  // Direct SQL, no contract!
        // ...
    ]);
}
```

**Impact:**
- Tests pass because legacy path finds order by `OXTRANSID`
- Real E2E fails because contract has `cs_test_...` and webhook has `pi_...`
- **False green tests** - tests don't catch the real bug

**Required Fix:**
All webhook tests MUST work through the contract state machine:
1. Create contract with proper flow
2. Transition contract through states (DRAFT → PENDING → COMMITTED)
3. Then test webhook processing

This ensures tests reflect real-world behavior and catch ID mismatch bugs.



## Proposed Fixes

### Fix 1: Update providerOrderId to PaymentIntent ID (Critical)

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

**Change:** Line 275, change `providerOrderId: $sessionId` to `providerOrderId: $paymentIntentId`

```php
// BEFORE (buggy):
$event = new PaymentAuthorizedEvent(
    context: $context,
    authorizationId: $paymentIntentId,
    providerOrderId: $sessionId,           // ✗ cs_test_...
    amount: $session->amount_total / 100,
    currency: $currency
);

// AFTER (fixed):
$event = new PaymentAuthorizedEvent(
    context: $context,
    authorizationId: $paymentIntentId,
    providerOrderId: $paymentIntentId,     // ✓ pi_...
    amount: $session->amount_total / 100,
    currency: $currency
);
```

### Fix 2: Ensure OXPAID Update on Contract Fulfillment

**File:** `src/Stripe/Service/WebhookProcessingService.php`

The `updateOrderFieldsAfterContractFulfillment()` method already exists and is called when `$contractResult === true`. Ensure it's working correctly.

### Fix 3: Migration to Fix Existing Contracts

Create migration to update existing contracts that have `cs_test_...` as providerOrderId:

```sql
-- This is complex because we need to map checkout session → payment intent
-- via the transaction table or order.OXTRANSID
UPDATE osc_payment_contract c
JOIN oxorder o ON c.OXORDERID = o.OXID
SET c.OXPROVIDERORDERID = o.OXTRANSID
WHERE c.OXPROVIDERORDERID LIKE 'cs_%'
  AND o.OXTRANSID LIKE 'pi_%';
```

### Fix 4: WebhookLog Access via Service → Repository (Architectural)

**Problem:** Direct SQL access to `osc_payment_webhooklogs` in multiple places:
- `WebhookController.php:163` - INSERT
- `WebhookProcessingService.php:773` - INSERT
- `WebhookProcessingService.php:804` - UPDATE

**Required Architecture:**
```
WebhookController / WebhookProcessingService
           ↓
    WebhookLogService (NEW)
           ↓
    WebhookLogRepositoryInterface
           ↓
    DoctrineWebhookLogRepository
           ↓
    osc_payment_webhooklogs table
```

**WebhookLogService responsibilities:**
- `logEventReceived(eventId, eventType, payload): void`
- `markEventProcessed(eventId, status): void`
- `markEventFailed(eventId, errorMessage): void`
- `findByEventId(eventId): ?WebhookLog`

**Principle:** All data access flows through Service → Repository → Database. No direct SQL in controllers or processing services.

### Fix 5: Refactor OxpaidWebhookUpdateTest to Use Contract State Machine (Critical)

**File:** `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php`

**Problem:** Current tests bypass the contract layer entirely, creating a false sense of security.

**Required Changes:**
1. Remove direct `oxorder` INSERT with `OXTRANSID`
2. Create contract via `ContractRepository`
3. Transition contract: DRAFT → PENDING → READY_TO_COMMIT → COMMITTED
4. Link order to contract
5. THEN test webhook processing

**New Test Flow:**
```php
// CORRECT (uses contract state machine):
private function createTestOrderWithContract(string $paymentIntentId): string
{
    // 1. Create contract
    $contract = new PaymentContract(...);
    $contract->setProvider('stripe', $paymentIntentId);  // ✓ Use pi_... from the start!
    $this->contractRepository->save($contract);

    // 2. Transition to COMMITTED
    $contract->transitionToPending();
    $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [...]);
    // ... transition to COMMITTED with order

    // 3. Return order ID for assertions
    return $orderId;
}
```

**Principle:** Tests must mirror production code paths. If production uses contracts, tests must use contracts.

## TDD Test Created

**File:** `tests/Integration/Stripe/Webhook/ContractAwareOxpaidWebhookTest.php`

Two tests:
1. `contractAwareWebhookUpdatesOxpaid()` - Tests the FIXED flow
2. `bugDemonstration_contractWithCheckoutSessionIdFailsLookup()` - Demonstrates current bug

## Implementation Plan

### Phase 1: Fix the Bug (Immediate)

1. [ ] Fix `StripeCheckoutReturnHandler.php:275` - change `$sessionId` to `$paymentIntentId`
2. [ ] Run new `ContractAwareOxpaidWebhookTest` to verify fix
3. [ ] Run all unit tests
4. [ ] Run E2E tests

### Phase 2: Refactor Tests to Use Contract State Machine (Critical)

**Rationale:** Tests that bypass the contract layer give false confidence. All webhook tests must use the same code paths as production.

1. [ ] Refactor `OxpaidWebhookUpdateTest` to create contracts instead of direct SQL
2. [ ] Ensure tests create contract → transition states → then test webhooks
3. [ ] Remove or deprecate legacy test helpers that bypass contracts
4. [ ] Add `@group contract-aware` to all refactored tests
5. [ ] Verify all OXPAID tests pass with contract-aware flow

**Files to refactor:**
- `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php`
- Any other tests that create orders with direct SQL for webhook testing

### Phase 3: Fix Existing Data (Migration)

1. [ ] Create migration `Version20251204_FixContractProviderOrderId.php`
2. [ ] Update contracts with `cs_test_...` to use `pi_...` from order's `OXTRANSID`
3. [ ] Run E2E tests to verify historical orders now work

### Phase 4: Architectural Cleanup (Technical Debt)

**4a. WebhookLog Service Layer:**
1. [ ] Create `WebhookLogServiceInterface` with methods:
   - `logEventReceived(eventId, eventType, payload): void`
   - `markEventProcessed(eventId, status): void`
   - `markEventFailed(eventId, errorMessage): void`
2. [ ] Create `WebhookLogService` implementation using `WebhookLogRepositoryInterface`
3. [ ] Refactor `WebhookController` to use `WebhookLogService`
4. [ ] Refactor `WebhookProcessingService` to use `WebhookLogService`
5. [ ] Remove all direct SQL to `osc_payment_webhooklogs`

**4b. Order Field Updates:**
1. [ ] Create `OrderRepositoryInterface` for oxorder field updates (OXPAID, OXTRANSSTATUS, OXTRANSID)
2. [ ] Refactor `WebhookProcessingService` to use order repository
3. [ ] Ensure all payment-related data flows through proper layers

**Architecture Principle:**
```
Controller/Service → Domain Service → Repository → Database
```
No direct SQL in controllers or processing services.

## Test Verification

```bash
# Run TDD tests
./vendor/bin/phpunit --filter ContractAwareOxpaidWebhookTest

# Run all OXPAID tests
./vendor/bin/phpunit --group oxpaid

# Run Sprint 7 tests
./vendor/bin/phpunit --group sprint-7

# Run E2E tests
cd tests/e2e/playwright && npx playwright test tests/admin/payment-date-validation.spec.ts
```

## Success Criteria

1. `ContractAwareOxpaidWebhookTest::contractAwareWebhookUpdatesOxpaid()` passes
2. All existing unit tests pass (1098 tests)
3. E2E `payment-date-validation.spec.ts` passes (no orders with OK status and empty OXPAID)
4. New orders via checkout flow get OXPAID set correctly
5. **All webhook tests use contract state machine** - no direct SQL order creation in tests
6. `OxpaidWebhookUpdateTest` refactored and passing with contract-aware flow

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking existing orders | High | Migration updates only contracts with cs_test_... prefix |
| Breaking webhook idempotency | Medium | Test with duplicate webhooks |
| Breaking refund flow | Medium | Run refund E2E test after fix |
| Test refactoring breaks coverage | Medium | Keep old tests until new ones pass |

## Estimated Effort

- Phase 1 (Bug Fix): 1-2 hours
- Phase 2 (Test Refactoring): 2-3 hours
- Phase 3 (Migration): 1 hour
- Phase 4 (Cleanup): 4-6 hours (can be done later)

## Related Documentation

- Sprint 6 Report: `docs/payment-component/daniil_dev_log/20251204/done/sprint-6-contract-aware-webhooks-report.md`
- Sprint 4 OXPAID Report: `docs/payment-component/daniil_dev_log/20251203/done/sprint-4-OXPAID-REPORT.md`
- Architecture Overview: `docs/payment-component/00-overview.md`
