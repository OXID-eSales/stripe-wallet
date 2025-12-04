# Sprint 6: Contract-Aware Webhook Processing

**Date:** December 3, 2025
**Status:** PLANNED
**Priority:** CRITICAL
**Related Diagrams:** `/puml/01-08` in this directory

---

## Development Principles (MANDATORY)

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

## Executive Summary

### The Problem

The payment module has **two parallel workflows** that update order state differently:

| Flow | Uses Contract? | Updates Via | Result |
|------|---------------|-------------|--------|
| **Frontend** | YES | Event system + Contract state machine | DRAFT→PENDING→READY→COMMITTED |
| **Webhook** | NO | Direct SQL | Only `oxorder` tables updated |

**Result:** Contract stuck in `COMMITTED` state forever, never transitions to `FULFILLED`.

### Why Tests Are "False Green"

- **50+ webhook tests pass** because they verify:
  - WebhookLog is saved correctly
  - Event type is logged
  - Payload is stored
  - Idempotency by event_id

- **Tests DON'T verify:**
  - Contract is found by provider order ID
  - Contract state is validated (must be `COMMITTED`)
  - Contract transitions `COMMITTED → FULFILLED`
  - `ContractFulfilledEvent` is dispatched

### The Fix

Refactor `WebhookProcessingService` to be **contract-aware**:
1. Find contract by provider order ID
2. Validate contract state
3. Transition contract to `FULFILLED`
4. Update order **through** contract
5. Dispatch `ContractFulfilledEvent`

---

## Objectives

### Primary Goal
Make `WebhookProcessingService` use the Contract state machine for all payment state transitions.

### Definition of Done
1. Webhook processing finds contract by `OXPROVIDERORDERID`
2. Webhook validates contract state before processing
3. Contract transitions from `COMMITTED` to `FULFILLED`
4. `ContractFulfilledEvent` is dispatched
5. All existing tests continue to pass
6. New tests verify contract-aware behavior
7. E2E test verifies complete flow with contract state

---

## Phase 1: RED - Write Failing Tests

### Test 1.1: Webhook Finds Contract by Provider Order ID

```php
/**
 * @test
 * @group sprint-6
 * @group contract-aware
 */
public function webhookFindsContractByProviderOrderId(): void
{
    $event = $this->createStripeEvent('evt_001', 'payment_intent.succeeded', [
        'id' => 'pi_test_123',
        'status' => 'succeeded',
    ]);

    $contract = $this->createMock(PaymentContractInterface::class);
    $contract->method('getState')->willReturn(ContractState::committed());
    $contract->method('getOrderId')->willReturn('order123');

    $this->contractRepository
        ->expects($this->once())
        ->method('findByProviderOrderId')
        ->with('pi_test_123')
        ->willReturn($contract);

    $this->service->processEvent($event);
}
```

### Test 1.2: Webhook Validates Contract Is COMMITTED

```php
/**
 * @test
 * @group sprint-6
 * @group contract-aware
 */
public function webhookSkipsAlreadyFulfilledContract(): void
{
    $event = $this->createStripeEvent('evt_002', 'payment_intent.succeeded', [
        'id' => 'pi_already_fulfilled',
        'status' => 'succeeded',
    ]);

    $contract = $this->createMock(PaymentContractInterface::class);
    $contract->method('getState')->willReturn(ContractState::fulfilled());

    $this->contractRepository
        ->method('findByProviderOrderId')
        ->willReturn($contract);

    // Contract should NOT be saved (idempotent - already fulfilled)
    $this->contractRepository
        ->expects($this->never())
        ->method('save');

    $this->service->processEvent($event);
}
```

### Test 1.3: Webhook Transitions Contract to FULFILLED

```php
/**
 * @test
 * @group sprint-6
 * @group contract-aware
 */
public function webhookTransitionsContractToFulfilled(): void
{
    $event = $this->createStripeEvent('evt_003', 'payment_intent.succeeded', [
        'id' => 'pi_to_fulfill',
        'status' => 'succeeded',
    ]);

    $contract = $this->createMock(PaymentContractInterface::class);
    $contract->method('getState')->willReturn(ContractState::committed());
    $contract->method('getOrderId')->willReturn('order456');

    $contract->expects($this->once())
        ->method('fulfill');

    $this->contractRepository
        ->method('findByProviderOrderId')
        ->willReturn($contract);

    $this->contractRepository
        ->expects($this->once())
        ->method('save')
        ->with($contract);

    $this->service->processEvent($event);
}
```

