# Database Schema & Models - Smart-Contract Pattern

**Version:** 1.0.0
**Date:** 2025-10-20
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)
**Status:** Architectural Specification
**Related Documents:**
- [01-02-architecture-smart-contracts.md](01-02-architecture-smart-contracts.md) - Smart-Contract architecture overview
- [02-database-and-models.md](02-database-and-models.md) - Current database design

---

## Table of Contents

1. [Overview](#overview)
2. [Database Schema](#database-schema)
3. [Entity Relationships](#entity-relationships)
4. [Domain Models](#domain-models)
5. [Value Objects](#value-objects)
6. [Repositories](#repositories)
7. [Data Flow Examples](#data-flow-examples)
8. [Migration Guide](#migration-guide)

---

## Overview

This document specifies the database schema and domain models required for the **Smart-Contract Pattern** implementation. The smart-contract pattern introduces a new entity (`PaymentContract`) that manages the payment lifecycle before order creation.

### Key Design Principles

1. **Non-Invasive:** No ALTER TABLE on OXID core tables (`oxorder`, `oxuser`)
2. **FK References:** Component tables reference OXID core via foreign keys
3. **Contract as Aggregate Root:** Contract owns conditions, basket snapshot, and lifecycle
4. **Order Created Later:** Order is created only when contract is committed
5. **Explicit State Management:** Contract state machine is separate from order state

---

## Database Schema

### 1. Contract Table: `osc_payment_contract`

**Purpose:** Main contract table storing payment lifecycle state.

```sql
CREATE TABLE IF NOT EXISTS osc_payment_contract (
    -- Primary key
    OXID CHAR(32) NOT NULL PRIMARY KEY COMMENT 'Contract ID (UUID)',

    -- Shop & user references
    OXSHOPID INT NOT NULL COMMENT 'Shop ID (multi-shop support)',
    OXUSERID CHAR(32) NOT NULL COMMENT 'FK to oxuser.OXID',
    OXORDERID CHAR(32) NULL COMMENT 'FK to oxorder.OXID (NULL until committed!)',

    -- Contract state
    OXSTATE VARCHAR(32) NOT NULL COMMENT 'State: DRAFT, PENDING, READY_TO_COMMIT, COMMITTED, FULFILLED, CANCELLED, EXPIRED, FAILED',
    OXSTATEREASON VARCHAR(255) NULL COMMENT 'Reason for state (if failed/cancelled)',

    -- Snapshot data (immutable)
    OXBASKETDATA JSON NOT NULL COMMENT 'Complete basket snapshot (items, discounts, totals)',
    OXTERMS JSON NULL COMMENT 'Terms & conditions agreed by customer',
    OXMETADATA JSON NULL COMMENT 'Additional metadata (IP, user agent, session ID, etc.)',

    -- Fulfillment conditions
    OXCONDITIONS JSON NOT NULL COMMENT 'Array of conditions with status (see ContractCondition)',

    -- Provider data
    OXPROVIDER VARCHAR(32) NULL COMMENT 'Payment provider: stripe, paypal, unzer, adyen, klarna, amazonpay, square',
    OXPROVIDERORDERID VARCHAR(128) NULL COMMENT 'Provider order/session/intent ID (e.g., pi_stripe_123, PAY-123)',
    OXPROVIDERDATA JSON NULL COMMENT 'Provider-specific data (e.g., authorization details)',

    -- Timestamps
    OXCREATED DATETIME NOT NULL COMMENT 'Contract creation timestamp',
    OXUPDATED DATETIME NOT NULL COMMENT 'Last update timestamp',
    OXCOMMITTEDAT DATETIME NULL COMMENT 'When order was created (contract committed)',
    OXFULFILLEDAT DATETIME NULL COMMENT 'When payment was captured (contract fulfilled)',
    OXEXPIRESAT DATETIME NULL COMMENT 'Contract expiration (default: +24 hours)',

    -- Indexes
    INDEX IDX_STATE (OXSTATE),
    INDEX IDX_USER (OXUSERID),
    INDEX IDX_ORDER (OXORDERID),
    INDEX IDX_PROVIDER_ORDER (OXPROVIDERORDERID),
    INDEX IDX_CREATED (OXCREATED),
    INDEX IDX_EXPIRES (OXEXPIRESAT),
    INDEX IDX_STATE_EXPIRES (OXSTATE, OXEXPIRESAT),

    -- Foreign keys
    FOREIGN KEY FK_CONTRACT_USER (OXUSERID)
        REFERENCES oxuser(OXID)
        ON DELETE CASCADE
        COMMENT 'User who created contract',

    FOREIGN KEY FK_CONTRACT_ORDER (OXORDERID)
        REFERENCES oxorder(OXID)
        ON DELETE SET NULL
        COMMENT 'Order created from contract (NULL until committed)'

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Payment contract lifecycle management - tracks intent → commitment → fulfillment';
```

### 2. Modified Order State Table: `osc_payment_order_state`

**Purpose:** Add contract reference to existing order state tracking.

```sql
-- Add contract reference to existing table
ALTER TABLE osc_payment_order_state
    ADD COLUMN IF NOT EXISTS OXCONTRACTID CHAR(32) NULL
        COMMENT 'FK to osc_payment_contract.OXID',
    ADD INDEX IDX_CONTRACT (OXCONTRACTID),
    ADD FOREIGN KEY FK_ORDER_STATE_CONTRACT (OXCONTRACTID)
        REFERENCES osc_payment_contract(OXID)
        ON DELETE SET NULL
        COMMENT 'Contract that created this order';
```

**Note:** Existing `osc_payment_order_state` schema remains unchanged except for this addition. See [02-database-and-models.md](02-database-and-models.md) for full schema.

### 3. Transaction Table Enhancement: `osc_payment_transaction`

**Purpose:** Add contract reference to transaction tracking.

```sql
-- Add contract reference to existing table
ALTER TABLE osc_payment_transaction
    ADD COLUMN IF NOT EXISTS OXCONTRACTID CHAR(32) NULL
        COMMENT 'FK to osc_payment_contract.OXID',
    ADD INDEX IDX_TRANSACTION_CONTRACT (OXCONTRACTID),
    ADD FOREIGN KEY FK_TRANSACTION_CONTRACT (OXCONTRACTID)
        REFERENCES osc_payment_contract(OXID)
        ON DELETE SET NULL
        COMMENT 'Contract associated with transaction';
```

---

## Entity Relationships

### ER Diagram

```
┌──────────────────┐
│   oxuser         │
│  (OXID core)     │
└──────┬───────────┘
       │ 1
       │ creates
       │ n
┌──────▼────────────────────────────────────────────────┐
│  osc_payment_contract                                  │
│  ──────────────────────────────────────────────────   │
│  OXID (PK)                                             │
│  OXUSERID (FK → oxuser.OXID)                          │
│  OXORDERID (FK → oxorder.OXID) ← NULL until committed │
│  OXSTATE (DRAFT → PENDING → COMMITTED → FULFILLED)   │
│  OXBASKETDATA (JSON: items, discounts, totals)       │
│  OXCONDITIONS (JSON: payment_auth, fraud, stock)     │
│  OXPROVIDERORDERID (Provider contract ID)             │
└──────┬────────────────────────────────────────────────┘
       │ 1
       │ committed to
       │ 1 (0..1)
┌──────▼───────────┐
│   oxorder        │
│  (OXID core)     │──────┐
└──────┬───────────┘      │ 1
       │ 1                │ has
       │ has              │ 1
       │ 1                │
┌──────▼─────────────────────┐      ┌──▼──────────────────────┐
│ osc_payment_order_state     │      │ osc_payment_transaction │
│ ──────────────────────────  │      │ ─────────────────────── │
│ OXORDERID (FK)              │      │ OXORDERID (FK)          │
│ OXCONTRACTID (FK) ← NEW!    │      │ OXCONTRACTID (FK) ← NEW!│
│ OXPAYMENTSTATE              │      │ OXTRANSACTIONID         │
│ OXPROVIDERORDERID           │      │ OXTYPE (authorization)  │
└─────────────────────────────┘      └─────────────────────────┘
```

### Relationship Summary

| From | To | Cardinality | Description |
|------|-----|-------------|-------------|
| `oxuser` → `osc_payment_contract` | 1:N | User can create multiple contracts |
| `osc_payment_contract` → `oxorder` | 0..1:1 | Contract MAY create one order (NULL until committed) |
| `oxorder` → `osc_payment_order_state` | 1:1 | Each order has one state record |
| `oxorder` → `osc_payment_transaction` | 1:N | Each order has multiple transactions (auth, capture, refund) |
| `osc_payment_contract` → `osc_payment_order_state` | 1:1 | Contract links to order state (after commitment) |
| `osc_payment_contract` → `osc_payment_transaction` | 1:N | Contract can have multiple transactions |

---

## Domain Models

### 1. PaymentContract (Aggregate Root)

**Namespace:** `Osc\Payment\Component\Model\PaymentContract`

```php
<?php

declare(strict_types=1);

namespace Osc\Payment\Component\Model;

use Osc\Payment\Component\ValueObject\ContractState;
use Osc\Payment\Component\ValueObject\BasketSnapshot;
use Osc\Payment\Component\Entity\ContractCondition;

/**
 * Payment Contract - Aggregate Root
 *
 * Manages the payment lifecycle from purchase intent to order creation.
 *
 * State transitions:
 * DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
 *      ↓           ↓                 ↓
 *  CANCELLED   EXPIRED            FAILED
 */
final class PaymentContract
{
    // States (match OXSTATE values)
    public const STATE_DRAFT = 'draft';
    public const STATE_PENDING = 'pending';
    public const STATE_READY_TO_COMMIT = 'ready_to_commit';
    public const STATE_COMMITTED = 'committed';
    public const STATE_FULFILLED = 'fulfilled';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_EXPIRED = 'expired';
    public const STATE_FAILED = 'failed';

    // Properties (map to database columns)
    private ?string $id = null;                    // OXID
    private string $shopId;                         // OXSHOPID
    private string $userId;                         // OXUSERID
    private ?string $orderId = null;                // OXORDERID (NULL until committed)
    private string $state;                          // OXSTATE
    private ?string $stateReason = null;            // OXSTATEREASON
    private BasketSnapshot $basketSnapshot;         // OXBASKETDATA (Value Object)
    private array $conditions = [];                 // OXCONDITIONS (ContractCondition[])
    private ?string $provider = null;               // OXPROVIDER
    private ?string $providerOrderId = null;        // OXPROVIDERORDERID
    private ?\DateTime $createdAt;                  // OXCREATED
    private \DateTime $updatedAt;                   // OXUPDATED
    private ?\DateTime $committedAt = null;         // OXCOMMITTEDAT
    private ?\DateTime $fulfilledAt = null;         // OXFULFILLEDAT
    private ?\DateTime $expiresAt = null;           // OXEXPIRESAT

    // Domain events (not persisted)
    private array $recordedEvents = [];

    public function __construct(
        string $shopId,
        string $userId,
        BasketSnapshot $basketSnapshot,
        string $state = self::STATE_DRAFT
    ) {
        $this->shopId = $shopId;
        $this->userId = $userId;
        $this->basketSnapshot = $basketSnapshot;
        $this->state = $state;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->expiresAt = new \DateTime('+24 hours');
    }

    /**
     * Add a condition that must be fulfilled before contract can be committed
     */
    public function addCondition(ContractCondition $condition): void
    {
        if (!in_array($this->state, [self::STATE_DRAFT, self::STATE_PENDING])) {
            throw new \LogicException(
                "Cannot add conditions to contract in state: {$this->state}"
            );
        }

        $this->conditions[] = $condition;
        $this->updatedAt = new \DateTime();
    }

    /**
     * Transition from DRAFT to PENDING (conditions being resolved)
     */
    public function transitionToPending(): void
    {
        if ($this->state !== self::STATE_DRAFT) {
            throw new \LogicException("Cannot transition from {$this->state} to PENDING");
        }

        if (empty($this->conditions)) {
            throw new \LogicException("Cannot transition to PENDING without conditions");
        }

        $this->state = self::STATE_PENDING;
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractTransitionedToPendingEvent($this));
    }

    /**
     * Fulfill a specific condition
     */
    public function fulfillCondition(string $type, array $data = []): void
    {
        $conditionFulfilled = false;

        foreach ($this->conditions as $condition) {
            if ($condition->getType() === $type && !$condition->isFulfilled()) {
                $condition->fulfill($data);
                $conditionFulfilled = true;
                break;
            }
        }

        if (!$conditionFulfilled) {
            throw new \InvalidArgumentException("Condition not found or already fulfilled: {$type}");
        }

        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractConditionFulfilledEvent($this, $type, $data));

        // Check if all conditions fulfilled → transition to READY_TO_COMMIT
        if ($this->areAllConditionsFulfilled() && $this->state === self::STATE_PENDING) {
            $this->transitionToReadyToCommit();
        }
    }

    /**
     * Check if all conditions are fulfilled
     */
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

    /**
     * Transition from PENDING to READY_TO_COMMIT (all conditions met)
     */
    private function transitionToReadyToCommit(): void
    {
        if ($this->state !== self::STATE_PENDING) {
            throw new \LogicException("Cannot transition from {$this->state} to READY_TO_COMMIT");
        }

        $this->state = self::STATE_READY_TO_COMMIT;
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractReadyToCommitEvent($this));
    }

    /**
     * Commit contract to order (order created, contract linked)
     */
    public function commitToOrder(string $orderId): void
    {
        if ($this->state !== self::STATE_READY_TO_COMMIT) {
            throw new \LogicException(
                "Cannot commit contract in state: {$this->state}. Must be READY_TO_COMMIT."
            );
        }

        if (!$this->areAllConditionsFulfilled()) {
            throw new \LogicException("Cannot commit contract with unfulfilled conditions");
        }

        $this->orderId = $orderId;
        $this->state = self::STATE_COMMITTED;
        $this->committedAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractCommittedEvent($this, $orderId));
    }

    /**
     * Fulfill contract (payment captured, contract complete)
     */
    public function fulfill(): void
    {
        if ($this->state !== self::STATE_COMMITTED) {
            throw new \LogicException("Cannot fulfill contract in state: {$this->state}");
        }

        if (!$this->orderId) {
            throw new \LogicException("Cannot fulfill contract without order ID");
        }

        $this->state = self::STATE_FULFILLED;
        $this->fulfilledAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractFulfilledEvent($this));
    }

    /**
     * Cancel contract (user or system cancellation)
     */
    public function cancel(string $reason): void
    {
        if ($this->state === self::STATE_FULFILLED) {
            throw new \LogicException("Cannot cancel fulfilled contract");
        }

        $this->state = self::STATE_CANCELLED;
        $this->stateReason = $reason;
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractCancelledEvent($this, $reason));
    }

    /**
     * Mark contract as expired (no action taken within time limit)
     */
    public function expire(): void
    {
        if ($this->state === self::STATE_FULFILLED || $this->state === self::STATE_CANCELLED) {
            throw new \LogicException("Cannot expire contract in terminal state: {$this->state}");
        }

        $this->state = self::STATE_EXPIRED;
        $this->stateReason = 'Contract expired after 24 hours';
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractExpiredEvent($this));
    }

    /**
     * Check if contract is expired
     */
    public function isExpired(): bool
    {
        return $this->expiresAt && new \DateTime() > $this->expiresAt;
    }

    /**
     * Set provider information (Stripe, PayPal, etc.)
     */
    public function setProvider(string $provider, string $providerOrderId): void
    {
        $this->provider = $provider;
        $this->providerOrderId = $providerOrderId;
        $this->updatedAt = new \DateTime();
    }

    // Getters
    public function getId(): ?string { return $this->id; }
    public function getShopId(): string { return $this->shopId; }
    public function getUserId(): string { return $this->userId; }
    public function getOrderId(): ?string { return $this->orderId; }
    public function getState(): string { return $this->state; }
    public function getStateReason(): ?string { return $this->stateReason; }
    public function getBasketSnapshot(): BasketSnapshot { return $this->basketSnapshot; }
    public function getConditions(): array { return $this->conditions; }
    public function getProvider(): ?string { return $this->provider; }
    public function getProviderOrderId(): ?string { return $this->providerOrderId; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getUpdatedAt(): \DateTime { return $this->updatedAt; }
    public function getCommittedAt(): ?\DateTime { return $this->committedAt; }
    public function getFulfilledAt(): ?\DateTime { return $this->fulfilledAt; }
    public function getExpiresAt(): ?\DateTime { return $this->expiresAt; }

    /**
     * Get recorded domain events
     */
    public function getRecordedEvents(): array
    {
        return $this->recordedEvents;
    }

    /**
     * Clear recorded domain events (after dispatching)
     */
    public function clearRecordedEvents(): void
    {
        $this->recordedEvents = [];
    }

    /**
     * Record a domain event (internal)
     */
    private function recordEvent(object $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
```

### 2. ContractCondition (Entity)

**Namespace:** `Osc\Payment\Component\Entity\ContractCondition`

```php
<?php

declare(strict_types=1);

namespace Osc\Payment\Component\Entity;

/**
 * Contract Condition
 *
 * Represents a precondition that must be fulfilled before contract can be committed.
 */
final class ContractCondition
{
    // Condition types
    public const TYPE_PAYMENT_AUTHORIZED = 'payment_authorized';
    public const TYPE_FRAUD_CHECK = 'fraud_check';
    public const TYPE_STOCK_RESERVED = 'stock_reserved';
    public const TYPE_COMPLIANCE_CHECK = 'compliance_check';
    public const TYPE_ADDRESS_VALIDATED = 'address_validated';
    public const TYPE_AGE_VERIFICATION = 'age_verification';
    public const TYPE_CUSTOM = 'custom';

    // Statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_FAILED = 'failed';

    private string $type;
    private string $status;
    private array $data;
    private \DateTime $createdAt;
    private ?\DateTime $fulfilledAt = null;
    private ?string $failureReason = null;

    public function __construct(
        string $type,
        string $status = self::STATUS_PENDING,
        array $data = []
    ) {
        $this->type = $type;
        $this->status = $status;
        $this->data = $data;
        $this->createdAt = new \DateTime();
    }

    public function fulfill(array $data = []): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new \LogicException("Condition already fulfilled: {$this->type}");
        }

        $this->status = self::STATUS_FULFILLED;
        $this->data = array_merge($this->data, $data);
        $this->fulfilledAt = new \DateTime();
    }

    public function fail(string $reason): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new \LogicException("Cannot fail already fulfilled condition: {$this->type}");
        }

        $this->status = self::STATUS_FAILED;
        $this->failureReason = $reason;
    }

    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    // Getters
    public function getType(): string { return $this->type; }
    public function getStatus(): string { return $this->status; }
    public function getData(): array { return $this->data; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getFulfilledAt(): ?\DateTime { return $this->fulfilledAt; }
    public function getFailureReason(): ?string { return $this->failureReason; }

    /**
     * Convert to array (for JSON storage in OXCONDITIONS column)
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'status' => $this->status,
            'data' => $this->data,
            'createdAt' => $this->createdAt->format(\DateTime::ATOM),
            'fulfilledAt' => $this->fulfilledAt?->format(\DateTime::ATOM),
            'failureReason' => $this->failureReason,
        ];
    }

    /**
     * Create from array (from JSON storage)
     */
    public static function fromArray(array $data): self
    {
        $condition = new self(
            type: $data['type'],
            status: $data['status'],
            data: $data['data'] ?? []
        );

        $condition->createdAt = new \DateTime($data['createdAt']);

        if (!empty($data['fulfilledAt'])) {
            $condition->fulfilledAt = new \DateTime($data['fulfilledAt']);
        }

        if (!empty($data['failureReason'])) {
            $condition->failureReason = $data['failureReason'];
        }

        return $condition;
    }
}
```

---

## Value Objects

### 1. BasketSnapshot (Value Object)

**Purpose:** Immutable snapshot of basket data at contract creation time.

```php
<?php

declare(strict_types=1);

namespace Osc\Payment\Component\ValueObject;

/**
 * Basket Snapshot (Value Object)
 *
 * Immutable snapshot of basket data captured when contract is created.
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

    public function __construct(
        array $items,
        array $discounts,
        float $totalGross,
        float $totalNet,
        float $totalVat,
        string $currency
    ) {
        // Immutable - no setters allowed
        $this->items = $items;
        $this->discounts = $discounts;
        $this->totalGross = $totalGross;
        $this->totalNet = $totalNet;
        $this->totalVat = $totalVat;
        $this->currency = $currency;
        $this->capturedAt = new \DateTime();
    }

    // Only getters
    public function getItems(): array { return $this->items; }
    public function getDiscounts(): array { return $this->discounts; }
    public function getTotalGross(): float { return $this->totalGross; }
    public function getTotalNet(): float { return $this->totalNet; }
    public function getTotalVat(): float { return $this->totalVat; }
    public function getCurrency(): string { return $this->currency; }
    public function getCapturedAt(): \DateTime { return $this->capturedAt; }

    /**
     * Convert to array (for JSON storage in OXBASKETDATA column)
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'discounts' => $this->discounts,
            'totals' => [
                'gross' => $this->totalGross,
                'net' => $this->totalNet,
                'vat' => $this->totalVat,
                'currency' => $this->currency,
            ],
            'capturedAt' => $this->capturedAt->format(\DateTime::ATOM),
        ];
    }

    /**
     * Create from array (from JSON storage)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            items: $data['items'] ?? [],
            discounts: $data['discounts'] ?? [],
            totalGross: (float)($data['totals']['gross'] ?? 0.0),
            totalNet: (float)($data['totals']['net'] ?? 0.0),
            totalVat: (float)($data['totals']['vat'] ?? 0.0),
            currency: $data['totals']['currency'] ?? 'EUR'
        );
    }

    /**
     * Create from OXID basket
     */
    public static function fromOxidBasket(\OxidEsales\Eshop\Application\Model\Basket $basket): self
    {
        $items = [];
        foreach ($basket->getContents() as $basketItem) {
            $items[] = [
                'articleId' => $basketItem->getProductId(),
                'title' => $basketItem->getTitle(),
                'amount' => $basketItem->getAmount(),
                'price' => $basketItem->getPrice()->getBruttoPrice(),
                'vat' => $basketItem->getPrice()->getVat(),
            ];
        }

        $discounts = [];
        foreach ($basket->getDiscounts() as $discount) {
            $discounts[] = [
                'type' => 'voucher', // or 'discount'
                'code' => $discount->getId(),
                'amount' => -$discount->getBruttoPrice(),
            ];
        }

        return new self(
            items: $items,
            discounts: $discounts,
            totalGross: $basket->getBruttoSum(),
            totalNet: $basket->getNettoSum(),
            totalVat: $basket->getBruttoSum() - $basket->getNettoSum(),
            currency: $basket->getBasketCurrency()->name
        );
    }
}
```

### 2. ContractState (Value Object)

**Purpose:** Type-safe contract state representation.

```php
<?php

declare(strict_types=1);

namespace Osc\Payment\Component\ValueObject;

/**
 * Contract State (Value Object)
 *
 * Type-safe representation of contract state.
 */
final class ContractState
{
    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

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

    public function getValue(): string
    {
        return $this->value;
    }

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

    public function isCancelled(): bool
    {
        return $this->value === 'cancelled';
    }

    public function isExpired(): bool
    {
        return $this->value === 'expired';
    }

    public function isFailed(): bool
    {
        return $this->value === 'failed';
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, ['fulfilled', 'cancelled', 'expired', 'failed']);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

---

## Repositories

### ContractRepository

**Purpose:** Repository for PaymentContract aggregate persistence.

```php
<?php

declare(strict_types=1);

namespace Osc\Payment\Component\Repository;

use Osc\Payment\Component\Model\PaymentContract;
use Osc\Payment\Component\Entity\ContractCondition;
use Osc\Payment\Component\ValueObject\BasketSnapshot;
use Doctrine\DBAL\Connection;

final class ContractRepository
{
    public function __construct(
        private Connection $connection,
        private string $tableName = 'osc_payment_contract'
    ) {}

    /**
     * Save contract (insert or update)
     */
    public function save(PaymentContract $contract): void
    {
        if ($contract->getId()) {
            $this->update($contract);
        } else {
            $this->insert($contract);
        }
    }

    /**
     * Find contract by ID
     */
    public function find(string $id): ?PaymentContract
    {
        $data = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->tableName} WHERE OXID = ?",
            [$id]
        );

        return $data ? $this->hydrate($data) : null;
    }

    /**
     * Find contract by provider order ID (for webhook processing)
     */
    public function findByProviderOrderId(string $providerOrderId): ?PaymentContract
    {
        $data = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->tableName} WHERE OXPROVIDERORDERID = ?",
            [$providerOrderId]
        );

        return $data ? $this->hydrate($data) : null;
    }

    /**
     * Find contract by order ID
     */
    public function findByOrderId(string $orderId): ?PaymentContract
    {
        $data = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->tableName} WHERE OXORDERID = ?",
            [$orderId]
        );

        return $data ? $this->hydrate($data) : null;
    }

    /**
     * Find expired contracts (for cleanup cron)
     */
    public function findExpired(\DateTime $before = null): array
    {
        $before = $before ?? new \DateTime();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->tableName}
             WHERE OXEXPIRESAT < ?
             AND OXSTATE NOT IN (?, ?, ?)",
            [
                $before->format('Y-m-d H:i:s'),
                PaymentContract::STATE_FULFILLED,
                PaymentContract::STATE_CANCELLED,
                PaymentContract::STATE_EXPIRED,
            ]
        );

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    /**
     * Insert new contract
     */
    private function insert(PaymentContract $contract): void
    {
        $id = $this->generateId();

        $this->connection->insert($this->tableName, [
            'OXID' => $id,
            'OXSHOPID' => $contract->getShopId(),
            'OXUSERID' => $contract->getUserId(),
            'OXORDERID' => $contract->getOrderId(),
            'OXSTATE' => $contract->getState(),
            'OXSTATEREASON' => $contract->getStateReason(),
            'OXBASKETDATA' => json_encode($contract->getBasketSnapshot()->toArray()),
            'OXCONDITIONS' => json_encode(
                array_map(fn($c) => $c->toArray(), $contract->getConditions())
            ),
            'OXPROVIDER' => $contract->getProvider(),
            'OXPROVIDERORDERID' => $contract->getProviderOrderId(),
            'OXCREATED' => $contract->getCreatedAt()->format('Y-m-d H:i:s'),
            'OXUPDATED' => $contract->getUpdatedAt()->format('Y-m-d H:i:s'),
            'OXCOMMITTEDAT' => $contract->getCommittedAt()?->format('Y-m-d H:i:s'),
            'OXFULFILLEDAT' => $contract->getFulfilledAt()?->format('Y-m-d H:i:s'),
            'OXEXPIRESAT' => $contract->getExpiresAt()?->format('Y-m-d H:i:s'),
        ]);

        // Set ID on contract via reflection
        $reflection = new \ReflectionClass($contract);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($contract, $id);
    }

    /**
     * Update existing contract
     */
    private function update(PaymentContract $contract): void
    {
        $this->connection->update(
            $this->tableName,
            [
                'OXORDERID' => $contract->getOrderId(),
                'OXSTATE' => $contract->getState(),
                'OXSTATEREASON' => $contract->getStateReason(),
                'OXCONDITIONS' => json_encode(
                    array_map(fn($c) => $c->toArray(), $contract->getConditions())
                ),
                'OXPROVIDER' => $contract->getProvider(),
                'OXPROVIDERORDERID' => $contract->getProviderOrderId(),
                'OXUPDATED' => $contract->getUpdatedAt()->format('Y-m-d H:i:s'),
                'OXCOMMITTEDAT' => $contract->getCommittedAt()?->format('Y-m-d H:i:s'),
                'OXFULFILLEDAT' => $contract->getFulfilledAt()?->format('Y-m-d H:i:s'),
            ],
            ['OXID' => $contract->getId()]
        );
    }

    /**
     * Hydrate contract from database row
     */
    private function hydrate(array $data): PaymentContract
    {
        $basketData = json_decode($data['OXBASKETDATA'], true);
        $basketSnapshot = BasketSnapshot::fromArray($basketData);

        $contract = new PaymentContract(
            shopId: (string)$data['OXSHOPID'],
            userId: $data['OXUSERID'],
            basketSnapshot: $basketSnapshot,
            state: $data['OXSTATE']
        );

        // Set ID via reflection
        $reflection = new \ReflectionClass($contract);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($contract, $data['OXID']);

        // Hydrate conditions
        $conditionsData = json_decode($data['OXCONDITIONS'], true);
        foreach ($conditionsData as $conditionData) {
            $contract->addCondition(ContractCondition::fromArray($conditionData));
        }

        // Hydrate other properties via reflection
        // (Simplified - full implementation would set all properties)

        return $contract;
    }

    /**
     * Generate unique contract ID
     */
    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
```

---

## Data Flow Examples

### Example 1: Contract Creation with Basket Snapshot

```json
// OXBASKETDATA column example
{
  "items": [
    {
      "articleId": "94415306f824dc1aa2fce0dc4f12783d",
      "title": "Kuyichi Ledergürtel JEVER",
      "amount": 2,
      "price": 29.90,
      "vat": 19.0
    },
    {
      "articleId": "b56369b1fc9d7e1d84cfa8e",
      "title": "Trapez Ledertasche",
      "amount": 1,
      "price": 89.95,
      "vat": 19.0
    }
  ],
  "discounts": [
    {
      "type": "voucher",
      "code": "SAVE10",
      "amount": -10.00
    }
  ],
  "totals": {
    "gross": 139.75,
    "net": 117.44,
    "vat": 22.31,
    "currency": "EUR"
  },
  "capturedAt": "2025-10-20T14:30:00Z"
}
```

### Example 2: Contract Conditions Tracking

```json
// OXCONDITIONS column example
[
  {
    "type": "payment_authorized",
    "status": "fulfilled",
    "data": {
      "authorizationId": "pi_3QAbC123xyz",
      "amount": 139.75,
      "currency": "EUR"
    },
    "createdAt": "2025-10-20T14:30:00Z",
    "fulfilledAt": "2025-10-20T14:30:15Z",
    "failureReason": null
  },
  {
    "type": "fraud_check",
    "status": "fulfilled",
    "data": {
      "score": 98,
      "risk": "low",
      "provider": "stripe_radar"
    },
    "createdAt": "2025-10-20T14:30:00Z",
    "fulfilledAt": "2025-10-20T14:30:18Z",
    "failureReason": null
  },
  {
    "type": "stock_reserved",
    "status": "pending",
    "data": {},
    "createdAt": "2025-10-20T14:30:00Z",
    "fulfilledAt": null,
    "failureReason": null
  }
]
```

### Example 3: Complete Contract Lifecycle

```sql
-- Step 1: Contract created (DRAFT state)
INSERT INTO osc_payment_contract (
    OXID, OXSHOPID, OXUSERID, OXORDERID, OXSTATE,
    OXBASKETDATA, OXCONDITIONS, OXCREATED, OXUPDATED, OXEXPIRESAT
) VALUES (
    'contract_abc123',
    1,
    'user_xyz789',
    NULL,  -- No order yet!
    'draft',
    '{"items": [...]}',
    '[]',  -- No conditions yet
    '2025-10-20 14:30:00',
    '2025-10-20 14:30:00',
    '2025-10-21 14:30:00'  -- Expires in 24 hours
);

-- Step 2: Transition to PENDING (conditions added)
UPDATE osc_payment_contract SET
    OXSTATE = 'pending',
    OXCONDITIONS = '[
        {"type": "payment_authorized", "status": "pending", ...},
        {"type": "fraud_check", "status": "pending", ...}
    ]',
    OXUPDATED = '2025-10-20 14:30:05'
WHERE OXID = 'contract_abc123';

-- Step 3: Conditions fulfilled → READY_TO_COMMIT
UPDATE osc_payment_contract SET
    OXSTATE = 'ready_to_commit',
    OXCONDITIONS = '[
        {"type": "payment_authorized", "status": "fulfilled", ...},
        {"type": "fraud_check", "status": "fulfilled", ...}
    ]',
    OXPROVIDER = 'stripe',
    OXPROVIDERORDERID = 'pi_stripe_123',
    OXUPDATED = '2025-10-20 14:30:20'
