# Sprint 8: Drop osc_payment_order_state - Technical Plan

**Date:** 2025-12-04
**Status:** Planning
**Branch:** TBD (b-7.4.x-STRP-XX)
**Approach:** TDD-First, LSP, Clean Code, DI

---

## Executive Summary

Complete removal of `osc_payment_order_state` table and related code. All payment state tracking will be consolidated into `osc_payment_contract`. This eliminates redundant dual-tracking and simplifies the architecture.

---

## Problem Statement

### Current Issues

1. **Dual State Tracking**: Both `osc_payment_contract.OXSTATE` and `osc_payment_order_state.OXPAYMENTSTATE` track payment status
2. **Redundant Data**: `OXPROVIDERORDERID` exists in both tables
3. **Dead Code**: `PaymentOrderStateRepository` is never instantiated
4. **Confusing Flow**: `WebhookProcessingService` updates both tables
5. **Missing Features**: Contract doesn't track capture/refund amounts

### Tables Involved

| Table | Purpose | Action |
|-------|---------|--------|
| `osc_payment_order_state` | Legacy payment state | **DROP** |
| `osc_payment_contract` | Contract state machine | **ENHANCE** |
| `osc_payment_webhooklogs` | Webhook audit log | Keep |
| `oxorder` | OXID orders | Keep (OXPAID updated) |

---

## Dead Code to Remove

### Files to DELETE

```
src/Stripe/Repository/PaymentOrderStateRepository.php
  - Class never instantiated
  - Commented out in services.yaml
  - No references in codebase
```

### Methods to REMOVE from WebhookProcessingService

```php
// These methods update osc_payment_order_state - REMOVE
private function updateOrderPaymentState(string $orderId, string $state): void
private function updateOrderCaptureState(string $orderId, float $capturedAmount): void
private function updateOrderRefundState(string $orderId, float $refundedAmount): void
```

### Code to REMOVE (call sites)

```
src/Stripe/Service/WebhookProcessingService.php:285  $this->updateOrderPaymentState($order->getId(), 'paid');
src/Stripe/Service/WebhookProcessingService.php:350  $this->updateOrderPaymentState($order->getId(), 'failed');
src/Stripe/Service/WebhookProcessingService.php:372  $this->updateOrderPaymentState($order->getId(), 'canceled');
src/Stripe/Service/WebhookProcessingService.php:427  $this->updateOrderCaptureState($order->getId(), $capturedAmount);
src/Stripe/Service/WebhookProcessingService.php:488  $this->updateOrderRefundState($order->getId(), $refundedAmount);
src/Stripe/Service/WebhookProcessingService.php:583  $this->updateOrderPaymentState($order->getId(), 'paid');
```

### Legacy Fallback to REMOVE (WebhookController)

```php
// Remove processEventBasic() method
// Remove fallback conditional in render()
```

---

## Schema Changes

### Migration: Add Fields to Contract

```sql
ALTER TABLE osc_payment_contract
ADD COLUMN OXCAPTUREDAMOUNT DECIMAL(10,2) DEFAULT NULL AFTER OXFULFILLEDAT,
ADD COLUMN OXREFUNDEDAMOUNT DECIMAL(10,2) DEFAULT NULL AFTER OXCAPTUREDAMOUNT,
ADD COLUMN OXCAPTUREDAT DATETIME DEFAULT NULL AFTER OXREFUNDEDAMOUNT,
ADD COLUMN OXREFUNDEDAT DATETIME DEFAULT NULL AFTER OXCAPTUREDAT;
```

### Migration: Data Migration

```sql
-- Migrate existing data from order_state to contract
UPDATE osc_payment_contract c
JOIN osc_payment_order_state os ON c.OXID = os.OXCONTRACTID
SET
    c.OXCAPTUREDAMOUNT = os.OXCAPTUREDAMOUNT,
    c.OXREFUNDEDAMOUNT = os.OXREFUNDEDAMOUNT,
    c.OXCAPTUREDAT = os.OXCAPTUREDAT,
    c.OXUPDATED = NOW()
WHERE os.OXCONTRACTID IS NOT NULL;
```

### Migration: Drop Table

```sql
DROP TABLE IF EXISTS osc_payment_order_state;
```

---

## Implementation Phases

### Phase 1: TDD - Write Failing Tests First

**Tests to Write:**

```php
// tests/Integration/Component/Contract/ContractCaptureRefundTest.php
class ContractCaptureRefundTest extends IntegrationTestCase
{
    /** @test */
    public function contractStoresCapturedAmount(): void
    {
        // Given: Contract in committed state
        // When: handleChargeCaptured() called
        // Then: Contract has OXCAPTUREDAMOUNT set
    }

    /** @test */
    public function contractStoresRefundedAmount(): void
    {
        // Given: Contract in fulfilled state
        // When: handleChargeRefunded() called
        // Then: Contract has OXREFUNDEDAMOUNT set
    }

    /** @test */
    public function multipleRefundsAccumulate(): void
    {
        // Given: Contract with existing refund
        // When: Second refund processed
        // Then: OXREFUNDEDAMOUNT is sum of both
    }
}
```

**Test for No order_state Usage:**

```php
// tests/Integration/Stripe/Webhook/NoOrderStateTest.php
class NoOrderStateTest extends IntegrationTestCase
{
    /** @test */
    public function webhookProcessingDoesNotTouchOrderStateTable(): void
    {
        // Given: Empty osc_payment_order_state table
        // When: Process payment_intent.succeeded webhook
        // Then: Table still empty (no inserts/updates)
    }
}
```

