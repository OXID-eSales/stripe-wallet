# Sprint 2: Contract Condition Handlers Implementation

**Date:** 2026-01-20
**Priority:** Medium
**Estimated Effort:** 8-12 hours
**Status:** IN PROGRESS

---

## Q&A Decisions (2026-01-20)

| Decision | Choice |
|----------|--------|
| Handlers needed | All 3 (Stock Reserve, Stock Release, Fraud Check) |
| Stock tracking | Direct OXSTOCK manipulation, no new table |
| Fraud check | Stripe Radar score, interface in component, impl in stripe |
| Fraud outcome | Pass/Fail only (no manual review) |
| Fraud threshold | 0.7 default |
| Fraud failure action | Cancel contract |
| Stock reserve timing | On DRAFT (before payment, during contract creation) |
| Stock reserve behavior | Synchronous, fail contract creation if insufficient stock |
| Stock release trigger | All terminal states except FULFILLED |
| StockService interface | Contract-aware (`reserveForContract`, `releaseForContract`) |
| Stock release failure | Throw exception (strict consistency) |
| Stock code location | All in payment-component (provider-independent) |
| Fraud code location | Interface in component, implementation in stripe |
| Configuration | Admin toggles: `bEnableStockReservation`, `bEnableFraudCheck` |
| Stock OFF behavior | Don't touch OXSTOCK, let OXID handle normally |

---

## Core Development Principles

All code in this sprint MUST follow:

| Principle | Requirement |
|-----------|-------------|
| **TDD-First** | Write failing tests BEFORE implementation. Red → Green → Refactor |
| **SOLID** | Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion |
| **Liskov Substitution** | Subtypes must be substitutable for their base types |
| **Dependency Injection** | Depend on abstractions, not concretions. Inject dependencies via constructor |
| **DRY** | Don't Repeat Yourself. Extract common logic to shared methods/classes |
| **Clean Code** | Meaningful names, small functions (15-25 lines), early returns (no else), single responsibility per method |
| **No Over-Engineering** | Only add what's needed now. No speculative features or premature abstractions |

### Testing Commands

Run from `payment-component/` or `stripe/` directory:

```bash
# Quick check (unit tests + style checks)
./bin/pre-commit-check.sh

# Full check (unit tests + integration tests + style checks)
./bin/pre-commit-check.sh --full
```

---

## Executive Summary

The payment-component has placeholder handlers for contract conditions that were designed but never fully implemented:

1. **StockReservationHandler** - Reserve inventory when contract is created
2. **StockReleaseHandler** - Release inventory when contract is cancelled/expired
3. **FraudCheckHandler** - Run fraud checks as a contract condition

These handlers implement the **Contract Condition Pattern** from the Smart-Contract Architecture, where contracts have conditions that must be fulfilled before commitment.

---

## Architecture Context

### Contract Condition Pattern

From `architecture/01-architecture-layers.md`:

```
Contract Lifecycle: DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED

Conditions:
- payment_authorized (required)
- fraud_check (optional)
- stock_reserved (optional)
```

Each condition is a gate that must be fulfilled before the contract can transition to `READY_TO_COMMIT`. The handlers listen to contract events and fulfill their respective conditions.

### Event Flow

```
PaymentInitiatedEvent
    ↓
ContractCreationHandler (creates contract with conditions)
    ↓
ContractCreatedEvent
    ↓
┌─────────────────────────────────────────────────────────────┐
│ Parallel Condition Resolution:                               │
│  • PaymentAuthorizationHandler → fulfills payment_authorized │
│  • FraudCheckHandler → fulfills fraud_check                  │
│  • StockReservationHandler → fulfills stock_reserved         │
└─────────────────────────────────────────────────────────────┘
    ↓
(when all conditions fulfilled)
    ↓
ContractReadyToCommitEvent
```

### Cleanup Flow

```
ContractCancelledEvent / ContractExpiredEvent
    ↓
ContractCleanupHandler
    ↓
StockReleaseHandler (releases reserved inventory)
```

---

## Implementation Plan

### Handler 1: StockReservationHandler

**Purpose:** Reserve inventory for basket items when contract enters PENDING state.

**Listens to:** `ContractTransitionedToPendingEvent`

**Condition:** `stock_reserved`

#### TDD Test Cases

```php
class StockReservationHandlerTest extends TestCase
{
    // Happy path
    public function testReservesStockForAllBasketItems(): void
    public function testFulfillsStockReservedCondition(): void
    public function testSavesContractAfterFulfillment(): void

    // Edge cases
    public function testSkipsIfStockReservedConditionNotRequired(): void
    public function testSkipsIfContractAlreadyHasStockReserved(): void
    public function testSkipsIfContractInTerminalState(): void

    // Error handling
    public function testFailsContractWhenInsufficientStock(): void
    public function testHandlesPartialStockAvailability(): void
    public function testRollsBackOnStockServiceException(): void

    // Integration
    public function testDispatchesStockReservedEvent(): void
}
```

