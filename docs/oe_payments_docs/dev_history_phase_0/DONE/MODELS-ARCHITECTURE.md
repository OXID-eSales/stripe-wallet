# Domain Models Architecture

**Date:** 2025-10-31
**Status:** ✅ IMPLEMENTED
**Pattern:** DDD (Domain-Driven Design)

---

## 📋 Overview

All domain models follow a consistent architecture with:
1. **Common Interface** (`ModelInterface`) - Defines base contract for all models
2. **Abstract Base Class** (`AbstractModel`) - Provides shared functionality
3. **Specific Interfaces** - Each model type has its own interface extending the base
4. **DDD Organization** - Models organized by domain concepts
5. **Persistence Split** - Clear separation between persistent and non-persistent models

---

## 🎨 Persistence Architecture Diagram

### Complete System Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         DOMAIN MODEL LAYER                                   │
│                                                                               │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │                    Base Infrastructure                                │  │
│  │                                                                        │  │
│  │  ModelInterface                AbstractModel                          │  │
│  │  ┌──────────────┐              ┌──────────────┐                       │  │
│  │  │ getId()      │              │ $id          │                       │  │
│  │  │ toArray()    │◄─────────────│ getId()      │                       │  │
│  │  └──────────────┘  implements  │ generateId() │                       │  │
│  │                                 │ toArray()    │                       │  │
│  │                                 └──────────────┘                       │  │
│  └────────────────────────────────────────┬─────────────────────────────┘  │
│                                            │ extends                         │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    PERSISTENT MODELS                                 │   │
│  │                    (Have Database Tables)                            │   │
│  │                                                                       │   │
│  │  ┌───────────────────────────────────────────────────────────────┐  │   │
│  │  │  PaymentContract (Aggregate Root)                             │  │   │
│  │  │  extends AbstractModel                                         │  │   │
│  │  │  ┌──────────────────────────────────────────────────────────┐ │  │   │
│  │  │  │ • $id (from AbstractModel)                               │ │  │   │
│  │  │  │ • $shopId, $userId, $orderId                             │ │  │   │
│  │  │  │ • $state (ContractState value object)                    │ │  │   │
│  │  │  │ • $basketSnapshot (BasketSnapshot value object)          │ │  │   │
│  │  │  │ • $conditions[] (ContractCondition entities)             │ │  │   │
│  │  │  │ • $provider, $providerOrderId, $providerRedirectUrl      │ │  │   │
│  │  │  │ • $expiresAt, $createdAt, $updatedAt, $fulfilledAt      │ │  │   │
│  │  │  └──────────────────────────────────────────────────────────┘ │  │   │
│  │  └───────────────────────────────────────────────────────────────┘  │   │
│  │          │                                                            │   │
│  │          │ owns (1:N)                                                 │   │
│  │          ▼                                                            │   │
│  │  ┌───────────────────────────────────────────────────────────────┐  │   │
│  │  │  ContractCondition (Child Entity)                             │  │   │
│  │  │  ┌──────────────────────────────────────────────────────────┐ │  │   │
│  │  │  │ • $type (payment_authorized, fraud_check, etc.)          │ │  │   │
│  │  │  │ • $status (pending, fulfilled, failed)                   │ │  │   │
│  │  │  │ • $data (array)                                          │ │  │   │
│  │  │  │ • $fulfilledAt, $failureReason                           │ │  │   │
│  │  │  └──────────────────────────────────────────────────────────┘ │  │   │
│  │  └───────────────────────────────────────────────────────────────┘  │   │
│  │                                                                       │   │
│  │  ┌───────────────────────────────────────────────────────────────┐  │   │
│  │  │  WebhookLog (Independent Entity)                              │  │   │
│  │  │  ┌──────────────────────────────────────────────────────────┐ │  │   │
│  │  │  │ • $id                                                    │ │  │   │
│  │  │  │ • $eventId (unique)                                      │ │  │   │
│  │  │  │ • $eventType, $contractId                                │ │  │   │
│  │  │  │ • $status, $receivedAt, $error                           │ │  │   │
│  │  │  └──────────────────────────────────────────────────────────┘ │  │   │
│  │  └───────────────────────────────────────────────────────────────┘  │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    NON-PERSISTENT MODELS                             │   │
│  │                    (Value Objects - Embedded)                        │   │
│  │                                                                       │   │
│  │  ┌───────────────────────────────────────────────────────────────┐  │   │
│  │  │  BasketSnapshot (Value Object)                                │  │   │
│  │  │  ┌──────────────────────────────────────────────────────────┐ │  │   │
│  │  │  │ • $items[] (array of line items)                         │ │  │   │
│  │  │  │ • $discounts[] (array of discounts)                      │ │  │   │
│  │  │  │ • $totalGross, $totalNet, $totalVat                      │ │  │   │
│  │  │  │ • $currency, $capturedAt                                 │ │  │   │
│  │  │  │ • Immutable (no setters)                                 │ │  │   │
│  │  │  │ • Factory: fromArray()                                   │ │  │   │
│  │  │  └──────────────────────────────────────────────────────────┘ │  │   │
│  │  └───────────────────────────────────────────────────────────────┘  │   │
│  │                                                                       │   │
│  │  ┌───────────────────────────────────────────────────────────────┐  │   │
│  │  │  ContractState (Value Object - Enum-like)                     │  │   │
│  │  │  ┌──────────────────────────────────────────────────────────┐ │  │   │
│  │  │  │ • $value (string)                                        │ │  │   │
│  │  │  │ • States: draft, pending, ready_to_commit, committed,    │ │  │   │
│  │  │  │           fulfilled, cancelled, expired, failed          │ │  │   │
│  │  │  │ • Immutable                                              │ │  │   │
│  │  │  │ • Factory: draft(), pending(), readyToCommit(), etc.     │ │  │   │
│  │  │  └──────────────────────────────────────────────────────────┘ │  │   │
│  │  └───────────────────────────────────────────────────────────────┘  │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
└───────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       │ Persistence Layer
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         DATABASE LAYER                                       │
│                                                                               │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │  osc_payments_contracts                                              │  │
│  │  ┌────────────────────────────────────────────────────────────────┐ │  │
│  │  │ OXID (PK)                    ← PaymentContract.$id             │ │  │
│  │  │ OXSHOPID                     ← PaymentContract.$shopId         │ │  │
│  │  │ OXUSERID                     ← PaymentContract.$userId         │ │  │
│  │  │ OXORDERID                    ← PaymentContract.$orderId        │ │  │
│  │  │ OXSTATE (VARCHAR)            ← ContractState.$value            │ │  │
│  │  │ OXBASKET (TEXT/JSON)         ← BasketSnapshot.toArray()        │ │  │
│  │  │ OXPROVIDER                   ← PaymentContract.$provider       │ │  │
│  │  │ OXPROVIDERORDERID            ← PaymentContract.$providerOrderId│ │  │
│  │  │ OXPROVIDERREDIRECTURL        ← PaymentContract.$providerRedirect│ │  │
│  │  │ OXEXPIRESAT                  ← PaymentContract.$expiresAt      │ │  │
│  │  │ OXCREATED                    ← PaymentContract.$createdAt      │ │  │
│  │  │ OXTIMESTAMP                  ← PaymentContract.$updatedAt      │ │  │
│  │  │ OXFULFILLEDAT                ← PaymentContract.$fulfilledAt    │ │  │
│  │  └────────────────────────────────────────────────────────────────┘ │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                │                                                             │
│                │ (1:N Foreign Key OXCONTRACTID)                              │
│                ▼                                                             │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │  osc_payments_contract_conditions                                    │  │
│  │  ┌────────────────────────────────────────────────────────────────┐ │  │
│  │  │ OXID (PK)                    ← ContractCondition (auto)        │ │  │
│  │  │ OXCONTRACTID (FK)            ← PaymentContract.$id             │ │  │
│  │  │ OXTYPE                       ← ContractCondition.$type         │ │  │
│  │  │ OXSTATUS                     ← ContractCondition.$status       │ │  │
│  │  │ OXDATA (TEXT/JSON)           ← ContractCondition.$data         │ │  │
│  │  │ OXFULFILLEDAT                ← ContractCondition.$fulfilledAt  │ │  │
│  │  │ OXFAILUREREASON              ← ContractCondition.$failureReason│ │  │
│  │  └────────────────────────────────────────────────────────────────┘ │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │  osc_payments_webhooklogs                                            │  │
│  │  ┌────────────────────────────────────────────────────────────────┐ │  │
│  │  │ OXID (PK)                    ← WebhookLog.$id                  │ │  │
│  │  │ OXEVENTID (UNIQUE)           ← WebhookLog.$eventId             │ │  │
│  │  │ OXEVENTTYPE                  ← WebhookLog.$eventType           │ │  │
│  │  │ OXCONTRACTID                 ← WebhookLog.$contractId          │ │  │
│  │  │ OXSTATUS                     ← WebhookLog.$status              │ │  │
│  │  │ OXRECEIVEDAT                 ← WebhookLog.$receivedAt          │ │  │
│  │  │ OXERROR                      ← WebhookLog.$error               │ │  │
│  │  └────────────────────────────────────────────────────────────────┘ │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
└───────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       │ Repository Layer
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         REPOSITORY LAYER                                     │
│                                                                               │
│  ┌─────────────────────────────────────────┐                                │
│  │  ContractRepository                     │  ← Manages PaymentContract     │
│  │  ┌───────────────────────────────────┐ │     aggregate (including       │
│  │  │ save(PaymentContract)             │ │     ContractCondition children)│
│  │  │ findById(string): ?PaymentContract│ │                                 │
│  │  │ findByUserId(string): array       │ │  Serializes:                   │
│  │  │ findByState(string): array        │ │  • ContractState → string      │
│  │  │ findExpired(): array              │ │  • BasketSnapshot → JSON       │
│  │  └───────────────────────────────────┘ │                                 │
│  └─────────────────────────────────────────┘                                │
│                                                                               │
│  ┌─────────────────────────────────────────┐                                │
│  │  WebhookLogRepository                   │  ← Manages WebhookLog          │
│  │  ┌───────────────────────────────────┐ │     (independent entity)       │
│  │  │ save(WebhookLog)                  │ │                                 │
│  │  │ findById(string): ?WebhookLog     │ │                                 │
│  │  │ findByEventId(string): ?WebhookLog│ │                                 │
│  │  │ findByContractId(string): array   │ │                                 │
│  │  └───────────────────────────────────┘ │                                 │
│  └─────────────────────────────────────────┘                                │
│                                                                               │
│  ❌ NO ContractConditionRepository   (managed via PaymentContract aggregate) │
│  ❌ NO BasketSnapshotRepository      (value object, embedded in contract)    │
│  ❌ NO ContractStateRepository       (value object, embedded in contract)    │
└───────────────────────────────────────────────────────────────────────────────┘
```

### Persistence Flow Examples

#### Example 1: Saving PaymentContract Aggregate

```
Application Layer
       ├─ Create PaymentContract($shopId, $userId, $basketSnapshot)
       ├─ Add ContractCondition(TYPE_PAYMENT_AUTHORIZED)
       ├─ Add ContractCondition(TYPE_FRAUD_CHECK)
       └─ Call: $repository->save($contract)
              │
              ▼
