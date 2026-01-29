[← Previous: TICKET-005](SPRINT-1-TICKET-05-sdk-adapter.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md)

---

# TICKET-006: Payment Contract Layer (Smart-Contract Pattern v4.0)

## Summary
Implement the Payment Contract domain layer in `src/Component/Contract/` with aggregate root, entities, value objects, repository, and service. This is the **core innovation** of the v4.0 architecture that separates purchase intent from order fulfillment.

## Priority
**P0 - Critical** (Foundation for Smart-Contract Pattern)

## Story Points
**13 points** (3 days)

## Business Value
Enables the smart-contract pattern where contracts capture purchase intent BEFORE order creation, providing clean separation between payment domain and order domain, eliminating order number gaps, and enabling better rollback handling.

## Architecture Reference
- **[00-overview.md](00-overview.md)** - Smart-contract pattern introduction (v4.0)
- **[01-architecture-layers.md](01-architecture-layers.md)** - Contract domain layer architecture
- **[02-database-and-models.md](02-database-and-models.md)** - Contract database schema

---

## Description

Create the Payment Contract domain layer implementing **Domain-Driven Design (DDD)** principles:

**Core Components:**
- **PaymentContract** (Aggregate Root) - Manages contract lifecycle and owns conditions
- **ContractCondition** (Entity) - Represents fulfillment preconditions
- **BasketSnapshot** (Value Object) - Immutable basket data at contract creation
- **ContractState** (Value Object) - Type-safe state enumeration
- **ContractRepository** - Persistence and queries
- **ContractService** - Business operations (CRUD, state management, cleanup)

**Key Innovation:**
```
Traditional:  User → Order (NOT_FINISHED) → Payment → Order (OK)
                      ↑ Order number assigned here (gaps on failure!)

Smart-Contract: User → Contract (DRAFT) → Conditions → Contract (COMMITTED) → Order created
                                                        ↑ Order number assigned here (NO GAPS!)
```

All code goes in `src/Component/Contract/` as it's provider-agnostic.

---

## Acceptance Criteria

### Must Have
- [ ] **PaymentContract** aggregate root in `src/Component/Contract/PaymentContract.php`
- [ ] **ContractCondition** entity in `src/Component/Contract/ContractCondition.php`
- [ ] **BasketSnapshot** value object in `src/Component/Contract/BasketSnapshot.php`
- [ ] **ContractState** value object in `src/Component/Contract/ContractState.php`
- [ ] **ContractRepository** in `src/Component/Repository/ContractRepository.php`
- [ ] **ContractService** in `src/Component/Service/ContractService.php`
- [ ] State machine implementation (DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED)
- [ ] Terminal states (CANCELLED, EXPIRED, FAILED)
- [ ] Condition tracking (payment_authorized, fraud_check, stock_reserved)
- [ ] Immutable basket snapshot
- [ ] 100% test coverage (pure domain logic)
- [ ] All tests passing
- [ ] PHPStan level 6+ passes

### Should Have
- [ ] Contract expiration logic (24-hour default timeout)
- [ ] Contract cleanup service (expired contracts)
- [ ] Query methods for active contracts
- [ ] Audit trail support

---

## Technical Details

### 1. PaymentContract (Aggregate Root)

