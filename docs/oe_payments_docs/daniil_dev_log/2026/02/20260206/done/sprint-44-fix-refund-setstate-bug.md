# Sprint 44: Fix Refund setState() Bug (STRP-89)

**Priority:** CRITICAL (blocks all admin refund operations)
**Estimated Effort:** 2-3 hours
**Approach:** TDD-first, SOLID, DRY, Liskov-safe
**Ref:** `reports/02-refund-setstate-bug-analysis.md`

---

## Problem Statement

`StripeRefundRequestHandler::updateContractState()` calls `$contract->setState('REFUNDED')` — a method that does not exist on `PaymentContractInterface`. The Stripe API refund succeeds (money returned), but the post-refund contract update crashes, showing an error in admin. PHPStan caught this but line 66 of `tests/PhpStan/phpstan.neon` suppresses it:

```yaml
# PaymentContractInterface setState method - to be added
- '#Call to an undefined method.*PaymentContractInterface::setState#'
```

This is a static analysis suppression hiding a runtime bug.

---

## Solution: Option A — Use Existing Refund Tracking API

Replace `setState('REFUNDED')` with the contract's existing refund tracking methods (`addRefundedAmount()`, `setRefundedAt()`), consistent with `ChargeRefundedHandler` (DRY). Add idempotency guard to prevent double-counting when webhook also fires.

---

## Phases

### Phase 1: RED — Write Failing Tests (TDD-first)

**Goal:** Tests that prove the bug exists and define the correct behavior.

#### 1.1 Unit Test: Verify `updateContractState` uses interface-safe methods

**File:** `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php`

Add the following test methods. All tests mock `ContractRepositoryInterface` (returns `PaymentContractInterface`) and `RefundServiceInterface` (returns successful `RefundResponse`). The handler's `handle()` calls `oxNew(Order::class)` internally, so we cannot drive the full flow in unit tests. Instead, we test `updateContractState` behavior indirectly by creating a **testable subclass** that exposes the private method, OR we refactor `updateContractState` to be `protected` for testability (justified: it's a discrete domain operation, not an implementation detail).

**Tests to add:**

```
testSuccessfulRefundUpdatesContractRefundTracking
  - Arrange: mock contract with getAmount() -> 99.99, getRefundedAmount() -> null
  - Act: call updateContractState with valid contractId, fullRefund=true
  - Assert: contract->addRefundedAmount(99.99) called once
  - Assert: contract->setRefundedAt() called once with DateTimeInterface
  - Assert: contractRepository->save() called once

testSkipsContractUpdateWhenContractIdIsNull
  - Arrange: event with no contractId
  - Act: call updateContractState
  - Assert: contractRepository->findById() never called

testSkipsContractUpdateWhenNotFullRefund
  - Arrange: event with contractId but partial refund (amount != null)
  - Act: call updateContractState
  - Assert: contractRepository->findById() never called

testSkipsContractUpdateWhenContractNotFound
  - Arrange: contractRepository->findById() returns null
  - Act: call updateContractState
  - Assert: no exception, no save() call

testIdempotencyGuardSkipsAlreadyRefundedContract
  - Arrange: mock contract with getRefundedAmount() -> 99.99 (already refunded)
  - Act: call updateContractState
  - Assert: contract->addRefundedAmount() never called
  - Assert: contractRepository->save() never called
```

**Why these tests matter:**
- They program against `PaymentContractInterface`, not `PaymentContract` (Liskov)
- They verify the handler uses only methods defined on the interface
- They catch the exact bug: calling a non-existent method would fail mock expectations

#### 1.2 Approach: Testable Subclass Pattern

Since `updateContractState` is `private` and `handle()` requires OXID's `oxNew()`, create a minimal testable subclass in the test file:

```php
/**
 * Testable subclass exposing updateContractState for unit testing.
 * This avoids making the production method public while enabling
 * isolated testing without OXID framework dependencies.
 */
class TestableStripeRefundRequestHandler extends StripeRefundRequestHandler
{
    // Change visibility: private -> protected in production class
    // Then override here to make public for testing
    public function testUpdateContractState(StripeRefundRequestEvent $event): void
    {
        // Calls parent's protected method via reflection or direct call
        $this->updateContractState($event);
    }
}
```

**Prerequisite:** Change `updateContractState` visibility from `private` to `protected` in the production handler. This is justified:
- The method is a discrete domain operation (contract state update after refund)
- Subclass handlers may need to override refund-to-contract behavior
- Follows Open/Closed principle — extensible without modifying the class

#### 1.3 Run tests — confirm RED

```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --testsuite Unit \
  --filter "testSuccessfulRefundUpdatesContractRefundTracking|testSkipsContractUpdate|testIdempotencyGuard"
```