Repository Layer (ContractRepository)
       ├─ Extract PaymentContract data
       ├─ Serialize ContractState → string ('draft')
       ├─ Serialize BasketSnapshot → JSON
       ├─ Extract ContractCondition[] children
       └─ Execute transaction:
              ├─ INSERT into osc_payments_contracts
              │     (OXID, OXSHOPID, OXUSERID, OXSTATE='draft', OXBASKET='{"items":[...]}', ...)
              └─ For each ContractCondition:
                    └─ INSERT into osc_payments_contract_conditions
                          (OXID, OXCONTRACTID, OXTYPE, OXSTATUS, ...)
              ▼
Database Layer
       ├─ osc_payments_contracts: 1 row inserted
       └─ osc_payments_contract_conditions: 2 rows inserted
```

#### Example 2: Loading PaymentContract Aggregate

```
Application Layer
       └─ Call: $repository->findById('contract_123')
              │
              ▼
Repository Layer (ContractRepository)
       ├─ Query osc_payments_contracts WHERE OXID = 'contract_123'
       ├─ Query osc_payments_contract_conditions WHERE OXCONTRACTID = 'contract_123'
       ├─ Deserialize OXSTATE string → ContractState::fromValue('draft')
       ├─ Deserialize OXBASKET JSON → BasketSnapshot::fromArray([...])
       ├─ Reconstruct ContractCondition[] from rows
       └─ Reconstruct PaymentContract aggregate
              ├─ new PaymentContract($shopId, $userId, $basketSnapshot, $id)
              ├─ Set internal state: $contract->state = $state
              ├─ Add conditions: foreach ($conditionRows) { $contract->addCondition(...) }
              └─ Return complete aggregate
              ▼
