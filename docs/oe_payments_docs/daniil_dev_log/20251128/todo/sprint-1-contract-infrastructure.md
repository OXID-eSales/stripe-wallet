# Sprint 1: Contract Infrastructure

**Sprint Goal:** Establish contract-first foundation with TDD
**Estimated Duration:** 2-3 hours
**Status:** NOT STARTED

---

## Architecture Reference

### PUML Sources
- `puml/04-02-payment-smart-contract-flow-standard.puml` lines 42-179: Contract creation flow
- `puml/05-order-state-contract-machine.puml` lines 59-127: Contract states (DRAFT → PENDING → READY_TO_COMMIT)

### Documentation
- `01-architecture-layers.md`: Component layer structure
- `00-overview.md`: Contract-first pattern overview

---

## Test Environment

```bash
# Run unit tests in Docker
docker compose exec php vendor/bin/phpunit tests/Unit/Component/Contract/

# Run specific test
docker compose exec php vendor/bin/phpunit tests/Unit/Component/Contract/PaymentContractTest.php
```

---

## Tasks

### 1.1 PaymentContract Model
**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/Contract/PaymentContractTest.php
class PaymentContractTest extends TestCase
{
    public function testContractStartsInDraftState(): void;
    public function testContractCanTransitionToPending(): void;
    public function testContractCanAddConditions(): void;
    public function testContractCanFulfillCondition(): void;
    public function testContractDetectsAllConditionsFulfilled(): void;
    public function testContractCanTransitionToReadyToCommit(): void;
    public function testContractCanCommitToOrder(): void;
    public function testContractStoresBasketSnapshot(): void;
}
```

**Implementation:**
- [ ] Create `src/Component/Contract/PaymentContract.php`
- [ ] Implement state machine (DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED)
- [ ] Implement conditions array with fulfillment tracking
- [ ] Implement basket snapshot storage

**Acceptance Criteria:**
- All tests pass
- Contract follows state machine from PUML
- Conditions can be added and fulfilled
- Basket snapshot is immutable

---

### 1.2 ContractRepository
**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/Repository/ContractRepositoryTest.php
class ContractRepositoryTest extends TestCase
{
    public function testCanSaveContract(): void;
    public function testCanFindContractById(): void;
    public function testCanFindContractByProviderSessionId(): void;
    public function testCanUpdateContract(): void;
}
```

**Implementation:**
- [ ] Create `src/Component/Repository/ContractRepositoryInterface.php`
- [ ] Create `src/Component/Repository/ContractRepository.php`
- [ ] For unit tests: Create `tests/.../Support/InMemoryContractRepository.php`

**Acceptance Criteria:**
- CRUD operations work
- Can find by provider session ID (for Stripe return)

---

### 1.3 ContractFactory
**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/Contract/ContractFactoryTest.php
class ContractFactoryTest extends TestCase
{
    public function testCreatesContractFromBasket(): void;
    public function testCapturesBasketSnapshot(): void;
    public function testAddsStandardConditions(): void;
    public function testSetsUserAndShopData(): void;
}
```

**Implementation:**
- [ ] Create `src/Component/Contract/ContractFactoryInterface.php`
- [ ] Create `src/Component/Contract/ContractFactory.php`
- [ ] Implement basket snapshot capture
- [ ] Implement standard conditions setup

**Acceptance Criteria:**
- Factory creates valid contract from basket
- Basket data is snapshotted (frozen)
- Standard conditions are added

---

### 1.4 ContractCreationHandler
**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/EventSystem/Handler/ContractCreationHandlerTest.php
class ContractCreationHandlerTest extends TestCase
{
    public function testCreatesContractOnPaymentInitiatedEvent(): void;
    public function testSetsContractInEventContext(): void;
    public function testDispatchesContractCreatedEvent(): void;
    public function testTransitionsContractToPending(): void;
}
```

**Implementation:**
- [ ] Update/Create `src/Component/EventSystem/Handler/ContractCreationHandler.php`
- [ ] Wire to `PaymentInitiatedEvent`
- [ ] Use ContractFactory
- [ ] Dispatch `ContractCreatedEvent`

**Acceptance Criteria:**
- Handler creates contract via factory
- Contract is saved to repository
- Contract ID is set in context
- ContractCreatedEvent is dispatched

---

### 1.5 Events for Contract Lifecycle
**Status:** [ ] NOT STARTED

**Events to create/verify:**
- [ ] `ContractCreatedEvent` - after contract created
- [ ] `ContractTransitionedToPendingEvent` - contract state change
- [ ] `ContractConditionFulfilledEvent` - single condition fulfilled
- [ ] `ContractReadyToCommitEvent` - all conditions fulfilled
- [ ] `ContractCommittedEvent` - order linked to contract

**Test First:**
```php
// tests/Unit/Component/EventSystem/Event/Contract/ContractEventsTest.php
class ContractEventsTest extends TestCase
{
    public function testContractCreatedEventContainsContract(): void;
    public function testContractReadyToCommitEventContainsContract(): void;
}
```

---

## Definition of Done

- [ ] All tests pass: `docker compose exec php vendor/bin/phpunit tests/Unit/Component/Contract/`
- [ ] All tests pass: `docker compose exec php vendor/bin/phpunit tests/Unit/Component/Repository/ContractRepositoryTest.php`
- [ ] All tests pass: `docker compose exec php vendor/bin/phpunit tests/Unit/Component/EventSystem/Handler/ContractCreationHandlerTest.php`
- [ ] Pre-commit check passes: `./source/extensions/stripe/bin/pre-commit-check.sh`
- [ ] Contract state machine matches PUML diagram
- [ ] Handler extends AbstractHandler (per handler-abstraction-pattern.md)

---

## Files Created/Modified

### New Files
- `src/Component/Contract/PaymentContract.php`
- `src/Component/Contract/PaymentContractInterface.php`
- `src/Component/Contract/ContractFactory.php`
- `src/Component/Contract/ContractFactoryInterface.php`
- `src/Component/Repository/ContractRepository.php`
- `src/Component/Repository/ContractRepositoryInterface.php`
- `src/Component/EventSystem/Event/Contract/ContractCreatedEvent.php`
- `src/Component/EventSystem/Event/Contract/ContractReadyToCommitEvent.php`
- `tests/Unit/Component/Contract/PaymentContractTest.php`
- `tests/Unit/Component/Contract/ContractFactoryTest.php`
- `tests/Unit/Component/Repository/ContractRepositoryTest.php`
- `tests/Unit/Component/EventSystem/Handler/ContractCreationHandlerTest.php`

### Modified Files
- `src/Component/EventSystem/Handler/ContractCreationHandler.php` (if exists)
- `services.yaml` (wire new services)

---

## Notes

- Contract replaces the "order-first" approach from Bartek's controller
- Basket snapshot must be immutable - changes to basket don't affect contract
- Contract ID is used in Stripe metadata, not order ID