### Test 1.4: Webhook Dispatches ContractFulfilledEvent

```php
/**
 * @test
 * @group sprint-6
 * @group contract-aware
 */
public function webhookDispatchesContractFulfilledEvent(): void
{
    $event = $this->createStripeEvent('evt_004', 'payment_intent.succeeded', [
        'id' => 'pi_dispatch_event',
        'status' => 'succeeded',
    ]);

    $contract = $this->createMock(PaymentContractInterface::class);
    $contract->method('getState')->willReturn(ContractState::committed());
    $contract->method('getOrderId')->willReturn('order789');

    $this->contractRepository
        ->method('findByProviderOrderId')
        ->willReturn($contract);

    $this->eventDispatcher
        ->expects($this->once())
        ->method('dispatch')
        ->with($this->isInstanceOf(ContractFulfilledEvent::class));

    $this->service->processEvent($event);
}
```

### Test 1.5: Webhook Updates Order Through Contract

```php
/**
 * @test
 * @group sprint-6
 * @group contract-aware
 */
public function webhookUpdatesOrderThroughContract(): void
{
    $event = $this->createStripeEvent('evt_005', 'payment_intent.succeeded', [
        'id' => 'pi_update_order',
        'status' => 'succeeded',
    ]);

    $orderId = 'order_from_contract';
    $contract = $this->createMock(PaymentContractInterface::class);
    $contract->method('getState')->willReturn(ContractState::committed());
    $contract->method('getOrderId')->willReturn($orderId);

    $this->contractRepository
        ->method('findByProviderOrderId')
        ->willReturn($contract);

    // Order should be found via contract's order ID
    $order = $this->createMock(Order::class);
    $this->orderRepository
        ->expects($this->once())
        ->method('find')
        ->with($orderId)
        ->willReturn($order);

    $order->expects($this->once())
        ->method('markOrderPaid');

    $this->service->processEvent($event);
}
```

---

## Phase 2: GREEN - Implement Contract-Aware Logic

### Step 2.1: Add Dependencies to WebhookProcessingService

```php
class WebhookProcessingService
{
    public function __construct(
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?WebhookLogRepositoryInterface $webhookLogRepository = null,
        private ?ContractRepositoryInterface $contractRepository = null,  // NEW
        private ?OrderRepositoryInterface $orderRepository = null         // NEW
    ) {
    }
}
```

### Step 2.2: Refactor handlePaymentIntentSucceeded