Application Layer
       ← Receives fully reconstructed PaymentContract with all children
```

---

## 🏗️ Base Architecture

### ModelInterface (Common Interface)

**Location:** `src/Component/Model/ModelInterface.php`

All models implement this interface:

```php
interface ModelInterface
{
    public function getId(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
```

**Purpose:**
- Ensures all models have unique identifiers
- Enforces array serialization capability
- Provides consistent API across all models

### AbstractModel (Common Base Class)

**Location:** `src/Component/Model/AbstractModel.php`

All concrete models extend this abstract class:

```php
abstract class AbstractModel implements ModelInterface
{
    protected ?string $id = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    protected function generateId(string $prefix = 'id'): string
    {
        return uniqid($prefix . '_', true);
    }

    abstract public function toArray(): array;
}
```

**Features:**
- ID management with protected property
- ID generation with custom prefixes
- Forces implementation of `toArray()` in subclasses

---

## 📁 Current Model Organization

### Directory Structure

**Base Infrastructure:**
```
src/Component/Model/
├── ModelInterface.php          # Common interface
├── AbstractModel.php           # Common base class
├── Persistent/                 # Persistent models (have database tables)
└── ValueObject/                # Non-persistent value objects
```

**Domain Models (by domain):**
```
src/Component/Contract/         # Contract domain
├── PaymentContract.php         # Aggregate root (PERSISTENT)
├── PaymentContractInterface.php
├── ContractCondition.php       # Entity (PERSISTENT)
├── ContractState.php           # Value object (NON-PERSISTENT)
└── BasketSnapshot.php          # Value object (NON-PERSISTENT)

src/Component/Webhook/
└── WebhookLog.php              # Entity (PERSISTENT)
```

**Future Directories (prepared, currently empty):**
```
src/Component/Model/
├── Contract/                   # For future contract models
└── Entity/                     # For future shared entities
```

### Why This Organization?

1. **Base infrastructure centralized** in `Model/` directory
2. **Domain models remain in domain directories** (`Contract/`, `Payment/`, etc.)
3. **No breaking changes** to existing code
4. **Clear separation** between infrastructure and domain
5. **Persistence boundaries clearly marked** (PERSISTENT vs NON-PERSISTENT)

---

## 🗄️ Persistence Architecture

### Persistent Models (Have Database Tables)

Models that are stored in their own database tables and require repositories for data access:

#### 1. PaymentContract (Aggregate Root)
**Location:** `src/Component/Contract/PaymentContract.php`
**Database Table:** `osc_payments_contracts`
**Persistence Type:** Full entity persistence

**Table Columns:**
- `OXID` - Primary key (maps to $id)
- `OXSHOPID` - Shop ID
- `OXUSERID` - User ID
- `OXORDERID` - Order ID (nullable)
- `OXSTATE` - Contract state (string representation of ContractState value object)
- `OXBASKET` - Basket snapshot (JSON serialization of BasketSnapshot value object)
- `OXPROVIDER` - Provider name
- `OXPROVIDERORDERID` - Provider order ID
- `OXPROVIDERREDIRECTURL` - Provider redirect URL
- `OXEXPIRESAT` - Expiration timestamp
- `OXCREATED` - Creation timestamp
- `OXTIMESTAMP` - Last update timestamp
- `OXFULFILLEDAT` - Fulfillment timestamp

**Repository:** `DoctrineContractRepository` (to be implemented)

#### 2. ContractCondition (Entity)
**Location:** `src/Component/Contract/ContractCondition.php`
**Database Table:** `osc_payments_contract_conditions`
**Persistence Type:** Child entity of PaymentContract aggregate

**Table Columns:**
- `OXID` - Primary key
- `OXCONTRACTID` - Foreign key to osc_payments_contracts (aggregate root)
- `OXTYPE` - Condition type
- `OXSTATUS` - Condition status
- `OXDATA` - Condition data (JSON)
- `OXFULFILLEDAT` - Fulfillment timestamp
- `OXFAILUREREASON` - Failure reason (nullable)

**Note:** ContractCondition is part of the PaymentContract aggregate and managed through the aggregate root. It does not have its own repository.

#### 3. WebhookLog (Entity)
**Location:** `src/Component/Webhook/WebhookLog.php`
**Database Table:** `osc_payments_webhooklogs`
**Persistence Type:** Independent entity

**Table Columns:**
- `OXID` - Primary key (maps to $id)
- `OXEVENTID` - Webhook event ID (unique)
- `OXEVENTTYPE` - Event type
- `OXCONTRACTID` - Associated contract ID (nullable)
- `OXSTATUS` - Processing status
- `OXRECEIVEDAT` - Receipt timestamp
- `OXERROR` - Error message (nullable)

**Repository:** `WebhookLogRepository` (implemented)

---

### Non-Persistent Models (Value Objects)

Models that exist only in memory or are serialized as part of persistent entities:

#### 1. BasketSnapshot (Value Object)
**Location:** `src/Component/Contract/BasketSnapshot.php`
**Storage:** Serialized as JSON in `osc_payments_contracts.OXBASKET` column
**Persistence Type:** Embedded value object

**Characteristics:**
- Immutable
- No separate table
- Stored as JSON within PaymentContract
- Deserialized when PaymentContract is loaded
- No separate repository

**JSON Structure:**
```json
{
  "items": [...],
  "discounts": [...],
  "totalGross": 199.98,
  "totalNet": 168.05,
  "totalVat": 31.93,
  "currency": "EUR",
  "capturedAt": "2025-10-31 14:30:00"
}
```

#### 2. ContractState (Value Object)
**Location:** `src/Component/Contract/ContractState.php`
**Storage:** Stored as string in `osc_payments_contracts.OXSTATE` column
**Persistence Type:** Embedded value object (enum-like)

**Characteristics:**
- Immutable
- No separate table
- Stored as simple string value
- Reconstructed via factory methods when PaymentContract is loaded
- No separate repository

**String Values:**
- `draft`
- `pending`
- `ready_to_commit`
- `committed`
- `fulfilled`
- `cancelled`
- `expired`
- `failed`

---

### Persistence Strategy Summary

| Model | Type | Database Table | Storage Method | Repository |
|-------|------|---------------|----------------|------------|
| PaymentContract | Aggregate Root | osc_payments_contracts | Full persistence | DoctrineContractRepository |
| ContractCondition | Entity | osc_payments_contract_conditions | Full persistence | Via aggregate |
| WebhookLog | Entity | osc_payments_webhooklogs | Full persistence | WebhookLogRepository |
| BasketSnapshot | Value Object | - | JSON in parent table | None |
| ContractState | Value Object | - | String in parent table | None |

---

### DDD Persistence Patterns

#### Aggregate Persistence

**PaymentContract** is an aggregate root that controls persistence of its children:

```php
// When saving PaymentContract, the repository must also save:
// 1. The PaymentContract entity itself
// 2. All ContractCondition entities (children)
// 3. Serialize BasketSnapshot as JSON
// 4. Serialize ContractState as string

$contract = new PaymentContract($shopId, $userId, $basket);
$contract->addCondition(new ContractCondition('payment_authorized'));

// Repository saves entire aggregate in one transaction
$repository->save($contract);

// Database operations:
// - INSERT into osc_payments_contracts (contract data)
// - INSERT into osc_payments_contract_conditions (for each condition)
```

#### Value Object Serialization

Value objects are serialized as part of their parent entity:

```php
// BasketSnapshot -> JSON
$contract = new PaymentContract($shopId, $userId, $basketSnapshot);

// When persisted:
// OXBASKET column = json_encode($basketSnapshot->toArray())

// When loaded:
// $basketSnapshot = BasketSnapshot::fromArray(json_decode($row['OXBASKET'], true))
```

#### Repository Boundaries

**Repositories only exist for aggregate roots and independent entities:**

- ✅ `ContractRepository` - For PaymentContract aggregate
- ✅ `WebhookLogRepository` - For WebhookLog entity
- ❌ No `ContractConditionRepository` - Managed via PaymentContract
- ❌ No `BasketSnapshotRepository` - Value object, not persisted separately
- ❌ No `ContractStateRepository` - Value object, not persisted separately

---

## 📁 Model Types & Organization

### 1. Aggregate Roots

**Pattern:** Entity that serves as entry point to aggregate

#### PaymentContract

**Location:** `src/Component/Contract/PaymentContract.php`
**Interface:** `PaymentContractInterface` extends `ModelInterface`
**Base Class:** `AbstractModel`

**Characteristics:**
- Aggregate root for contract bounded context
- Manages contract lifecycle
- Controls access to child entities (ContractCondition)
- Enforces business rules

**Example:**
```php
class PaymentContract extends AbstractModel implements PaymentContractInterface
{
    // Inherits: $id, getId(), generateId()

    public function __construct(
        int $shopId,
        string $userId,
        BasketSnapshot $basketSnapshot,
        ?string $id = null
    ) {
        // Uses inherited generateId('contract')
        $this->id = $id ?? $this->generateId('contract');
        // ...
    }

    // Implements: toArray()
    public function toArray(): array
    {
        return [
            'id' => $this->id, // From AbstractModel
            'shopId' => $this->shopId,
            // ...
        ];
    }
}
```

---

### 2. Entities

**Pattern:** Objects with identity that change over time

#### ContractCondition

**Location:** `src/Component/Contract/ContractCondition.php`
**Type:** Entity (part of PaymentContract aggregate)

**Characteristics:**
- Has identity but no separate interface (part of aggregate)
- Managed by PaymentContract aggregate root
- State can change (pending → fulfilled/failed)

**Example:**
```php
class ContractCondition
{
    // Entity pattern - has methods but accessed via aggregate

    public function fulfill(array $data = []): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new \DomainException("Already fulfilled");
        }
        $this->status = self::STATUS_FULFILLED;
        $this->data = array_merge($this->data, $data);
        $this->fulfilledAt = new \DateTime();
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'status' => $this->status,
            'data' => $this->data,
            'fulfilledAt' => $this->fulfilledAt?->format('Y-m-d H:i:s'),
        ];
    }
}
```

---

### 3. Value Objects

**Pattern:** Immutable objects defined by their attributes

#### BasketSnapshot

**Location:** `src/Component/Contract/BasketSnapshot.php`
**Type:** Value Object

**Characteristics:**
- Immutable (no setters)
- Private constructor (factory method pattern)
- Defined by attributes, not identity
- No ID required

**Example:**
```php
class BasketSnapshot
{
    // Value Object - immutable