#### Interface Design (SOLID - Interface Segregation)

```php
// Contract-aware interface for stock operations (Q&A Decision)
interface StockServiceInterface
{
    /**
     * Reserve stock for all items in contract's basket snapshot.
     * Decrements OXARTICLES.OXSTOCK directly.
     *
     * @param PaymentContractInterface $contract Contract with basket snapshot
     * @throws InsufficientStockException If any item has insufficient stock
     */
    public function reserveForContract(PaymentContractInterface $contract): void;

    /**
     * Release reserved stock for contract.
     * Increments OXARTICLES.OXSTOCK directly.
     *
     * @param PaymentContractInterface $contract Contract to release stock for
     * @throws StockReleaseException If release fails (strict consistency)
     */
    public function releaseForContract(PaymentContractInterface $contract): void;

    /**
     * Check if all items in contract's basket have sufficient stock.
     *
     * @param PaymentContractInterface $contract Contract to check
     * @return bool True if all items available
     */
    public function hasAvailableStock(PaymentContractInterface $contract): bool;
}
```

#### Handler Implementation (Clean Code)

```php
class StockReservationHandler implements HandlerInterface
{
    private const CONDITION_TYPE = ContractCondition::TYPE_STOCK_RESERVED;

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly StockServiceInterface $stockService,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public static function getHandledEventClass(): string
    {
        return ContractTransitionedToPendingEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractTransitionedToPendingEvent) {
            return;
        }

        $contract = $event->getContract();

        if (!$this->shouldReserveStock($contract)) {
            return;
        }

        $this->reserveStockForContract($contract);
    }

    private function shouldReserveStock(PaymentContractInterface $contract): bool
    {
        // Early returns (Clean Code - no else)
        if ($contract->getState()->isTerminal()) {
            return false;
        }

        if (!$contract->hasCondition(self::CONDITION_TYPE)) {
            return false;
        }

        if ($contract->isConditionFulfilled(self::CONDITION_TYPE)) {
            return false;
        }

        return true;
    }

    private function reserveStockForContract(PaymentContractInterface $contract): void
    {
        $basketSnapshot = $contract->getBasketSnapshot();
        $items = $this->extractItemsFromBasket($basketSnapshot);

        try {
            $result = $this->stockService->reserve($items, $contract->getId());
            $this->fulfillCondition($contract, $result);
        } catch (InsufficientStockException $e) {
            $this->handleInsufficientStock($contract, $e);
        }
    }

    // ... additional private methods
}
```

---

### Handler 2: StockReleaseHandler

**Purpose:** Release reserved inventory when contract is cancelled or expired.

**Listens to:** `ContractTerminatedEventInterface` (implemented by `ContractCancelledEvent`, `ContractExpiredEvent`)

#### TDD Test Cases

```php
class StockReleaseHandlerTest extends TestCase
{
    // Happy path
    public function testReleasesStockOnContractCancelled(): void
    public function testReleasesStockOnContractExpired(): void

    // Edge cases
    public function testSkipsIfNoStockWasReserved(): void
    public function testSkipsIfStockAlreadyReleased(): void
    public function testHandlesFulfilledContractsGracefully(): void

    // Error handling
    public function testLogsErrorWhenReleaseFailsButDoesNotThrow(): void

    // Idempotency
    public function testIsIdempotentOnMultipleCalls(): void
}
```

#### Handler Implementation

```php
class StockReleaseHandler implements HandlerInterface
{
    public function __construct(
        private readonly StockServiceInterface $stockService,
        private readonly LoggerInterface $logger
    ) {}

    public static function getHandledEventClass(): string
    {
        return ContractTerminatedEventInterface::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractTerminatedEventInterface) {
            return;
        }

        $contract = $event->getContract();

        // Don't release stock for fulfilled contracts (items were shipped)
        if ($contract->getState()->isFulfilled()) {
            return;
        }

        $this->releaseStockSafely($contract);
    }

    private function releaseStockSafely(PaymentContractInterface $contract): void
    {
        try {
            $this->stockService->release($contract->getId());
        } catch (\Throwable $e) {
            // Log but don't throw - stock release failure shouldn't block cancellation
            $this->logger->error('Failed to release stock for contract', [
                'contract_id' => $contract->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

---

### Handler 3: FraudCheckHandler

**Purpose:** Run fraud checks when contract enters PENDING state.

**Listens to:** `ContractTransitionedToPendingEvent`

**Condition:** `fraud_check`

#### TDD Test Cases

```php
class FraudCheckHandlerTest extends TestCase
{
    // Happy path
    public function testRunsFraudCheckForContract(): void
    public function testFulfillsConditionWhenCheckPasses(): void
    public function testFailsContractWhenFraudDetected(): void