```php
<?php
// src/Component/Contract/PaymentContract.php

namespace Osc\Payment\Component\Contract;

use Osc\Payment\Component\Contract\ContractCondition;
use Osc\Payment\Component\Contract\BasketSnapshot;
use Osc\Payment\Component\Contract\ContractState;

/**
 * Payment Contract - Aggregate Root (v4.0)
 *
 * Domain entity that captures purchase intent before order creation.
 * Manages conditions, state transitions, and lifecycle.
 *
 * DDD Pattern: Aggregate Root
 */
final class PaymentContract
{
    // States
    public const STATE_DRAFT = 'draft';
    public const STATE_PENDING = 'pending';
    public const STATE_READY_TO_COMMIT = 'ready_to_commit';
    public const STATE_COMMITTED = 'committed';
    public const STATE_FULFILLED = 'fulfilled';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_EXPIRED = 'expired';
    public const STATE_FAILED = 'failed';

    // Core properties
    private ?string $id = null;
    private int $shopId;
    private string $userId;  // FK to oxuser.OXID
    private ?string $orderId = null;  // FK to oxorder.OXID (NULL until committed!)
    private ContractState $state;
    private BasketSnapshot $basketSnapshot;
    private array $conditions = [];  // ContractCondition[]
    private ?\DateTime $expiresAt = null;
    private ?\DateTime $createdAt = null;
    private ?\DateTime $updatedAt = null;
    private ?\DateTime $fulfilledAt = null;

    // Provider data
    private ?string $provider = null;  // 'stripe', 'paypal', etc.
    private ?string $providerOrderId = null;  // Provider's contract/order ID
    private ?string $providerRedirectUrl = null;

    public function __construct(
        int $shopId,
        string $userId,
        BasketSnapshot $basketSnapshot,
        ?string $id = null
    ) {
        $this->id = $id ?? $this->generateId();
        $this->shopId = $shopId;
        $this->userId = $userId;
        $this->basketSnapshot = $basketSnapshot;
        $this->state = ContractState::draft();
        $this->createdAt = new \DateTime();
        $this->expiresAt = (new \DateTime())->add(new \DateInterval('PT24H'));  // 24 hours
    }

    // ============================================================
    // STATE MACHINE
    // ============================================================

    public function transitionToPending(): void
    {
        if (!$this->state->isDraft()) {
            throw new \DomainException('Can only transition to PENDING from DRAFT state');
        }

        if (empty($this->conditions)) {
            throw new \DomainException('Cannot transition to PENDING without conditions');
        }

        $this->state = ContractState::pending();
        $this->touch();
    }

    public function commitToOrder(string $orderId): void
    {
        if (!$this->state->isReadyToCommit()) {
            throw new \DomainException('Contract must be in READY_TO_COMMIT state to commit');
        }

        if (!$this->areAllConditionsFulfilled()) {
            throw new \DomainException('Cannot commit contract with unfulfilled conditions');
        }

        $this->orderId = $orderId;
        $this->state = ContractState::committed();
        $this->touch();
    }

    public function fulfill(): void
    {
        if (!$this->state->isCommitted()) {
            throw new \DomainException('Contract must be COMMITTED before fulfillment');
        }

        $this->state = ContractState::fulfilled();
        $this->fulfilledAt = new \DateTime();
        $this->touch();
    }

    public function cancel(string $reason = ''): void
    {
        if ($this->state->isTerminal()) {
            throw new \DomainException('Cannot cancel a terminal state contract');
        }

        $this->state = ContractState::cancelled();
        $this->touch();
    }

    public function fail(string $reason): void
    {
        if ($this->state->isTerminal()) {
            throw new \DomainException('Cannot fail a terminal state contract');
        }

        $this->state = ContractState::failed();
        $this->touch();
    }

    public function expire(): void
    {
        if ($this->state->isTerminal()) {
            throw new \DomainException('Cannot expire a terminal state contract');
        }

        $this->state = ContractState::expired();
        $this->touch();
    }

    // ============================================================
    // CONDITION MANAGEMENT
    // ============================================================

    public function addCondition(ContractCondition $condition): void
    {
        if (!$this->state->isDraft()) {
            throw new \DomainException('Cannot add conditions after DRAFT state');
        }

        $this->conditions[] = $condition;
        $this->touch();
    }

    public function fulfillCondition(string $type, array $data = []): void
    {
        $condition = $this->findCondition($type);

        if ($condition === null) {
            throw new \DomainException("Condition type '{$type}' not found");
        }

        $condition->fulfill($data);
        $this->touch();

        // Auto-transition to READY_TO_COMMIT if all conditions fulfilled
        if ($this->areAllConditionsFulfilled() && $this->state->isPending()) {
            $this->state = ContractState::readyToCommit();
        }
    }

    public function failCondition(string $type, string $reason): void
    {
        $condition = $this->findCondition($type);

        if ($condition === null) {
            throw new \DomainException("Condition type '{$type}' not found");
        }

        $condition->fail($reason);
        $this->fail("Condition '{$type}' failed: {$reason}");
        $this->touch();
    }

    public function areAllConditionsFulfilled(): bool
    {
        if (empty($this->conditions)) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (!$condition->isFulfilled()) {
                return false;
            }
        }

        return true;
    }

    private function findCondition(string $type): ?ContractCondition
    {
        foreach ($this->conditions as $condition) {
            if ($condition->getType() === $type) {
                return $condition;
            }
        }

        return null;
    }

    // ============================================================
    // PROVIDER MANAGEMENT
    // ============================================================

    public function setProvider(string $provider, string $providerOrderId, ?string $redirectUrl = null): void
    {
        $this->provider = $provider;
        $this->providerOrderId = $providerOrderId;
        $this->providerRedirectUrl = $redirectUrl;
        $this->touch();
    }

    // ============================================================
    // GETTERS
    // ============================================================

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function getState(): ContractState
    {
        return $this->state;
    }

    public function getStateValue(): string
    {
        return $this->state->getValue();
    }

    public function getBasketSnapshot(): BasketSnapshot
    {
        return $this->basketSnapshot;
    }

    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
    }

    public function getProviderRedirectUrl(): ?string
    {
        return $this->providerRedirectUrl;
    }

    public function getExpiresAt(): ?\DateTime
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    public function getFulfilledAt(): ?\DateTime
    {
        return $this->fulfilledAt;
    }

    public function isExpired(): bool
    {
        if ($this->state->isTerminal()) {
            return false;
        }

        return $this->expiresAt !== null && $this->expiresAt < new \DateTime();
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function touch(): void
    {
        $this->updatedAt = new \DateTime();
    }

    private function generateId(): string
    {
        return uniqid('contract_', true);
    }

    // ============================================================
    // SERIALIZATION (for repository)
    // ============================================================

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shopId' => $this->shopId,
            'userId' => $this->userId,
            'orderId' => $this->orderId,
            'state' => $this->state->getValue(),
            'basketSnapshot' => $this->basketSnapshot->toArray(),
            'conditions' => array_map(fn($c) => $c->toArray(), $this->conditions),
            'provider' => $this->provider,
            'providerOrderId' => $this->providerOrderId,
            'providerRedirectUrl' => $this->providerRedirectUrl,
            'expiresAt' => $this->expiresAt?->format('Y-m-d H:i:s'),
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
            'fulfilledAt' => $this->fulfilledAt?->format('Y-m-d H:i:s'),
        ];
    }

    public static function fromArray(array $data): self
    {
        $contract = new self(
            shopId: $data['shopId'],
            userId: $data['userId'],
            basketSnapshot: BasketSnapshot::fromArray($data['basketSnapshot']),
            id: $data['id']
        );

        $contract->orderId = $data['orderId'] ?? null;
        $contract->state = ContractState::fromValue($data['state']);
        $contract->provider = $data['provider'] ?? null;
        $contract->providerOrderId = $data['providerOrderId'] ?? null;
        $contract->providerRedirectUrl = $data['providerRedirectUrl'] ?? null;

        if (isset($data['conditions'])) {
            $contract->conditions = array_map(
                fn($c) => ContractCondition::fromArray($c),
                $data['conditions']
            );
        }

        if (isset($data['expiresAt'])) {
            $contract->expiresAt = new \DateTime($data['expiresAt']);
        }

        if (isset($data['createdAt'])) {
            $contract->createdAt = new \DateTime($data['createdAt']);
        }

        if (isset($data['updatedAt'])) {
            $contract->updatedAt = new \DateTime($data['updatedAt']);
        }

        if (isset($data['fulfilledAt'])) {
            $contract->fulfilledAt = new \DateTime($data['fulfilledAt']);
        }

        return $contract;
    }
}
```