    private function __construct(
        array $items,
        array $discounts,
        float $totalGross,
        float $totalNet,
        float $totalVat,
        string $currency,
        \DateTimeInterface $capturedAt
    ) {
        // All properties set once in constructor
        $this->items = $items;
        $this->discounts = $discounts;
        // ... (no setters!)
    }

    // Factory method
    public static function fromArray(array $data): self
    {
        return new self(/* ... */);
    }

    // Only getters, no setters
    public function getItems(): array { return $this->items; }
    public function getTotalGross(): float { return $this->totalGross; }
}
```

#### ContractState

**Location:** `src/Component/Contract/ContractState.php`
**Type:** Value Object (Enum-like)

**Characteristics:**
- Immutable state representation
- Factory methods for each state
- Type-safe state management

**Example:**
```php
class ContractState
{
    private function __construct(private readonly string $value) {}

    // Factory methods
    public static function draft(): self { return new self('draft'); }
    public static function pending(): self { return new self('pending'); }

    // State checks
    public function isDraft(): bool { return $this->value === 'draft'; }
    public function isTerminal(): bool { /* ... */ }

    // Value Object equality
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

---

## 🎯 Design Patterns Applied

### 1. Abstract Factory Pattern

**AbstractModel** provides factory method for ID generation:

```php
// In AbstractModel
protected function generateId(string $prefix = 'id'): string
{
    return uniqid($prefix . '_', true);
}

// Usage in PaymentContract
$this->id = $id ?? $this->generateId('contract');
// Results in: contract_6543210abcdef...
```

### 2. Template Method Pattern

**AbstractModel** defines template with `toArray()` as abstract method:

```php
abstract class AbstractModel implements ModelInterface
{
    // Template requires implementation
    abstract public function toArray(): array;
}
```

### 3. Strategy Pattern (via Interfaces)

Models implement specific interfaces, allowing polymorphism:

```php
function processModel(ModelInterface $model): void
{
    $id = $model->getId();      // Works for any model
    $data = $model->toArray();  // Works for any model
}

// Usage
processModel($paymentContract); // ✓
processModel($orderModel);      // ✓ (if implements ModelInterface)
```

### 4. Aggregate Pattern (DDD)

**PaymentContract** as aggregate root:

```php
class PaymentContract extends AbstractModel
{
    // Aggregate controls child entities
    private array $conditions = [];

    public function addCondition(ContractCondition $condition): void
    {
        // Business rule enforcement
        if (!$this->state->isDraft()) {
            throw new \DomainException('Cannot add after DRAFT');
        }
        $this->conditions[] = $condition;
    }

    // External code cannot directly modify conditions
    // Must go through aggregate root methods
}
```

---

## 📊 Model Hierarchy

```
ModelInterface (interface)
    ├── toArray(): array
    └── getId(): ?string

AbstractModel (abstract class) implements ModelInterface
    ├── $id: ?string
    ├── getId(): ?string
    ├── generateId(prefix): string
    └── toArray(): array [abstract]

PaymentContractInterface extends ModelInterface
    ├── getStateValue(): string
    ├── getAmount(): float
    ├── getCurrency(): string
    └── ... (contract-specific methods)

PaymentContract extends AbstractModel implements PaymentContractInterface
    ├── PERSISTENCE: Database table (osc_payments_contracts)
    ├── TYPE: Aggregate Root
    ├── Inherits: $id, getId(), generateId()
    ├── Adds: $shopId, $userId, $state, $basket, $conditions
    └── Implements: toArray(), getStateValue(), getAmount(), etc.

ContractCondition (Entity - no interface, managed by aggregate)
    ├── PERSISTENCE: Database table (osc_payments_contract_conditions)
    ├── TYPE: Child Entity
    ├── Has identity (type + status)
    ├── State transitions (pending → fulfilled/failed)
    └── Accessed via PaymentContract aggregate

WebhookLog (Entity - independent)
    ├── PERSISTENCE: Database table (osc_payments_webhooklogs)
    ├── TYPE: Independent Entity
    ├── Has identity (eventId + id)
    └── Has own repository

BasketSnapshot (Value Object - no ID, immutable)
    ├── PERSISTENCE: JSON serialized in parent table (OXBASKET column)
    ├── TYPE: Embedded Value Object
    ├── Immutable attributes
    ├── Factory method: fromArray()
    └── Only getters

ContractState (Value Object - enum-like)
    ├── PERSISTENCE: String serialized in parent table (OXSTATE column)
    ├── TYPE: Embedded Value Object
    ├── Immutable state
    ├── Factory methods: draft(), pending(), etc.
    └── Type-safe state management
```

---

## ✅ Benefits of This Architecture

### 1. **Consistency**
- All models follow same base structure
- Predictable API across domain
- Easier to learn and maintain

### 2. **Reusability**
- Common functionality in AbstractModel
- No duplication of ID generation logic
- Shared serialization contract

### 3. **Type Safety**
- ModelInterface enforces contracts
- AbstractModel provides implementation
- Compile-time checks via interfaces

### 4. **Extensibility**
- Easy to add new models (extend AbstractModel)
- Easy to add new model types (implement ModelInterface)
- Backward compatible changes

### 5. **Testability**
- Mock ModelInterface for testing
- Test AbstractModel functionality once
- Focus tests on domain logic

### 6. **DDD Compliance**
- Clear aggregate boundaries
- Value objects properly immutable
- Entities have identity and lifecycle

---

## 🔍 Code Examples

### Creating a New Model

To create a new model following this architecture:

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Model\YourDomain;

use OxidSolutionCatalysts\Payments\Component\Model\AbstractModel;
use OxidSolutionCatalysts\Payments\Component\Model\ModelInterface;

// 1. Create specific interface (optional, but recommended for aggregates)
interface YourModelInterface extends ModelInterface
{
    public function getDomainSpecificData(): string;
}

// 2. Create concrete model
class YourModel extends AbstractModel implements YourModelInterface
{
    // Use inherited $id from AbstractModel

