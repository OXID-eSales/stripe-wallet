# Sprint 1: Add AUTHORIZED State to ContractState

**Status:** PENDING
**Priority:** HIGH
**Estimated Effort:** 1 hour

---

## Objective

Add the `AUTHORIZED` state to the contract state machine to support delayed/manual capture workflow.

---

## Current State Machine

```
DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
                                              ↘ CANCELLED
                                              ↘ EXPIRED
                                              ↘ FAILED
```

## Target State Machine

```
DRAFT → PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED
            │                                               ↘ CANCELLED
            │                                               ↘ EXPIRED
            │                                               ↘ FAILED
            │
            └─────────────────→ READY_TO_COMMIT (automatic capture)
```

---

## Tasks

### 1. Update ContractState.php

**File:** `src/Component/Contract/ContractState.php`

```php
// Add to VALID_STATES array
private const VALID_STATES = [
    'draft',
    'pending',
    'authorized',  // NEW
    'ready_to_commit',
    'committed',
    'fulfilled',
    'cancelled',
    'expired',
    'failed',
];

// Add factory method
public static function authorized(): self
{
    return new self('authorized');
}

// Add checker method
public function isAuthorized(): bool
{
    return $this->value === 'authorized';
}
```

### 2. Update PaymentContract.php

**File:** `src/Component/Contract/PaymentContract.php`

Add method to transition to AUTHORIZED state:

```php
public function authorize(): void
{
    if (!$this->state->isPending()) {
        throw new InvalidStateTransitionException(
            "Cannot authorize contract in state: {$this->state->getValue()}"
        );
    }

    $this->state = ContractState::authorized();
    $this->updatedAt = new DateTimeImmutable();
}

public function captureAuthorization(): void
{
    if (!$this->state->isAuthorized()) {
        throw new InvalidStateTransitionException(
            "Cannot capture authorization for contract in state: {$this->state->getValue()}"
        );
    }

    $this->state = ContractState::readyToCommit();
    $this->updatedAt = new DateTimeImmutable();
}
```

### 3. Create Unit Tests (TDD - Write First!)

**File:** `tests/Unit/Component/Contract/ContractStateTest.php`

```php
public function testAuthorizedStateCanBeCreated(): void
{
    $state = ContractState::authorized();

    $this->assertTrue($state->isAuthorized());
    $this->assertFalse($state->isTerminal());
    $this->assertEquals('authorized', $state->getValue());
}

public function testAuthorizedStateIsNotTerminal(): void
{
    $state = ContractState::authorized();

    $this->assertFalse($state->isTerminal());
}

public function testCanTransitionFromPendingToAuthorized(): void
{
    $state = ContractState::pending();

    $this->assertFalse($state->isAuthorized());
    // Contract-level transition test
}
```

**File:** `tests/Unit/Component/Contract/PaymentContractTest.php`

```php
public function testContractCanTransitionToAuthorizedState(): void
{
    $contract = new PaymentContract(
        shopId: 1,
        userId: 'user-123',
        basketSnapshot: $this->createBasketSnapshot()
    );

    $contract->transitionToPending();
    $contract->authorize();

    $this->assertTrue($contract->getState()->isAuthorized());
}

public function testCannotAuthorizeNonPendingContract(): void
{
    $contract = new PaymentContract(
        shopId: 1,
        userId: 'user-123',
        basketSnapshot: $this->createBasketSnapshot()
    );

    // Contract is in DRAFT state
    $this->expectException(InvalidStateTransitionException::class);
    $contract->authorize();
}

public function testCaptureAuthorizationTransitionsToReadyToCommit(): void
{
    $contract = new PaymentContract(
        shopId: 1,
        userId: 'user-123',
        basketSnapshot: $this->createBasketSnapshot()
    );

    $contract->transitionToPending();
    $contract->authorize();
    $contract->captureAuthorization();

    $this->assertTrue($contract->getState()->isReadyToCommit());
}
```

---

## Acceptance Criteria

- [ ] `ContractState::authorized()` factory method exists
- [ ] `ContractState::isAuthorized()` checker method exists
- [ ] `AUTHORIZED` is not a terminal state
- [ ] `PaymentContract::authorize()` transitions from PENDING to AUTHORIZED
- [ ] `PaymentContract::captureAuthorization()` transitions from AUTHORIZED to READY_TO_COMMIT
- [ ] Cannot transition to AUTHORIZED from non-PENDING states
- [ ] All existing tests still pass
- [ ] New unit tests pass
- [ ] PHPStan level 6 passes
- [ ] PSR-12 code style passes

---

## Test Commands

```bash
# Run ContractState tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Component/Contract/ContractStateTest.php

# Run PaymentContract tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Component/Contract/PaymentContractTest.php

# Run all unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
```

---

## Dependencies

- None (this is the foundation for other sprints)

---

## Notes

- The AUTHORIZED state represents a payment that has been authorized by the payment provider but not yet captured
- In Stripe terms: `PaymentIntent.status = 'requires_capture'`
- Authorization typically expires after 7 days (Stripe default)
- Future sprint may add EXPIRED handling for timed-out authorizations
