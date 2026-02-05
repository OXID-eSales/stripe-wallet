# Sprint 43: Interface Creation for LSP Compliance

**Date:** 2026-02-06
**Status:** 📋 PENDING DISCUSSION
**Prerequisites:** Sprint 38-41 completed (dead code cleanup)
**Estimated Effort:** 4-8 hours (depending on scope)

---

## Executive Summary

Three services in the Stripe module lack interfaces, violating the Liskov Substitution Principle. This sprint creates interfaces for these services to enable:
- Mock-based unit testing
- Alternative implementations
- Clear API contracts

**Priority Order:**
1. `WebhookProcessingService` - **CRITICAL** (core functionality)
2. `OxpaidReconciliationService` - Medium (cron functionality)
3. `StaticContent` - Low (module activation only)

---

## Questions for Discussion

### Question 1: WebhookProcessingService Interface Granularity

**Context:** `WebhookProcessingService` has 30+ public methods and 1240+ lines. Should we:

| Option | Description | Pros | Cons |
|--------|-------------|------|------|
| **A) Single interface** | One `WebhookProcessingServiceInterface` | Simple, direct | Large interface |
| **B) Split by event type** | Separate `PaymentIntentHandlerInterface`, `ChargeHandlerInterface`, etc. | Interface segregation | More files, complexity |
| **C) Split by responsibility** | `WebhookProcessorInterface` + `ContractUpdaterInterface` + `OrderUpdaterInterface` | Clear separation | Refactoring needed |

**Current Method Categories:**
```
// Main entry point
processWebhook(Event $event, array $data): bool

// PaymentIntent handlers
handlePaymentIntentSucceeded(PaymentIntent $pi): bool
handlePaymentIntentFailed(PaymentIntent $pi): bool
handlePaymentIntentCanceled(PaymentIntent $pi): bool

// Charge handlers
handleChargeCaptured(Charge $charge): bool
handleChargeRefunded(Charge $charge): bool
handleChargeDisputeCreated(Dispute $dispute): bool

// Helper methods (20+ private/protected)
```

**My Recommendation:** Option A for now (single interface), refactor later if needed

---

### Question 2: OxpaidReconciliationService Interface

**Context:** This service is called by cron jobs to reconcile unpaid orders.

| Option | Description |
|--------|-------------|
| **A) Full interface** | All public methods in interface |
| **B) Minimal interface** | Only `reconcile()` method |

**Current Public Methods:**
```php
public function reconcile(int $limit = 100): ReconciliationResult;
public function findOrdersNeedingReconciliation(): array;
public function reconcileOrder(array $orderData): bool;
```

**My Recommendation:** Option A (full interface) - enables better testing

---

### Question 3: StaticContent Interface

**Context:** Used only during module activation/deactivation.

| Option | Description |
|--------|-------------|
| **A) Create interface** | Consistency, enables alternative payment method configs |
| **B) Skip for now** | Low priority, rarely tested |

**My Recommendation:** Option B (skip for now) - focus on higher priority items

---

### Question 4: Generic ServiceInterface Replacement

**Context:** `ModuleConfigurationService` and `ConfigurationValidator` only implement generic `ServiceInterface`.

| Option | Description |
|--------|-------------|
| **A) Create specific interfaces** | Full SOLID compliance |
| **B) Keep generic** | These are utility classes, less critical |

**My Recommendation:** Option B (keep generic for now) - lower priority

---

## Implementation Plan

### Phase 1: WebhookProcessingServiceInterface (2-3 hours)

**Step 1: Create Interface**
```
src/Stripe/Service/WebhookProcessingServiceInterface.php
```

**Proposed Interface:**
```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Charge;
use Stripe\Dispute;

interface WebhookProcessingServiceInterface
{
    /**
     * Process incoming Stripe webhook event.
     *
     * @param Event $stripeEvent The Stripe event object
     * @param array $webhookData Raw webhook payload
     * @return bool True if processed successfully
     */
    public function processWebhook(Event $stripeEvent, array $webhookData): bool;

    /**
     * Handle payment_intent.succeeded event.
     */
    public function handlePaymentIntentSucceeded(PaymentIntent $paymentIntent): bool;

    /**
     * Handle payment_intent.payment_failed event.
     */
    public function handlePaymentIntentFailed(PaymentIntent $paymentIntent): bool;

    /**
     * Handle payment_intent.canceled event.
     */
    public function handlePaymentIntentCanceled(PaymentIntent $paymentIntent): bool;

    /**
     * Handle charge.captured event.
     */
    public function handleChargeCaptured(Charge $charge): bool;

    /**
     * Handle charge.refunded event.
     */
    public function handleChargeRefunded(Charge $charge): bool;

    /**
     * Handle charge.dispute.created event.
     */
    public function handleChargeDisputeCreated(Dispute $dispute): bool;
}
```