    public function __construct(
        private string $data,
        ?string $id = null
    ) {
        // Use inherited generateId()
        $this->id = $id ?? $this->generateId('yourmodel');
    }

    // Implement specific interface
    public function getDomainSpecificData(): string
    {
        return $this->data;
    }

    // Implement required toArray()
    public function toArray(): array
    {
        return [
            'id' => $this->id,           // From AbstractModel
            'data' => $this->data,
        ];
    }

    // Add factory method if needed
    public static function fromArray(array $data): self
    {
        return new self(
            data: $data['data'],
            id: $data['id'] ?? null
        );
    }
}
```

### Using Models Polymorphically

```php
// Function accepting any model
function saveModel(ModelInterface $model, RepositoryInterface $repo): void
{
    $data = $model->toArray();  // Works for any model
    $repo->save($data);
}

// Works with different model types
saveModel($paymentContract, $contractRepo);
saveModel($orderModel, $orderRepo);
saveModel($customerModel, $customerRepo);
```

---

## 📈 Current Implementation Status

### Implemented Models

| Model | Type | Base Class | Interface | Database Table | Persistence | Status |
|-------|------|------------|-----------|----------------|-------------|--------|
| PaymentContract | Aggregate Root | AbstractModel | PaymentContractInterface | osc_payments_contracts | PERSISTENT | ✅ |
| ContractCondition | Entity | - | - | osc_payments_contract_conditions | PERSISTENT | ✅ |
| WebhookLog | Entity | - | - | osc_payments_webhooklogs | PERSISTENT | ✅ |
| BasketSnapshot | Value Object | - | - | - | JSON in parent | ✅ |
| ContractState | Value Object | - | - | - | String in parent | ✅ |

### Base Infrastructure

| Component | Location | Status |
|-----------|----------|--------|
| ModelInterface | src/Component/Model/ModelInterface.php | ✅ |
| AbstractModel | src/Component/Model/AbstractModel.php | ✅ |
| Model/Contract/ | src/Component/Model/Contract/ | ✅ Created |
| Model/Entity/ | src/Component/Model/Entity/ | ✅ Created |
| Model/ValueObject/ | src/Component/Model/ValueObject/ | ✅ Created |

---

## 🧪 Testing Strategy

### Testing Base Classes

```php
// AbstractModel is tested through concrete implementations
// No direct tests needed for abstract class

// Test that PaymentContract properly uses AbstractModel
public function testInheritsIdFromAbstractModel(): void
{
    $contract = new PaymentContract(1, 'user-123', $basket);

    // getId() comes from AbstractModel
    $this->assertNotNull($contract->getId());
    $this->assertStringStartsWith('contract_', $contract->getId());
}
```

### Testing Interfaces

```php
// Test that interface contract is fulfilled
public function testImplementsModelInterface(): void
{
    $contract = new PaymentContract(1, 'user-123', $basket);

    $this->assertInstanceOf(ModelInterface::class, $contract);
    $this->assertIsString($contract->getId());
    $this->assertIsArray($contract->toArray());
}
```

---

## 🚀 Future Enhancements

### Potential Additions

1. **Timestamps Trait**
   ```php
   trait HasTimestamps
   {
       private \DateTimeInterface $createdAt;
       private \DateTimeInterface $updatedAt;