WHERE OXID = 'contract_abc123';

-- Step 4: Order created → COMMITTED
UPDATE osc_payment_contract SET
    OXSTATE = 'committed',
    OXORDERID = 'order_def456',  -- Order created!
    OXCOMMITTEDAT = '2025-10-20 14:30:25',
    OXUPDATED = '2025-10-20 14:30:25'
WHERE OXID = 'contract_abc123';

-- Step 5: Payment captured → FULFILLED
UPDATE osc_payment_contract SET
    OXSTATE = 'fulfilled',
    OXFULFILLEDAT = '2025-10-20 14:35:00',
    OXUPDATED = '2025-10-20 14:35:00'
WHERE OXID = 'contract_abc123';
```

---

## Migration Guide

### Step 1: Create Contract Table

```sql
-- Run migration
CREATE TABLE IF NOT EXISTS osc_payment_contract (
    -- See full schema above
);
```

### Step 2: Add Contract References to Existing Tables

```sql
-- Add contract FK to order state
ALTER TABLE osc_payment_order_state
    ADD COLUMN IF NOT EXISTS OXCONTRACTID CHAR(32) NULL,
    ADD INDEX IDX_CONTRACT (OXCONTRACTID),
    ADD FOREIGN KEY FK_ORDER_STATE_CONTRACT (OXCONTRACTID)
        REFERENCES osc_payment_contract(OXID) ON DELETE SET NULL;