**Step 2: Update Service**
```php
class WebhookProcessingService implements WebhookProcessingServiceInterface
```

**Step 3: Update DI Configuration**
```yaml
services:
    OxidEsales\Payments\Stripe\Service\WebhookProcessingServiceInterface:
        alias: OxidEsales\Payments\Stripe\Service\WebhookProcessingService
```

**Step 4: Update Type Hints**
- `WebhookController.php` - Change constructor type hint

**Step 5: Verify Tests Pass**
```bash
./bin/pre-commit-check.sh --full
```

---

### Phase 2: OxpaidReconciliationServiceInterface (1-2 hours)

**Step 1: Create Interface**
```
src/Stripe/Service/OxpaidReconciliationServiceInterface.php
```

**Proposed Interface:**
```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\Result\ReconciliationResult;

interface OxpaidReconciliationServiceInterface
{
    /**
     * Reconcile orders with unpaid status against Stripe API.
     *
     * @param int $limit Maximum orders to process
     * @return ReconciliationResult
     */
    public function reconcile(int $limit = 100): ReconciliationResult;

    /**
     * Find orders needing reconciliation.
     *
     * @return array<array{OXID: string, OXTRANSID: string, OXORDERNR: int}>
     */
    public function findOrdersNeedingReconciliation(): array;

    /**
     * Reconcile a single order.
     *
     * @param array{OXID: string, OXTRANSID: string, OXORDERNR: int} $orderData
     * @return bool True if order was reconciled
     */
    public function reconcileOrder(array $orderData): bool;
}
```

**Step 2-5:** Same as Phase 1

---

### Phase 3 (Optional): StaticContentInterface (1 hour)

Only if we decide to proceed with Option A in Question 3.

---

## Files to Create

| File | Purpose |
|------|---------|
| `src/Stripe/Service/WebhookProcessingServiceInterface.php` | Interface definition |
| `src/Stripe/Service/OxpaidReconciliationServiceInterface.php` | Interface definition |

## Files to Modify

| File | Changes |
|------|---------|
| `src/Stripe/Service/WebhookProcessingService.php` | Add `implements` clause |
| `src/Stripe/Service/OxpaidReconciliationService.php` | Add `implements` clause |
| `src/Stripe/Controller/WebhookController.php` | Update type hint |
| `services.yaml` | Add interface aliases |

---

## Testing Strategy

### Unit Tests (New)

After interfaces are created, we can add unit tests with mocks:

```php
class WebhookControllerTest extends TestCase
{
    public function testProcessWebhookDelegates(): void
    {
        $mockService = $this->createMock(WebhookProcessingServiceInterface::class);
        $mockService->expects($this->once())
            ->method('processWebhook')
            ->willReturn(true);

        $controller = new WebhookController($mockService);
        // ... test controller logic without hitting Stripe API
    }
}
```

### Integration Tests (Existing)

Keep existing integration tests as smoke tests.

---

## Dependencies

**Before starting this sprint:**
- [x] Sprint 38: Remove dead API key fields
- [x] Sprint 39: Add key mismatch warning
- [x] Sprint 40: Remove StatusMappingConfig
- [x] Sprint 41: Idempotency analysis report

**Optional Prerequisites:**
- [ ] Sprint 42: Idempotency implementation (independent)

---

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Breaking changes | Low | Medium | Interfaces match existing signatures |
| Test failures | Low | Low | Run `pre-commit-check.sh --full` |
| DI container issues | Low | Medium | Test container compilation |

---

## Decision Matrix

| Question | Options | Recommendation |
|----------|---------|----------------|
| Q1: WebhookProcessing granularity | A/B/C | A (single interface) |
| Q2: OxpaidReconciliation interface | A/B | A (full interface) |
| Q3: StaticContent interface | A/B | B (skip for now) |
| Q4: Generic ServiceInterface | A/B | B (keep generic) |

---

## Action Items After Discussion

- [ ] Finalize answers to Q1-Q4
- [ ] Create `WebhookProcessingServiceInterface`
- [ ] Create `OxpaidReconciliationServiceInterface`
- [ ] Update service implementations
- [ ] Update DI configuration
- [ ] Run `./bin/pre-commit-check.sh --full`
- [ ] Update documentation