       protected function initTimestamps(): void
       {
           $this->createdAt = new \DateTime();
           $this->updatedAt = new \DateTime();
       }

       protected function touch(): void
       {
           $this->updatedAt = new \DateTime();
       }
   }
   ```

2. **Soft Delete Trait**
   ```php
   trait SoftDeletes
   {
       private ?\DateTimeInterface $deletedAt = null;

       public function delete(): void
       {
           $this->deletedAt = new \DateTime();
       }

       public function isDeleted(): bool
       {
           return $this->deletedAt !== null;
       }
   }
   ```

3. **Event Sourcing Support**
   ```php
   abstract class EventSourcedModel extends AbstractModel
   {
       private array $recordedEvents = [];

       protected function recordEvent(object $event): void
       {
           $this->recordedEvents[] = $event;
       }

       public function getRecordedEvents(): array
       {
           return $this->recordedEvents;
       }
   }
   ```

---

## 📚 References

**Design Patterns:**
- Martin Fowler - Domain Model Pattern
- Eric Evans - Domain-Driven Design
- Gang of Four - Design Patterns (Template Method, Factory)

**Related Documentation:**
- [TICKET-10-DATABASE-MODELS-STATUS.md](TICKET-10-DATABASE-MODELS-STATUS.md) - Implementation details
- [00-REMAINING-WORK-INDEX.md](../to-do/00-REMAINING-WORK-INDEX.md) - Project status

**Code Examples:**
- `src/Component/Model/ModelInterface.php` - Base interface
- `src/Component/Model/AbstractModel.php` - Base class
- `src/Component/Contract/PaymentContract.php` - Aggregate example
- `src/Component/Contract/ContractCondition.php` - Entity example
- `src/Component/Contract/BasketSnapshot.php` - Value object example

---

**Status:** ✅ IMPLEMENTED
**Version:** 1.0
**Last Updated:** 2025-10-31