    // Edge cases
    public function testSkipsIfFraudCheckConditionNotRequired(): void
    public function testSkipsIfAlreadyChecked(): void
    public function testHandlesAsyncFraudCheckResult(): void

    // Scoring thresholds
    public function testPassesWhenScoreBelowThreshold(): void
    public function testFailsWhenScoreAboveThreshold(): void
    public function testHoldsForManualReviewWhenScoreInGrayZone(): void

    // Error handling
    public function testHandlesFraudServiceTimeout(): void
    public function testFallsBackToAllowWhenServiceUnavailable(): void
}
```

#### Interface Design (Q&A: Pass/Fail only, no manual review)

```php
// Interface in payment-component, implementation in stripe
interface FraudCheckServiceInterface
{
    /**
     * Run fraud check for a contract.
     * Uses Stripe Radar score with configurable threshold (default 0.7).
     *
     * @return FraudCheckResult Pass/Fail result with score
     */
    public function check(PaymentContractInterface $contract): FraudCheckResult;
}

// Value object for result (simplified - no manual review)
final class FraudCheckResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly float $score,
        public readonly string $reason = ''
    ) {}

    public static function passed(float $score): self
    {
        return new self(true, $score);
    }

    public static function failed(float $score, string $reason): self
    {
        return new self(false, $score, $reason);
    }
}
```

#### Handler Implementation

```php
class FraudCheckHandler implements HandlerInterface
{
    private const CONDITION_TYPE = ContractCondition::TYPE_FRAUD_CHECK;

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly FraudCheckServiceInterface $fraudCheckService,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public static function getHandledEventClass(): string
    {
        return ContractTransitionedToPendingEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractTransitionedToPendingEvent) {
            return;
        }

        $contract = $event->getContract();

        if (!$this->shouldRunFraudCheck($contract)) {
            return;
        }

        $this->runFraudCheck($contract, $event->getContext());
    }

    private function shouldRunFraudCheck(PaymentContractInterface $contract): bool
    {
        if ($contract->getState()->isTerminal()) {
            return false;
        }

        if (!$contract->hasCondition(self::CONDITION_TYPE)) {
            return false;
        }

        if ($contract->isConditionFulfilled(self::CONDITION_TYPE)) {
            return false;
        }

        return true;
    }

    private function runFraudCheck(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void {
        try {
            $result = $this->fraudCheckService->check($contract);
            $this->processResult($contract, $result, $context);
        } catch (\Throwable $e) {
            $this->handleFraudCheckError($contract, $e);
        }
    }

    private function processResult(
        PaymentContractInterface $contract,
        FraudCheckResult $result,
        EventContextInterface $context
    ): void {
        if ($result->passed) {
            $contract->fulfillCondition(self::CONDITION_TYPE, [
                'score' => $result->score,
                'checkedAt' => (new \DateTimeImmutable())->format('c'),
            ]);
            $this->contractRepository->save($contract);
            return;
        }

        if ($result->requiresManualReview) {
            $this->holdForManualReview($contract, $result);
            return;
        }

        $this->failContractForFraud($contract, $result);
    }

    // ... additional private methods
}
```

---

## Service Layer Implementation

### StockService (Adapter for OXID)

```php
interface StockServiceInterface
{
    public function reserve(array $items, string $reservationId): StockReservationResult;
    public function release(string $reservationId): void;
    public function checkAvailability(array $items): StockAvailabilityResult;
}