---

### 2. ContractCondition (Entity)

```php
<?php
// src/Component/Contract/ContractCondition.php

namespace Osc\Payment\Component\Contract;

/**
 * Contract Condition - Entity (v4.0)
 *
 * Represents a fulfillment precondition that must be met
 * before a contract can be committed.
 *
 * DDD Pattern: Entity (owned by PaymentContract aggregate)
 */
final class ContractCondition
{
    // Condition types
    public const TYPE_PAYMENT_AUTHORIZED = 'payment_authorized';
    public const TYPE_FRAUD_CHECK = 'fraud_check';
    public const TYPE_STOCK_RESERVED = 'stock_reserved';
    public const TYPE_COMPLIANCE_CHECK = 'compliance_check';
    public const TYPE_ADDRESS_VALIDATED = 'address_validated';

    // Statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_FAILED = 'failed';

    private string $type;
    private string $status;
    private array $data = [];
    private ?\DateTime $fulfilledAt = null;
    private ?string $failureReason = null;

    public function __construct(string $type)
    {
        $this->validateType($type);
        $this->type = $type;
        $this->status = self::STATUS_PENDING;
    }

    public function fulfill(array $data = []): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new \DomainException("Condition '{$this->type}' is already fulfilled");
        }

        $this->status = self::STATUS_FULFILLED;
        $this->data = $data;
        $this->fulfilledAt = new \DateTime();
    }

    public function fail(string $reason): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new \DomainException("Cannot fail a fulfilled condition");
        }

        $this->status = self::STATUS_FAILED;
        $this->failureReason = $reason;
    }

    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getFulfilledAt(): ?\DateTime
    {
        return $this->fulfilledAt;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    private function validateType(string $type): void
    {
        $validTypes = [
            self::TYPE_PAYMENT_AUTHORIZED,
            self::TYPE_FRAUD_CHECK,
            self::TYPE_STOCK_RESERVED,
            self::TYPE_COMPLIANCE_CHECK,
            self::TYPE_ADDRESS_VALIDATED,
        ];

        if (!in_array($type, $validTypes, true)) {
            throw new \InvalidArgumentException("Invalid condition type: {$type}");
        }
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'status' => $this->status,
            'data' => $this->data,
            'fulfilledAt' => $this->fulfilledAt?->format('Y-m-d H:i:s'),
            'failureReason' => $this->failureReason,
        ];
    }

    public static function fromArray(array $data): self
    {
        $condition = new self($data['type']);
        $condition->status = $data['status'];
        $condition->data = $data['data'] ?? [];
        $condition->failureReason = $data['failureReason'] ?? null;

        if (isset($data['fulfilledAt'])) {
            $condition->fulfilledAt = new \DateTime($data['fulfilledAt']);
        }

        return $condition;
    }
}
```

