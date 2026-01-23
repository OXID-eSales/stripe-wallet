# SPRINT 12: Fix Internal Duplication in StripeOrderCreationHandler

**Date Created:** 2026-01-23
**Date Completed:** 2026-01-23
**Status:** COMPLETED ✓
**Priority:** LOW
**Estimated Effort:** 1 hour
**Actual Effort:** ~15 minutes
**Final Tests:** 804 tests, 2369 assertions (ALL PASSING)
**Dependency:** None (independent sprint)

---

## Core Requirements

**All code must follow:**
- **TDD (Test-Driven Development)** - Write failing tests first, then implementation
- **SOLID Principles** - Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **Clean Code** - Meaningful names, small functions (15-25 lines), no else expressions (use early returns), DRY
- **Dependency Injection** - Depend on abstractions, not concretions
- **PSR-12** code style, **PHPStan level 6** compliance
- **DRY** do not repeat yourself - extract common code

---

## Development Environment

**Docker Environment:** All tests run inside Docker from project root.

**Running Tests:**
```bash
# Pre-commit check (Unit tests + style)
./bin/pre-commit-check.sh

# Full check with Integration tests (REQUIRED before completing sprint)
./bin/pre-commit-check.sh --full

# Single test file
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter "StripeOrderCreationHandler"
```

---

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Pattern | **Extract method** | Internal refactoring, no new service needed |
| Scope | **Minimal change** | Only extract duplicated code, no API changes |
| Testing | **Existing tests** | No new tests needed, just verify existing pass |

---

## Objective

Fix internal code duplication in `StripeOrderCreationHandler` by extracting common logic into a private method. This is a minor cleanup sprint.

---

## Problem Statement

`StripeOrderCreationHandler` (337 lines) has internal duplication:

**In `handleExistingOrder()` (lines 249-264):**
```php
$contract->commitToOrder($orderId);
$this->contractRepository->save($contract);

$requiresCapture = $context->get('requiresCapture') === true;
if (!$requiresCapture) {
    $this->updateOrderPaidTimestamp($orderId, $contract->getProviderOrderId());
}

$committedEvent = new ContractCommittedEvent($contract, $context, $orderId);
$this->eventDispatcher->dispatch($committedEvent);
```

**In `handlePostOrderCreation()` (lines 271-284):**
```php
$contract->commitToOrder($orderId);
$this->contractRepository->save($contract);

$requiresCapture = $context->get('requiresCapture') === true;
if (!$requiresCapture) {
    $this->updateOrderPaidTimestamp($orderId, $contract->getProviderOrderId());
}

$committedEvent = new ContractCommittedEvent($contract, $context, $orderId);
$this->eventDispatcher->dispatch($committedEvent);
```

**~15 lines of identical code!**

---

## Implementation Plan

### Phase 1: Extract Common Method

Create a new private method `commitContractAndDispatch()`:

```php
/**
 * Commit contract to order and dispatch ContractCommittedEvent.
 *
 * Sprint 12: Extracted to eliminate internal duplication.
 */
private function commitContractAndDispatch(
    \OxidEsales\PaymentComponent\Contract\PaymentContractInterface $contract,
    \OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface $context,
    string $orderId
): void {
    // Commit contract
    $contract->commitToOrder($orderId);
    $this->contractRepository->save($contract);

    // Update OXPAID only if automatic capture
    $requiresCapture = $context->get('requiresCapture') === true;
    if (!$requiresCapture) {
        $this->logEvent('commitContractAndDispatch: Updating OXPAID (automatic capture)');
        $this->updateOrderPaidTimestamp($orderId, $contract->getProviderOrderId());
    } else {
        $this->logEvent('commitContractAndDispatch: Skipping OXPAID (manual capture mode)');
    }

    // Dispatch event
    $committedEvent = new ContractCommittedEvent($contract, $context, $orderId);
    $this->eventDispatcher->dispatch($committedEvent);
}
```

### Phase 2: Update Calling Methods

**Update `handleExistingOrder()`:**

```php
private function handleExistingOrder(
    \OxidEsales\PaymentComponent\Contract\PaymentContractInterface $contract,
    \OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface $context,
    string $orderId
): void {
    // Load order to get order number
    /** @var \OxidEsales\Eshop\Application\Model\Order $order */
    $order = \oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
    $orderNumber = null;
    if ($order->load($orderId)) {
        $orderNumber = $order->getFieldData('oxordernr');
    }

    $this->logEvent('StripeOrderCreationHandler: Using existing order', [
        'orderId' => $orderId,
        'orderNumber' => $orderNumber,
    ]);

    // Set context for downstream handlers
    $context->set('orderId', $orderId);
    $context->set('orderNumber', $orderNumber);

    // Update order's transaction ID
    $this->updateOrderTransactionId($order, $context);

    // Common commit and dispatch logic
    $this->commitContractAndDispatch($contract, $context, $orderId);
}
```

**Update `handlePostOrderCreation()`:**

```php
private function handlePostOrderCreation(
    \OxidEsales\PaymentComponent\Contract\PaymentContractInterface $contract,
    \OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface $context,
    string $orderId
): void {
    $this->commitContractAndDispatch($contract, $context, $orderId);
}
```

### Phase 3: Extract Transaction ID Update

Optionally, extract the transaction ID update logic:

```php
/**
 * Update order's transaction ID with PaymentIntent ID.
 */
private function updateOrderTransactionId(
    \OxidEsales\Eshop\Application\Model\Order $order,
    \OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface $context
): void {
    $paymentIntentId = $context->get('paymentIntentId');
    $authorizationId = $context->get('authorizationId');
    /** @var string|null $paymentTransactionId */
    $paymentTransactionId = is_string($paymentIntentId) ? $paymentIntentId
        : (is_string($authorizationId) ? $authorizationId : null);

    if ($paymentTransactionId !== null && $order->getId() !== null) {
        $order->oxorder__oxtransid = new \OxidEsales\Eshop\Core\Field(
            $paymentTransactionId,
            \OxidEsales\Eshop\Core\Field::T_RAW
        );
        $order->save();
        $this->logEvent('Updated order transaction ID', [
            'orderId' => $order->getId(),
            'transactionId' => $paymentTransactionId,
        ]);
    }
}
```

---

## Files to Modify

| File | Change | Lines Change |
|------|--------|--------------|
| `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php` | Extract method, reduce duplication | 337 → ~310 (-27) |

---

## Acceptance Criteria

- [ ] `commitContractAndDispatch()` method created
- [ ] `handleExistingOrder()` uses new method
- [ ] `handlePostOrderCreation()` uses new method
- [ ] No duplicated code blocks (DRY principle)
- [ ] All existing tests pass
- [ ] `./bin/pre-commit-check.sh --full` passes

---

## Verification Commands

```bash
# Run handler tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter "StripeOrderCreationHandler"

# Full pre-commit check
./bin/pre-commit-check.sh --full
```

---

## Metrics

| Metric | Before | After | Change |
|--------|--------|-------|--------|
| Handler lines | 337 | ~310 | -8% |
| Internal duplication | ~15 lines | 0 | -100% |

---

## Notes

This is a minor cleanup sprint with low risk. The changes are purely internal refactoring with no impact on external behavior.

---

**Sprint Owner:** TBD
**Review Required:** Yes
**Depends On:** None