```php
private function handlePaymentIntentSucceeded(\Stripe\Event $event): void
{
    $paymentIntent = $event->data->object;
    $paymentIntentId = $paymentIntent->id;

    // 1. Find contract by provider order ID (NEW!)
    $contract = $this->findContractByProviderOrderId($paymentIntentId);

    if ($contract !== null) {
        // Contract-aware path
        $this->processContractFulfillment($contract, $paymentIntent);
    } else {
        // Legacy fallback (for orders created without contract)
        $this->processLegacyPayment($paymentIntent);
    }
}

private function findContractByProviderOrderId(string $providerOrderId): ?PaymentContractInterface
{
    if ($this->contractRepository === null) {
        return null;
    }

    return $this->contractRepository->findByProviderOrderId($providerOrderId);
}

private function processContractFulfillment(
    PaymentContractInterface $contract,
    object $paymentIntent
): void {
    // 2. Validate contract state
    if ($contract->getState()->isFulfilled()) {
        // Idempotent - already processed
        Registry::getLogger()->info('Contract already fulfilled (idempotent)', [
            'contract_id' => $contract->getId(),
        ]);
        return;
    }

    if (!$contract->getState()->isCommitted()) {
        // Contract not in expected state
        Registry::getLogger()->warning('Contract not in COMMITTED state', [
            'contract_id' => $contract->getId(),
            'state' => $contract->getStateValue(),
        ]);
        return;
    }

    // 3. Fulfill contract
    $contract->fulfill();
    $this->contractRepository->save($contract);

    // 4. Update order THROUGH contract
    $orderId = $contract->getOrderId();
    if ($orderId !== null) {
        $this->updateOrderPaidTimestamp($orderId);
        $this->updateOrderTransStatus($orderId, 'OK');
        $this->updateOrderTransId($orderId, $paymentIntent->id);
    }

    // 5. Dispatch event
    if ($this->eventDispatcher !== null) {
        $context = new EventContext([
            'contractId' => $contract->getId(),
            'orderId' => $orderId,
            'paymentIntentId' => $paymentIntent->id,
        ]);

        $event = new ContractFulfilledEvent($contract, $context, $orderId);
        $this->eventDispatcher->dispatch($event);
    }

    Registry::getLogger()->info('Contract fulfilled via webhook', [
        'contract_id' => $contract->getId(),
        'order_id' => $orderId,
    ]);
}

private function processLegacyPayment(object $paymentIntent): void
{
    // Keep existing direct SQL logic for backward compatibility
    $order = $this->findOrderByPaymentIntentId($paymentIntent->id);

    if ($order) {
        $this->updateOrderPaymentState($order->getId(), 'paid');
        $this->updateOrderPaidTimestamp($order->getId());
        $this->updateOrderTransStatus($order->getId(), 'OK');
        $this->updateOrderTransId($order->getId(), $paymentIntent->id);
    }
}
```

### Step 2.3: Update services.yaml

```yaml
OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService:
    arguments:
        $eventDispatcher: '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
        $webhookLogRepository: '@OxidSolutionCatalysts\Payments\Component\Repository\WebhookLogRepositoryInterface'
        $contractRepository: '@OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface'
        $orderRepository: '@OxidSolutionCatalysts\Payments\Component\Repository\OrderRepositoryInterface'
```

---

## Phase 3: REFACTOR - Clean Architecture

### Step 3.1: Extract WebhookContractHandler

For cleaner separation of concerns, extract contract logic to a dedicated handler:

```php
class WebhookContractFulfillmentHandler
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private EventDispatcherInterface $eventDispatcher
    ) {}

    public function handlePaymentSucceeded(
        PaymentContractInterface $contract,
        string $paymentIntentId
    ): bool {
        // Validate state
        if (!$contract->getState()->isCommitted()) {
            return false;
        }

        // Fulfill contract
        $contract->fulfill();
        $this->contractRepository->save($contract);

        // Dispatch event
        $this->eventDispatcher->dispatch(
            new ContractFulfilledEvent($contract, ...)
        );

        return true;
    }
}
```

### Step 3.2: Integration Test

```php
/**
 * @test
 * @group sprint-6
 * @group integration
 */
public function testWebhookFulfillsContractInDatabase(): void
{
    // 1. Create contract in DB with state = COMMITTED
    $contract = $this->createContractInDatabase('pi_integration_test');

    // 2. Simulate webhook
    $event = $this->createStripeEvent('evt_integration', 'payment_intent.succeeded', [
        'id' => 'pi_integration_test',
        'status' => 'succeeded',
    ]);

    $this->webhookService->processEvent($event);

    // 3. Verify contract state in DB
    $updatedContract = $this->contractRepository->findById($contract->getId());
    $this->assertTrue($updatedContract->getState()->isFulfilled());

    // 4. Verify order updated
    $order = $this->orderRepository->find($contract->getOrderId());
    $this->assertEquals('OK', $order->getFieldData('oxtransstatus'));
    $this->assertNotEquals('0000-00-00 00:00:00', $order->getFieldData('oxpaid'));
}
```

### Step 3.3: E2E Playwright Test

```typescript
test('complete checkout flow with contract fulfillment', async ({ page }) => {
    // 1. Complete checkout (creates contract in COMMITTED state)
    await completeCheckout(page);

    // 2. Get contract ID from session/response
    const contractId = await getContractIdFromThankYouPage(page);

    // 3. Trigger webhook via Stripe CLI
    await triggerStripeWebhook('payment_intent.succeeded');

    // 4. Verify contract state in DB
    const contractState = await queryDatabase(
        `SELECT OXSTATE FROM osc_payment_contract WHERE OXID = '${contractId}'`
    );
    expect(contractState).toBe('fulfilled');
});
```