---

### 3. BasketSnapshot (Value Object)

```php
<?php
// src/Component/Contract/BasketSnapshot.php

namespace Osc\Payment\Component\Contract;

/**
 * Basket Snapshot - Value Object (v4.0)
 *
 * Immutable snapshot of basket contents at contract creation time.
 * Prevents tampering and ensures contract integrity.
 *
 * DDD Pattern: Value Object (immutable)
 */
final class BasketSnapshot
{
    private array $items;
    private array $discounts;
    private float $totalGross;
    private float $totalNet;
    private float $totalVat;
    private string $currency;
    private \DateTime $capturedAt;

    private function __construct(
        array $items,
        array $discounts,
        float $totalGross,
        float $totalNet,
        float $totalVat,
        string $currency
    ) {
        $this->items = $items;
        $this->discounts = $discounts;
        $this->totalGross = $totalGross;
        $this->totalNet = $totalNet;
        $this->totalVat = $totalVat;
        $this->currency = $currency;
        $this->capturedAt = new \DateTime();
    }

    // Factory method from OXID basket
    public static function fromOxidBasket(object $basket): self
    {
        // TODO: Map from OXID Basket object
        // This is a placeholder - actual implementation would extract data from OXID basket

        $items = [];
        $discounts = [];

        return new self(
            items: $items,
            discounts: $discounts,
            totalGross: $basket->getPrice()->getBruttoPrice(),
            totalNet: $basket->getPrice()->getNettoPrice(),
            totalVat: $basket->getPrice()->getVATValue(),
            currency: $basket->getBasketCurrency()->name
        );
    }

    // Getters (no setters - immutable!)
    public function getItems(): array
    {
        return $this->items;
    }

    public function getDiscounts(): array
    {
        return $this->discounts;
    }

    public function getTotalGross(): float
    {
        return $this->totalGross;
    }

    public function getTotalNet(): float
    {
        return $this->totalNet;
    }

    public function getTotalVat(): float
    {
        return $this->totalVat;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCapturedAt(): \DateTime
    {
        return $this->capturedAt;
    }

    // Serialization
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'discounts' => $this->discounts,
            'totalGross' => $this->totalGross,
            'totalNet' => $this->totalNet,
            'totalVat' => $this->totalVat,
            'currency' => $this->currency,
            'capturedAt' => $this->capturedAt->format('Y-m-d H:i:s'),
        ];
    }

    public static function fromArray(array $data): self
    {
        $snapshot = new self(
            items: $data['items'],
            discounts: $data['discounts'],
            totalGross: $data['totalGross'],
            totalNet: $data['totalNet'],
            totalVat: $data['totalVat'],
            currency: $data['currency']
        );

        if (isset($data['capturedAt'])) {
            $snapshot->capturedAt = new \DateTime($data['capturedAt']);
        }

        return $snapshot;
    }
}
```