// OXID implementation
class OxidStockService implements StockServiceInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function reserve(array $items, string $reservationId): StockReservationResult
    {
        // Use OXID's oxarticles.OXSTOCK field
        // Store reservation in oe_payments_stock_reservations table
    }

    public function release(string $reservationId): void
    {
        // Restore stock and remove reservation record
    }
}
```

### FraudCheckService (Stripe Radar Integration)

```php
// For Stripe, fraud check is built into payment flow via Radar
class StripeFraudCheckService implements FraudCheckServiceInterface
{
    public function check(PaymentContractInterface $contract): FraudCheckResult
    {
        // Stripe Radar scores are returned with PaymentIntent
        // This service retrieves and evaluates the risk score
        $riskScore = $this->getStripeRiskScore($contract);

        if ($riskScore < 0.3) {
            return FraudCheckResult::passed($riskScore);
        }

        if ($riskScore < 0.7) {
            return FraudCheckResult::requiresReview($riskScore, ['Elevated risk score']);
        }

        return FraudCheckResult::failed($riskScore, ['High risk score']);
    }
}
```

---

## Database Schema

### Stock Reservations Table

```sql
CREATE TABLE oe_payments_stock_reservations (
    OXID CHAR(32) NOT NULL PRIMARY KEY,
    OXCONTRACTID CHAR(32) NOT NULL,
    OXARTID CHAR(32) NOT NULL,
    OXQUANTITY INT NOT NULL,
    OXCREATED DATETIME NOT NULL,
    OXRELEASED DATETIME NULL,

    INDEX idx_contract (OXCONTRACTID),
    INDEX idx_article (OXARTID),
    FOREIGN KEY (OXCONTRACTID) REFERENCES oe_payments_contract(OXID)
);
```

---

## Configuration

### Module Settings

```php
// metadata.php additions
'settings' => [
    // Stock reservation
    [
        'name' => 'bEnableStockReservation',
        'type' => 'bool',
        'value' => false,
        'group' => 'osc_stripe_features',
    ],
    [
        'name' => 'iStockReservationTimeoutMinutes',
        'type' => 'num',
        'value' => 30,
        'group' => 'osc_stripe_features',
    ],

    // Fraud check
    [
        'name' => 'bEnableFraudCheck',
        'type' => 'bool',
        'value' => false,
        'group' => 'osc_stripe_features',
    ],
    [
        'name' => 'fFraudScoreThreshold',
        'type' => 'num',
        'value' => 0.7,
        'group' => 'osc_stripe_features',
    ],
],
```

---

## Services.yaml Registration

```yaml
# Stock Services
OxidEsales\PaymentComponent\Service\StockServiceInterface:
  class: OxidEsales\PaymentComponent\Service\OxidStockService
  arguments:
    $connection: '@doctrine.dbal.connection'

# Stock Handlers
OxidEsales\PaymentComponent\EventSystem\Handler\StockReservationHandler:
  arguments:
    $contractRepository: '@OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface'
    $stockService: '@OxidEsales\PaymentComponent\Service\StockServiceInterface'
    $eventDispatcher: '@OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface'
  tags:
    - { name: payment.event_handler, priority: 50 }

OxidEsales\PaymentComponent\EventSystem\Handler\StockReleaseHandler:
  arguments:
    $stockService: '@OxidEsales\PaymentComponent\Service\StockServiceInterface'
    $logger: '@Psr\Log\LoggerInterface'
  tags:
    - { name: payment.event_handler }

# Fraud Check Services
OxidEsales\PaymentComponent\Service\FraudCheckServiceInterface:
  class: OxidEsales\Payments\Stripe\Service\StripeFraudCheckService
  arguments:
    $stripeAdapter: '@OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface'

# Fraud Check Handler
OxidEsales\PaymentComponent\EventSystem\Handler\FraudCheckHandler:
  arguments:
    $contractRepository: '@OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface'
    $fraudCheckService: '@OxidEsales\PaymentComponent\Service\FraudCheckServiceInterface'
    $eventDispatcher: '@OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface'
    $logger: '@Psr\Log\LoggerInterface'
  tags:
    - { name: payment.event_handler, priority: 50 }
```

---

## Implementation Phases

### Phase 1: Core Infrastructure (2-3 hours)
1. Define `StockServiceInterface` and `FraudCheckServiceInterface`
2. Create result value objects (`StockReservationResult`, `FraudCheckResult`)
3. Create exception classes (`InsufficientStockException`, `FraudCheckException`)
4. Write unit tests for value objects

### Phase 2: StockReservationHandler (2-3 hours)
1. Write failing tests for `StockReservationHandler`
2. Implement handler following TDD
3. Create `OxidStockService` implementation
4. Write integration tests

### Phase 3: StockReleaseHandler (1-2 hours)
1. Write failing tests for `StockReleaseHandler`
2. Implement handler following TDD
3. Write integration tests for cleanup flow

### Phase 4: FraudCheckHandler (2-3 hours)
1. Write failing tests for `FraudCheckHandler`
2. Implement handler following TDD
3. Create `StripeFraudCheckService` implementation
4. Write integration tests

### Phase 5: Integration & Configuration (1-2 hours)
1. Add database migration for stock reservations
2. Add module configuration options
3. Register services in services.yaml
4. End-to-end testing

---

## Definition of Done

- [ ] All handlers have >90% test coverage
- [ ] All tests pass (unit + integration)
- [ ] PHPStan level 6 passes
- [ ] PSR-12 code style passes
- [ ] Handlers are registered and configurable
- [ ] Documentation updated
- [ ] Stock reservation creates/releases records correctly
- [ ] Fraud check integrates with Stripe Radar

---

## References

- Architecture: `architecture/01-architecture-layers.md`
- Fraud planning: `architecture/08-security-and-fraud.md`
- Contract conditions: `Contract/ContractCondition.php`
- Event system: `EventSystem/EventDispatcher.php`