### Phase 2: Enhance Contract Entity

**File:** `src/Component/Contract/PaymentContract.php`

```php
// Add new properties
private ?float $capturedAmount = null;
private ?float $refundedAmount = null;
private ?\DateTimeImmutable $capturedAt = null;
private ?\DateTimeImmutable $refundedAt = null;

// Add getters/setters following LSP
public function getCapturedAmount(): ?float;
public function setCapturedAmount(float $amount): void;
public function addRefundedAmount(float $amount): void; // Accumulates
public function getCapturedAt(): ?\DateTimeImmutable;
public function getRefundedAt(): ?\DateTimeImmutable;
```

**File:** `src/Component/Contract/PaymentContractInterface.php`

```php
// Add to interface (LSP compliance)
public function getCapturedAmount(): ?float;
public function setCapturedAmount(float $amount): void;
public function addRefundedAmount(float $amount): void;
```

### Phase 3: Update Repository

**File:** `src/Component/Repository/DoctrineContractRepository.php`

```php
// Update save() to persist new fields
// Update hydrate() to load new fields
```

### Phase 4: Update Webhook Handler

**File:** `src/Stripe/Handler/WebhookContractFulfillmentHandler.php`

```php
public function handleChargeCaptured(string $providerOrderId, float $amount): ?bool
{
    $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
    if ($contract === null) {
        return null;
    }

    // Set captured amount on contract
    $contract->setCapturedAmount($amount);
    $contract->setCapturedAt(new \DateTimeImmutable());

    // Existing fulfillment logic...
}

public function handleChargeRefunded(string $providerOrderId, float $amount): ?bool
{
    $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
    if ($contract === null) {
        return null;
    }

    // Accumulate refund amount
    $contract->addRefundedAmount($amount);
    $contract->setRefundedAt(new \DateTimeImmutable());

    $this->contractRepository->save($contract);
    return true;
}
```

### Phase 5: Remove Dead Code

1. Delete `src/Stripe/Repository/PaymentOrderStateRepository.php`
2. Remove `updateOrderPaymentState()` and call sites
3. Remove `updateOrderCaptureState()` and call sites
4. Remove `updateOrderRefundState()` and call sites
5. Remove `processEventBasic()` from WebhookController
6. Update services.yaml to remove commented entry

### Phase 6: Migration

**File:** `migration/data/Version20251205_DropOrderStateTable.php`

```php
public function up(Schema $schema): void
{
    // Step 1: Add columns to contract
    $this->addSql("ALTER TABLE osc_payment_contract
        ADD COLUMN OXCAPTUREDAMOUNT DECIMAL(10,2) DEFAULT NULL,
        ADD COLUMN OXREFUNDEDAMOUNT DECIMAL(10,2) DEFAULT NULL,
        ADD COLUMN OXCAPTUREDAT DATETIME DEFAULT NULL,
        ADD COLUMN OXREFUNDEDAT DATETIME DEFAULT NULL");

    // Step 2: Migrate data
    $this->addSql("UPDATE osc_payment_contract c
        JOIN osc_payment_order_state os ON c.OXID = os.OXCONTRACTID
        SET c.OXCAPTUREDAMOUNT = os.OXCAPTUREDAMOUNT,
            c.OXREFUNDEDAMOUNT = os.OXREFUNDEDAMOUNT,
            c.OXUPDATED = NOW()
        WHERE os.OXCONTRACTID IS NOT NULL");

    // Step 3: Drop table
    $this->addSql("DROP TABLE IF EXISTS osc_payment_order_state");
}

public function down(Schema $schema): void
{
    // Recreate table for rollback
    $this->addSql("CREATE TABLE osc_payment_order_state (...)");
    // Migrate data back
}
```

---

## Verification Checklist

### Before Merge

- [ ] All new tests pass (TDD)
- [ ] All existing tests pass
- [ ] No references to `osc_payment_order_state` in code
- [ ] No references to `PaymentOrderStateRepository`
- [ ] Migration runs successfully (up and down)
- [ ] E2E tests pass (checkout flow)
- [ ] Manual testing: new orders don't create order_state records

### Test Commands

```bash
# Unit tests
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit

# Integration tests
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php

# E2E tests
cd tests/e2e/playwright && npx playwright test tests/checkout/

# Verify no order_state usage
grep -rn "osc_payment_order_state" src/
# Should return: nothing
```

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Data loss during migration | High | Backup before migration, test on staging |
| Breaking existing orders | High | Migration preserves data in contract |
| Admin panel issues | Medium | Update admin views if needed |
| Refund tracking broken | Medium | New contract fields handle this |

---

## Architecture Diagrams

See PUML files:
- `puml/current-state-competing-entities.puml`
- `puml/target-state-unified-contract.puml`

---

## Success Criteria

1. ✅ `osc_payment_order_state` table dropped
2. ✅ `PaymentOrderStateRepository.php` deleted
3. ✅ All `updateOrder*State()` methods removed
4. ✅ Contract stores capture/refund amounts
5. ✅ All tests pass
6. ✅ E2E checkout flow works
7. ✅ New webhooks only update contract + oxorder.OXPAID

---

## Related Documentation

- Current state analysis: `sprint-7-oxpaid-providerorderid-fix-report.md`
- Contract architecture: `docs/payment-component/00-overview.md`
- Webhook processing: `docs/payment-component/02-webhook-processing.md`