---

### 4. ContractState (Value Object)

```php
<?php
// src/Component/Contract/ContractState.php

namespace Osc\Payment\Component\Contract;

/**
 * Contract State - Value Object (v4.0)
 *
 * Type-safe representation of contract states.
 *
 * DDD Pattern: Value Object (immutable, type-safe)
 */
final class ContractState
{
    private const VALID_STATES = [
        'draft',
        'pending',
        'ready_to_commit',
        'committed',
        'fulfilled',
        'cancelled',
        'expired',
        'failed',
    ];

    private const TERMINAL_STATES = [
        'fulfilled',
        'cancelled',
        'expired',
        'failed',
    ];

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::VALID_STATES, true)) {
            throw new \InvalidArgumentException("Invalid contract state: {$value}");
        }

        $this->value = $value;
    }

    // Factory methods
    public static function draft(): self
    {
        return new self('draft');
    }

    public static function pending(): self
    {
        return new self('pending');
    }

    public static function readyToCommit(): self
    {
        return new self('ready_to_commit');
    }

    public static function committed(): self
    {
        return new self('committed');
    }

    public static function fulfilled(): self
    {
        return new self('fulfilled');
    }

    public static function cancelled(): self
    {
        return new self('cancelled');
    }

    public static function expired(): self
    {
        return new self('expired');
    }

    public static function failed(): self
    {
        return new self('failed');
    }

    public static function fromValue(string $value): self
    {
        return new self($value);
    }

    // Query methods
    public function isDraft(): bool
    {
        return $this->value === 'draft';
    }

    public function isPending(): bool
    {
        return $this->value === 'pending';
    }

    public function isReadyToCommit(): bool
    {
        return $this->value === 'ready_to_commit';
    }

    public function isCommitted(): bool
    {
        return $this->value === 'committed';
    }

    public function isFulfilled(): bool
    {
        return $this->value === 'fulfilled';
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, self::TERMINAL_STATES, true);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(ContractState $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

---

### 5. ContractRepository

```php
<?php
// src/Component/Repository/ContractRepository.php

namespace Osc\Payment\Component\Repository;

use Osc\Payment\Component\Contract\PaymentContract;

/**
 * Contract Repository (v4.0)
 *
 * Persistence layer for PaymentContract aggregate.
 */
class ContractRepository
{
    // Constructor would inject database connection

    public function save(PaymentContract $contract): void
    {
        // TODO: Implement persistence
        // - Serialize contract to JSON for OXCONTRACTDATA column
        // - Save to oe_payments_contract table
    }

    public function findById(string $id): ?PaymentContract
    {
        // TODO: Query by ID
        return null;
    }

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContract
    {
        // TODO: Fast lookup for webhook processing
        return null;
    }

    public function findByUserId(string $userId): array
    {
        // TODO: Find all contracts for user
        return [];
    }

    public function findActiveByUserId(string $userId): ?PaymentContract
    {
        // TODO: Find PENDING/COMMITTED contract for user
        return null;
    }

    public function findExpired(?\DateTime $before = null): array
    {
        // TODO: Find expired contracts for cleanup
        return [];
    }

    public function delete(string $id): void
    {
        // TODO: Delete contract (for cleanup)
    }
}
```

---

### 6. ContractService

```php
<?php
// src/Component/Service/ContractService.php

namespace Osc\Payment\Component\Service;

use Osc\Payment\Component\Contract\PaymentContract;
use Osc\Payment\Component\Contract\ContractCondition;
use Osc\Payment\Component\Contract\BasketSnapshot;
use Osc\Payment\Component\Repository\ContractRepository;

/**
 * Contract Service (v4.0)
 *
 * Business operations for contract management.
 */
class ContractService
{
    private ContractRepository $contractRepository;

    public function __construct(ContractRepository $contractRepository)
    {
        $this->contractRepository = $contractRepository;
    }

    public function createContract(
        string $userId,
        object $basket,  // OXID Basket
        array $conditionTypes = []
    ): PaymentContract {
        $basketSnapshot = BasketSnapshot::fromOxidBasket($basket);

        $contract = new PaymentContract(
            shopId: 1,  // TODO: Get from config
            userId: $userId,
            basketSnapshot: $basketSnapshot
        );

        // Add default conditions if not provided
        if (empty($conditionTypes)) {
            $conditionTypes = [
                ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                ContractCondition::TYPE_FRAUD_CHECK,
            ];
        }

        foreach ($conditionTypes as $type) {
            $contract->addCondition(new ContractCondition($type));
        }

        $this->contractRepository->save($contract);

        return $contract;
    }