---

## Files to Modify

| File | Changes |
|------|---------|
| `src/Stripe/Service/WebhookProcessingService.php` | Add contract-aware logic |
| `src/Stripe/Service/services.yaml` | Add contract repository dependency |
| `tests/Unit/Stripe/Service/WebhookContractAwareTest.php` | NEW: Phase 1 tests |
| `tests/Integration/Stripe/Webhook/WebhookContractFulfillmentTest.php` | NEW: Phase 3 integration |
| `tests/e2e/playwright/tests/webhook/contract-fulfillment.spec.ts` | NEW: E2E test |

---

## Success Criteria

| Criterion | How to Verify |
|-----------|---------------|
| Contract is found by provider order ID | Unit test 1.1 |
| Contract state is validated | Unit tests 1.2, 1.3 |
| Contract transitions to FULFILLED | Unit test 1.3 + DB check |
| ContractFulfilledEvent dispatched | Unit test 1.4 |
| Order updated through contract | Unit test 1.5 |
| Backward compatibility maintained | All existing tests pass |
| E2E flow works | Playwright test passes |

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Existing orders without contracts | Legacy fallback path maintained |
| Contract not found | Graceful fallback to direct DB |
| Performance impact | Contract lookup is indexed on OXPROVIDERORDERID |
| Breaking existing tests | New tests added, old tests kept |

---

## Definition of Done Checklist

Before marking sprint complete, verify ALL items:

### TDD Compliance
- [ ] RED: Failing tests written FIRST (before any implementation)
- [ ] GREEN: Minimal code written to pass tests
- [ ] REFACTOR: Code cleaned up, follows SOLID

### Code Quality
- [ ] No duplicate code introduced
- [ ] No over-engineering (minimal changes only)
- [ ] Existing services reused where possible
- [ ] Dependency injection used (no `new Class()` in services)
- [ ] Liskov Substitution Principle respected

### Testing
- [ ] Unit tests pass: `docker compose exec php vendor/bin/phpunit -c /var/www/extensions/stripe/tests/phpunit.xml --testsuite Unit --group sprint-6`
- [ ] Integration tests pass: `docker compose exec php vendor/bin/phpunit -c /var/www/extensions/stripe/tests/phpunit.xml --testsuite Integration --group sprint-6 --bootstrap=/var/www/source/bootstrap.php`
- [ ] Pre-commit check passes: `./source/extensions/stripe/bin/pre-commit-check.sh`
- [ ] Full pre-commit check passes: `./source/extensions/stripe/bin/pre-commit-check.sh --full`

### Documentation
- [ ] Sprint file moved to `done/`
- [ ] Report created: `sprint-6-contract-aware-webhooks-REPORT.md`
- [ ] `status.md` updated

---

## Pre-Implementation Review Required

**STOP! Before writing code, answer these questions:**

1. **Does `ContractRepositoryInterface` have `findByProviderOrderId()`?**
   - If NO → Add to interface first
   - If YES → Check if implementation exists

2. **Can we modify existing `handlePaymentIntentSucceeded()` instead of new method?**
   - Prefer modification over addition

3. **Is there existing contract fulfillment logic we can reuse?**
   - Check `PaymentContract::fulfill()` method
   - Check existing event handlers

4. **Will this break existing tests?**
   - Run existing tests BEFORE changes
   - Ensure backward compatibility

---

## References

- Architecture: `docs/payment-component/05-02-webhooks-with-smart-contracts.md`
- PlantUML diagrams: `docs/payment-component/daniil_dev_log/20251203/puml/`
- Previous webhooks work: `docs/payment-component/daniil_dev_log/20251202/`
- Previous sprint reports: `docs/payment-component/daniil_dev_log/20251203/done/`

---

**Status:** Ready for implementation
**Next Action:**
1. Review existing code (ContractRepository, PaymentContract)
2. Create failing tests (Phase 1: RED)
3. Run `pre-commit-check.sh` before and after changes