All 5 new tests must FAIL (method calls `setState` which doesn't exist on mock).

---

### Phase 2: GREEN — Fix the Handler

**Goal:** Make all tests pass with minimal, correct changes.

#### 2.1 Fix `StripeRefundRequestHandler::updateContractState()`

**File:** `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php`

**Change 1 — Visibility:** `private function updateContractState` → `protected function updateContractState`

**Change 2 — Method body (lines 208-222):**

Replace:
```php
private function updateContractState(StripeRefundRequestEvent $event): void
{
    $contractId = $event->getContractId();
    if ($contractId === null || !$event->isFullRefund()) {
        return;
    }

    $contract = $this->contractRepository->findById($contractId);
    if ($contract === null) {
        return;
    }

    $contract->setState('REFUNDED');
    $this->contractRepository->save($contract);
}
```

With:
```php
protected function updateContractState(StripeRefundRequestEvent $event): void
{
    $contractId = $event->getContractId();
    if ($contractId === null || !$event->isFullRefund()) {
        return;
    }

    $contract = $this->contractRepository->findById($contractId);
    if ($contract === null) {
        return;
    }

    // Idempotency guard: skip if webhook already recorded the refund
    $currentRefund = $contract->getRefundedAmount();
    if ($currentRefund !== null && $currentRefund >= 0.01) {
        return;
    }

    $contract->addRefundedAmount($contract->getAmount());
    $contract->setRefundedAt(new \DateTimeImmutable());
    $this->contractRepository->save($contract);
}
```

**Why this is correct:**
- Uses `addRefundedAmount()` + `setRefundedAt()` — methods on `PaymentContractInterface` (Liskov-safe)
- Consistent with `ChargeRefundedHandler` webhook handler (DRY)
- Idempotency guard prevents double-counting when webhook fires after admin refund
- No changes to `payment-component` needed

#### 2.2 Run tests — confirm GREEN

```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --testsuite Unit \
  --filter "testSuccessfulRefundUpdatesContractRefundTracking|testSkipsContractUpdate|testIdempotencyGuard"
```

All 5 new tests must PASS.

---

### Phase 3: Remove PHPStan Suppression (the root prevention fix)

**Goal:** Ensure PHPStan catches this class of bug in the future.

#### 3.1 Remove the ignore rule

**File:** `tests/PhpStan/phpstan.neon`, line 65-66

Remove:
```yaml
        # PaymentContractInterface setState method - to be added
        - '#Call to an undefined method.*PaymentContractInterface::setState#'
```

**Why this matters:**
- This suppression hid the bug from static analysis
- Removing it ensures PHPStan (level 6, run at `--level=max` in pre-commit) will catch any future calls to non-existent methods on `PaymentContractInterface`
- This is the **structural prevention** — without this suppression, this bug class cannot recur

#### 3.2 Run PHPStan — confirm clean

```bash
docker compose exec -w /var/www/extensions/stripe -T php \
  vendor/bin/phpstan analyse -c tests/PhpStan/phpstan.neon \
  --level=max src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php --memory-limit=1G
```

Must pass with 0 errors. If the fix is correct, removing the suppression produces no new errors because `setState()` is no longer called.

---

### Phase 4: Full Validation

#### 4.1 Run full pre-commit check

```bash
./bin/pre-commit-check.sh --full
```

Must pass all 4 checks:
- [ ] PHP CodeSniffer (PSR-12)
- [ ] PHPUnit (Unit + Integration)
- [ ] PHPStan (level max)
- [ ] PHPMD

#### 4.2 Verify no regressions

Existing test count should remain 805+ (with 5 new tests = 810+). No existing tests should break.

---

## Files Changed

| File | Change | Lines |
|------|--------|-------|
| `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` | Fix `updateContractState()`: replace `setState('REFUNDED')` with `addRefundedAmount()` + `setRefundedAt()` + idempotency guard. Change visibility `private` → `protected`. | ~15 |
| `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php` | Add 5 new test methods + testable subclass | ~120 |
| `tests/PhpStan/phpstan.neon` | Remove `setState` suppression (lines 65-66) | -2 |

**Total:** ~135 lines added, ~17 lines modified, 2 lines removed.
**No changes to `payment-component`.** All fixes are in the Stripe module.

---

## Principles Applied

| Principle | How |
|-----------|-----|
| **TDD** | Tests written first (Phase 1), then implementation (Phase 2) |
| **SRP** | `updateContractState` has one job: record refund on contract |
| **OCP** | `protected` visibility allows extension without modification |
| **LSP** | All calls go through `PaymentContractInterface`, not concrete class |
| **DIP** | Handler depends on `ContractRepositoryInterface` abstraction |
| **DRY** | Same pattern as `ChargeRefundedHandler` — uses existing API, not a new mechanism |
| **Static Analysis as Safety Net** | PHPStan suppression removed — future violations caught at commit time |

---

## Verification Checklist

- [ ] 5 new unit tests written and passing
- [ ] `setState('REFUNDED')` removed from handler
- [ ] Uses `addRefundedAmount()` + `setRefundedAt()` (interface methods)
- [ ] Idempotency guard prevents double-counting
- [ ] PHPStan ignore rule for `setState` removed
- [ ] PHPStan passes clean on handler file
- [ ] `./bin/pre-commit-check.sh --full` passes all checks
- [ ] No changes to `payment-component` package
- [ ] Test count increased (805 → 810+)

---

## Risk Assessment

| Risk | Mitigation |
|------|-----------|
| Webhook + admin double-count | Idempotency guard checks `getRefundedAmount() >= 0.01` before writing |
| Partial refund via Stripe Dashboard | Only triggers on `isFullRefund()` — partial refunds skip this code |
| `protected` visibility leak | Method only exposed in test subclass; no other subclass in production |
| Existing integration tests break | No behavior change for successful path — same contract data is written, just via correct API |