    public function findActiveContractByUser(string $userId): ?PaymentContract
    {
        return $this->contractRepository->findActiveByUserId($userId);
    }

    public function cleanupExpiredContracts(): int
    {
        $expired = $this->contractRepository->findExpired();
        $count = 0;

        foreach ($expired as $contract) {
            $contract->expire();
            $this->contractRepository->save($contract);
            $count++;
        }

        return $count;
    }
}
```

---

## TDD Workflow

### Phase 1: Domain Models (Pure Logic - No Database)

```php
<?php
// tests/Component/Unit/Contract/PaymentContractTest.php

use PHPUnit\Framework\TestCase;
use Osc\Payment\Component\Contract\PaymentContract;
use Osc\Payment\Component\Contract\ContractCondition;
use Osc\Payment\Component\Contract\BasketSnapshot;

class PaymentContractTest extends TestCase
{
    public function testContractCreation_InitialStateIsDraft()
    {
        $snapshot = $this->createMockBasketSnapshot();
        $contract = new PaymentContract(1, 'user123', $snapshot);

        $this->assertEquals('draft', $contract->getStateValue());
        $this->assertNull($contract->getOrderId());
    }

    public function testAddCondition_OnlyAllowedInDraftState()
    {
        $contract = $this->createDraftContract();
        $condition = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $contract->addCondition($condition);

        $this->assertCount(1, $contract->getConditions());
    }

    public function testTransitionToPending_RequiresConditions()
    {
        $contract = $this->createDraftContract();

        $this->expectException(\DomainException::class);
        $contract->transitionToPending();  // No conditions added
    }

    public function testFulfillCondition_AutoTransitionsToReadyToCommit()
    {
        $contract = $this->createDraftContract();
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();

        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $this->assertTrue($contract->getState()->isReadyToCommit());
    }

    public function testCommitToOrder_RequiresAllConditionsFulfilled()
    {
        $contract = $this->createPendingContract();

        $this->expectException(\DomainException::class);
        $contract->commitToOrder('order123');  // Conditions not fulfilled
    }

    public function testCommitToOrder_SetsOrderId()
    {
        $contract = $this->createReadyToCommitContract();

        $contract->commitToOrder('order123');

        $this->assertEquals('order123', $contract->getOrderId());
        $this->assertTrue($contract->getState()->isCommitted());
    }

    public function testFulfill_RequiresCommittedState()
    {
        $contract = $this->createCommittedContract();

        $contract->fulfill();

        $this->assertTrue($contract->getState()->isFulfilled());
        $this->assertNotNull($contract->getFulfilledAt());
    }

    public function testCancel_NotAllowedInTerminalState()
    {
        $contract = $this->createFulfilledContract();

        $this->expectException(\DomainException::class);
        $contract->cancel();
    }

    public function testIsExpired_ChecksExpirationTime()
    {
        $contract = $this->createDraftContract();

        // Not expired yet (24 hours from now)
        $this->assertFalse($contract->isExpired());
    }

    // Helper methods
    private function createMockBasketSnapshot(): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.00,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    private function createDraftContract(): PaymentContract
    {
        return new PaymentContract(1, 'user123', $this->createMockBasketSnapshot());
    }