-- Add contract FK to transactions
ALTER TABLE osc_payment_transaction
    ADD COLUMN IF NOT EXISTS OXCONTRACTID CHAR(32) NULL,
    ADD INDEX IDX_TRANSACTION_CONTRACT (OXCONTRACTID),
    ADD FOREIGN KEY FK_TRANSACTION_CONTRACT (OXCONTRACTID)
        REFERENCES osc_payment_contract(OXID) ON DELETE SET NULL;
```

### Step 3: Implement Domain Models

```bash
# Create model files
src/Model/PaymentContract.php
src/Entity/ContractCondition.php
src/ValueObject/BasketSnapshot.php
src/ValueObject/ContractState.php
src/Repository/ContractRepository.php
```

### Step 4: Update Event Handlers

```php
// Old: Create order immediately
class PaymentInitiationHandler
{
    public function handle(PaymentInitiatedEvent $event): void
    {
        $order = $this->orderFactory->create($event->getBasket());
        // ...
    }
}

// New: Create contract first
class PaymentInitiationHandler
{
    public function handle(PaymentInitiatedEvent $event): void
    {
        // Create contract
        $contract = new PaymentContract(
            shopId: $this->config->getShopId(),
            userId: $event->getUser()->getId(),
            basketSnapshot: BasketSnapshot::fromOxidBasket($event->getBasket())
        );

        // Add conditions
        $contract->addCondition(new ContractCondition('payment_authorized'));
        $contract->addCondition(new ContractCondition('fraud_check'));

        // Transition to pending
        $contract->transitionToPending();

        // Save
        $this->contractRepository->save($contract);

        // Emit event
        $this->dispatcher->dispatch(new ContractCreatedEvent($contract));
    }
}
```

### Step 5: Add Order Creation Handler

```php
// New handler: Create order when all conditions met
class OrderCreationHandler
{
    public function handle(ContractReadyToCommitEvent $event): void
    {
        $contract = $event->getContract();

        // Create order from contract
        $order = $this->orderFactory->createFromContract($contract);
        $order->setState(Order::ORDER_STATE_NOT_FINISHED);
        $order->save();

        // Link contract to order
        $contract->commitToOrder($order->getId());
        $this->contractRepository->save($contract);

        // Create order state
        $orderState = new PaymentOrderState(
            orderId: $order->getId(),
            contractId: $contract->getId(),
            paymentState: PaymentOrderState::STATE_PAYMENT_IN_PROGRESS
        );
        $this->orderStateRepository->save($orderState);

        // Emit event
        $this->dispatcher->dispatch(new OrderCreatedFromContractEvent($contract, $order));
    }
}
```

---

## Conclusion

This database schema and domain model design provides:

✅ **Non-invasive integration** with OXID core (no ALTER TABLE on oxorder/oxuser)
✅ **Clean separation** between payment contract and order fulfillment
✅ **Explicit state management** with clear state machine
✅ **Complete audit trail** via JSON snapshot and condition tracking
✅ **Provider-agnostic design** supporting Stripe, PayPal, Adyen, etc.
✅ **DDD-aligned architecture** with aggregate roots, entities, and value objects

The smart-contract pattern extends the payment provider contract concept (PaymentIntent, Order, ChargePermission) to the shop order lifecycle, providing a robust foundation for modern payment processing.

---

**Status:** ✅ Ready for Implementation
**Next Steps:**
1. Review domain model design
2. Implement database migration
3. Create unit tests for PaymentContract aggregate
4. Implement event handlers for contract lifecycle