    // ... more helper methods
}
```

---

## Tasks Breakdown

1. **Value Objects** (2 hours)
   - Implement BasketSnapshot (immutable)
   - Implement ContractState (type-safe)
   - Write unit tests (100% coverage)

2. **ContractCondition Entity** (2 hours)
   - Implement ContractCondition
   - Test condition lifecycle (pending → fulfilled/failed)
   - Test validation

3. **PaymentContract Aggregate Root** (4 hours)
   - Implement PaymentContract
   - Implement state machine (all transitions)
   - Implement condition management
   - Test aggregate behavior (pure domain logic)
   - Test business rules enforcement

4. **ContractRepository** (2 hours)
   - Implement persistence layer
   - Test CRUD operations
   - Test query methods (findByProviderOrderId, findExpired)

5. **ContractService** (2 hours)
   - Implement business operations
   - Test contract creation flow
   - Test cleanup operations

6. **Integration Tests** (1 hour)
   - Test full contract lifecycle
   - Test repository integration
   - Test service layer

---

## Definition of Done

- [ ] All acceptance criteria met
- [ ] **PaymentContract** aggregate root implemented with full state machine
- [ ] **ContractCondition** entity implemented
- [ ] **BasketSnapshot** value object (immutable) implemented
- [ ] **ContractState** value object (type-safe) implemented
- [ ] **ContractRepository** implemented with persistence
- [ ] **ContractService** implemented with business logic
- [ ] 100% test coverage (pure domain logic tests)
- [ ] All tests passing
- [ ] PHPStan level 6+ passes
- [ ] Documentation complete (state machine, condition types)
- [ ] State transitions validated and tested
- [ ] Immutability enforced (value objects)

---

## Contract State Machine Validation

### Valid Transitions

```
DRAFT → PENDING
  ✓ Conditions added
  ✓ transitionToPending() called

PENDING → READY_TO_COMMIT
  ✓ All conditions fulfilled
  ✓ Auto-transition

READY_TO_COMMIT → COMMITTED
  ✓ commitToOrder(orderId) called
  ✓ All conditions still fulfilled

COMMITTED → FULFILLED
  ✓ fulfill() called (payment captured)

Any → CANCELLED (except terminal)
  ✓ cancel(reason) called

Any → EXPIRED (except terminal)
  ✓ expire() called

Any → FAILED (except terminal)
  ✓ fail(reason) called
```

### Invalid Transitions (Must Throw Exceptions)

```
❌ PENDING → COMMITTED (must go through READY_TO_COMMIT)
❌ DRAFT → COMMITTED (must go through PENDING and READY_TO_COMMIT)
❌ FULFILLED → CANCELLED (terminal state)
❌ Any terminal → Any other state
```

---

## Testing Strategy

### Unit Tests (Pure Domain Logic)

**No database, no framework dependencies:**

```php
public function testContractLifecycle_HappyPath()
{
    // DRAFT
    $contract = new PaymentContract(1, 'user123', $snapshot);
    $this->assertTrue($contract->getState()->isDraft());

    // Add condition
    $contract->addCondition(new ContractCondition('payment_authorized'));

    // PENDING
    $contract->transitionToPending();
    $this->assertTrue($contract->getState()->isPending());

    // Fulfill condition → READY_TO_COMMIT
    $contract->fulfillCondition('payment_authorized');
    $this->assertTrue($contract->getState()->isReadyToCommit());

    // COMMITTED
    $contract->commitToOrder('order123');
    $this->assertTrue($contract->getState()->isCommitted());
    $this->assertEquals('order123', $contract->getOrderId());

    // FULFILLED
    $contract->fulfill();
    $this->assertTrue($contract->getState()->isFulfilled());
}
```

### Integration Tests

**With repository and database:**

```php
public function testContractPersistence_RoundTrip()
{
    $contract = $this->contractService->createContract('user123', $basket);
    $contractId = $contract->getId();

    $loaded = $this->contractRepository->findById($contractId);

    $this->assertEquals($contract->getId(), $loaded->getId());
    $this->assertEquals($contract->getStateValue(), $loaded->getStateValue());
    $this->assertCount(count($contract->getConditions()), $loaded->getConditions());
}
```

---

## Performance Considerations

- **Condition Checks**: O(n) where n = number of conditions (typically 2-3)
- **State Transitions**: O(1) with validation
- **Serialization**: JSON encoding for persistence
- **Indexing**: OXPROVIDERORDERID, OXSTATE, OXUSERID

**Optimization:**
- Cache active contracts in Redis (PENDING/COMMITTED states)
- Lazy-load basket snapshot (only when needed)
- Batch cleanup of expired contracts

---

## Key Benefits of This Design

✅ **Pure Domain Logic**: Testable without database or framework
✅ **Type Safety**: ContractState prevents invalid states
✅ **Immutability**: BasketSnapshot cannot be tampered with
✅ **Business Rules Enforced**: State machine validates all transitions
✅ **Clean Separation**: Contract domain separate from order domain
✅ **DDD-Compliant**: Aggregate root, entities, value objects
✅ **Provider-Agnostic**: Works with any payment provider

---

[← Previous: TICKET-005](SPRINT-1-TICKET-05-sdk-adapter.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md)
