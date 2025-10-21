# System Architecture

**Version:** 1.0.0
**Date:** 2025-10-21
**Target Platform:** PHP 8.2+, PSR-12, SOLID, DDD, EDD
**Status:** Architecture Specification
**Visual Diagram:** [puml/01-system-architecture.puml](puml/01-system-architecture.puml)

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Layer Architecture](#layer-architecture)
3. [Component Responsibilities](#component-responsibilities)
4. [Design Patterns](#design-patterns)
5. [Data Flow](#data-flow)
6. [Deployment Architecture](#deployment-architecture)
7. [Technology Stack](#technology-stack)

---

## Architecture Overview

The blockchain-inspired inventory management system follows a **layered architecture** with clear separation of concerns:

```
┌─────────────────────────────────────────────────────────────┐
│ Application Layer (Event Handlers, Commands, Queries)      │
├─────────────────────────────────────────────────────────────┤
│ Domain Layer (Aggregates, Entities, Value Objects, Events) │
├─────────────────────────────────────────────────────────────┤
│ Infrastructure Layer (Repositories, Event Store, Consensus) │
├─────────────────────────────────────────────────────────────┤
│ Blockchain Layer (Conceptual: Ledger, Hash Chain, Consensus)│
└─────────────────────────────────────────────────────────────┘
```

### Core Principles

1. **Event-Driven Architecture**: All operations triggered by domain events
2. **Domain-Driven Design**: Rich domain models with business logic
3. **Event Sourcing**: Complete audit trail via immutable event log
4. **CQRS**: Separate read/write paths for performance
5. **Consensus Protocol**: Raft for distributed stock allocation
6. **Smart Contract Integration**: Payment lifecycle drives inventory operations

---

## Layer Architecture

### 1. Application Layer

**Responsibility:** Orchestrates use cases and workflows.

**Components:**
- **Event Handlers**: Subscribe to domain events and execute business workflows
  - `ReserveStockHandler`: Listens to `PaymentAuthorizedEvent`
  - `ReleaseStockHandler`: Listens to `PaymentFailedEvent`
  - `CommitStockHandler`: Listens to `OrderCreatedEvent`
  - `ShipStockHandler`: Listens to `OrderShippedEvent`

- **Commands**: Write operations (CQRS)
  - `ReserveStockCommand`: Reserve stock for contract
  - `ReleaseStockCommand`: Release stock reservation
  - `TransferStockCommand`: Transfer stock between warehouses

- **Queries**: Read operations (CQRS)
  - `GetStockLevelQuery`: Get available stock for SKU
  - `GetReservationsQuery`: Get active reservations
  - `GetWarehousesQuery`: Get warehouses with stock

**Code Example:**
```php
<?php

namespace Osc\Inventory\Application\Handler;

use Osc\Payment\Component\Event\PaymentAuthorizedEvent;
use Osc\Inventory\Domain\Service\InventoryServiceInterface;
use Osc\Payment\Component\Repository\ContractRepositoryInterface;

/**
 * Reserve Stock Handler
 *
 * Triggered when payment is authorized.
 * Reserves stock for payment contract.
 */
final class ReserveStockHandler
{
    public function __construct(
        private InventoryServiceInterface $inventoryService,
        private ContractRepositoryInterface $contractRepository
    ) {}

    public function handle(PaymentAuthorizedEvent $event): void
    {
        $contract = $this->contractRepository->find($event->getContractId());

        try {
            // Reserve stock across warehouses
            $reservations = $this->inventoryService->reserveStock($contract);

            // Fulfill contract condition
            $contract->fulfillCondition('TYPE_STOCK_RESERVED', [
                'reservations' => array_map(fn($r) => $r->getId(), $reservations)
            ]);

            $this->contractRepository->save($contract);

        } catch (InsufficientStockException $e) {
            // Stock unavailable → Fail condition
            $contract->failCondition('TYPE_STOCK_RESERVED', $e->getMessage());
            $this->contractRepository->save($contract);
            throw $e;
        }
    }
}
```

---

### 2. Domain Layer

**Responsibility:** Contains business logic and domain models.

**Components:**

#### Aggregates (Aggregate Roots)
- **InventoryItem**: Manages stock for a SKU across warehouses
- **Warehouse**: Represents a physical warehouse location

#### Entities
- **StockReservation**: Temporary hold on stock for a contract
- **StockLevel**: Tracks stock quantities at a warehouse

#### Value Objects
- **SKU**: Stock Keeping Unit identifier
- **Quantity**: Non-negative item count
- **Address**: Physical address with coordinates

#### Domain Services
- **InventoryService**: Orchestrates multi-aggregate operations
- **WarehouseAllocator**: Selects optimal warehouse for fulfillment

#### Domain Events
- **StockReservedEvent**: Stock reserved for contract
- **StockReleasedEvent**: Stock returned to available pool
- **StockCommittedEvent**: Stock allocated to order
- **StockShippedEvent**: Physical shipment from warehouse

**See:** [02-domain-models.md](02-domain-models.md) for complete domain model specification

---

### 3. Infrastructure Layer

**Responsibility:** Provides technical capabilities (persistence, caching, consensus).

**Components:**

#### Repositories
- **InventoryItemRepository**: Persistence for InventoryItem aggregate
- **WarehouseRepository**: Persistence for Warehouse aggregate
- **StockReservationRepository**: Persistence for reservations

#### Event Store
- **InventoryLedger**: Append-only event log with hash chain
- **Event Store**: Kafka/EventStoreDB for event persistence
- **Event Projections**: Read models built from events

#### Cache Layer
- **L1 Cache**: Local memory (1-5ms latency)
- **L2 Cache**: Redis cluster (5-20ms latency)
- **L3 Cache**: Event store projection (50-200ms latency)

#### Consensus Protocol
- **Raft Cluster**: Distributed consensus for stock allocation
- **Leader Node**: Serializes stock reservations
- **Follower Nodes**: Replicate log, ready for election

**Code Example:**
```php
<?php

namespace Osc\Inventory\Infrastructure\EventStore;

use Osc\Inventory\Domain\Event\DomainEvent;

/**
 * Inventory Ledger - Append-Only Event Log
 *
 * Implements blockchain principles:
 * - Immutable log
 * - Hash chain integrity
 * - Complete audit trail
 */
final class InventoryLedger
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private ConsensusProtocolInterface $consensus
    ) {}

    /**
     * Append event to ledger (via consensus)
     */
    public function append(DomainEvent $event): void
    {
        // Get previous event hash
        $previousHash = $this->getLatestHash();

        // Calculate hash chain
        $hash = $this->calculateHash($event, $previousHash);

        // Serialize event
        $eventData = [
            'event_id' => $event->getEventId(),
            'event_type' => $event->getEventName(),
            'payload' => $event->getPayload(),
            'hash' => $hash,
            'previous_hash' => $previousHash,
            'occurred_at' => $event->getOccurredAt()->format(\DateTime::ATOM),
        ];

        // Append via Raft consensus
        $this->consensus->append($eventData);

        // Persist to event store
        $this->eventStore->append($eventData);
    }

    /**
     * Calculate SHA-256 hash for event
     */
    private function calculateHash(DomainEvent $event, ?string $previousHash): string
    {
        $data = json_encode([
            'event_id' => $event->getEventId(),
            'event_type' => $event->getEventName(),
            'payload' => $event->getPayload(),
            'previous_hash' => $previousHash,
        ]);

        return hash('sha256', $data);
    }

    /**
     * Verify hash chain integrity
     */
    public function verifyIntegrity(): bool
    {
        $events = $this->eventStore->getAllEvents();
        $previousHash = null;

        foreach ($events as $event) {
            $expectedHash = $this->calculateHash($event, $previousHash);

            if ($event['hash'] !== $expectedHash) {
                return false;  // Hash chain broken!
            }

            $previousHash = $event['hash'];
        }

        return true;  // Integrity verified
    }
}
```

---

### 4. Blockchain Layer (Conceptual)

**Responsibility:** Provides blockchain-inspired guarantees WITHOUT using blockchain technology.

**Principles Applied:**

1. **Immutable Ledger**
   - All events append-only (no UPDATE/DELETE)
   - Complete history preserved
   - Time-travel queries possible

2. **Hash Chain**
   - Each event links to previous via hash
   - Tampering detection (breaks chain)
   - Cryptographic integrity

3. **Distributed Consensus**
   - Raft protocol (NOT Proof-of-Work)
   - Leader election
   - Log replication
   - Linearizability guarantee

4. **Audit Transparency**
   - Every stock movement recorded
   - Who, what, when, where
   - Immutable proof for compliance

---

## Component Responsibilities

### ReserveStockHandler
- **Trigger:** `PaymentAuthorizedEvent`
- **Input:** Payment contract with basket items
- **Process:**
  1. Extract SKUs and quantities from basket
  2. For each SKU, call `WarehouseAllocator::findOptimal()`
  3. Call `InventoryItem::reserve()` for each warehouse
  4. Emit `StockReservedEvent` for each reservation
  5. Fulfill `TYPE_STOCK_RESERVED` condition on contract
- **Output:** Stock reserved, contract condition met

### ReleaseStockHandler
- **Trigger:** `PaymentFailedEvent` or `ContractExpiredEvent`
- **Input:** Payment contract with active reservations
- **Process:**
  1. Get reservation IDs from contract
  2. Call `InventoryItem::releaseReservation()` for each
  3. Emit `StockReleasedEvent` for each release
  4. Fail `TYPE_STOCK_RESERVED` condition on contract
- **Output:** Stock returned to available pool

### CommitStockHandler
- **Trigger:** `OrderCreatedEvent`
- **Input:** Payment contract, order ID
- **Process:**
  1. Get reservation IDs from contract
  2. Call `InventoryItem::commitReservation()` for each
  3. Emit `StockCommittedEvent` for each commitment
- **Output:** Stock permanently allocated to order

### WarehouseAllocator
- **Input:** SKU, quantity, customer address
- **Process:**
  1. Get warehouses with sufficient stock
  2. Calculate score for each:
     - Distance to customer (lower better)
     - Warehouse load (lower better)
     - Shipping cost (lower better)
  3. Rank warehouses by score
  4. Return optimal warehouse
- **Output:** Warehouse or null (out of stock)

**Scoring Algorithm:**
```php
score = (distance_km * 0.7) + (load_percentage * 0.2) + (shipping_cost * 0.1)
```

**Example:**
```
Warehouse A (NY):  distance=10km,  load=30%, cost=$5  → score = 7 + 6 + 0.5 = 13.5
Warehouse B (LA):  distance=400km, load=20%, cost=$50 → score = 280 + 4 + 5 = 289
Warehouse C (CHI): distance=100km, load=10%, cost=$10 → score = 70 + 2 + 1 = 73

→ Select Warehouse A (lowest score)
```

---

## Design Patterns

### 1. Aggregate Pattern (DDD)
- **Purpose:** Enforce invariants and consistency boundaries
- **Implementation:** `InventoryItem` and `Warehouse` are aggregate roots
- **Benefits:** Transaction boundary, consistency guarantee

### 2. Repository Pattern
- **Purpose:** Abstract data access
- **Implementation:** Interfaces in domain, implementations in infrastructure
- **Benefits:** Testability, flexibility, separation of concerns

### 3. Event Sourcing
- **Purpose:** Complete audit trail and time-travel queries
- **Implementation:** All state changes captured as events
- **Benefits:** Perfect audit, debugging, replay capability

### 4. CQRS (Command-Query Responsibility Segregation)
- **Purpose:** Separate read/write paths for performance
- **Implementation:** Commands modify state, queries read projections
- **Benefits:** 100x faster reads, scalability

### 5. Saga Pattern (Distributed Transactions)
- **Purpose:** Coordinate multi-step workflows across aggregates
- **Implementation:** Event handlers choreograph workflow
- **Benefits:** No distributed transactions, eventual consistency

### 6. Template Method (Raft Consensus)
- **Purpose:** Consistent consensus algorithm
- **Implementation:** Leader/follower roles, log replication
- **Benefits:** Fault tolerance, linearizability

---

## Data Flow

### Stock Reservation Flow

```
1. Payment Authorized
   ↓
2. PaymentAuthorizedEvent emitted
   ↓
3. ReserveStockHandler triggered
   ↓
4. WarehouseAllocator finds optimal warehouse
   ↓
5. InventoryItem.reserve() called
   ↓
6. Raft consensus: Leader serializes request
   ↓
7. StockLevel updated (available → reserved)
   ↓
8. StockReservedEvent emitted
   ↓
9. Event appended to ledger (hash chain)
   ↓
10. Cache updated (Redis DECRBY)
   ↓
11. Contract condition fulfilled
   ↓
12. Contract state: PENDING → READY_TO_COMMIT
```

**See Diagrams:**
- [puml/03-sequence-stock-reservation.puml](puml/03-sequence-stock-reservation.puml)
- [puml/04-sequence-stock-release.puml](puml/04-sequence-stock-release.puml)

---

## Deployment Architecture

### Production Deployment

```
                       ┌─────────────────┐
                       │  Load Balancer  │
                       │  (HAProxy)      │
                       └────────┬────────┘
                                │
             ┌──────────────────┼──────────────────┐
             │                  │                  │
    ┌────────▼────────┐  ┌─────▼──────┐  ┌───────▼──────┐
    │  App Server 1   │  │App Server 2│  │App Server 3  │
    │  (PHP-FPM)      │  │(PHP-FPM)   │  │(PHP-FPM)     │
    └────────┬────────┘  └─────┬──────┘  └───────┬──────┘
             │                  │                  │
             └──────────────────┼──────────────────┘
                                │
        ┌───────────────────────┼───────────────────────┐
        │                       │                       │
  ┌─────▼──────┐      ┌────────▼────────┐    ┌────────▼────────┐
  │Redis Cluster│      │ Raft Cluster    │    │Event Store      │
  │(Cache L2)   │      │ (Consensus)     │    │(Kafka/EventDB)  │
  │             │      │                 │    │                 │
  │ Master      │      │ Leader          │    │ Broker 1        │
  │ + Replicas  │      │ Follower 1      │    │ Broker 2        │
  │             │      │ Follower 2      │    │ Broker 3        │
  └─────────────┘      └─────────────────┘    └─────────────────┘
        │                       │                       │
        └───────────────────────┼───────────────────────┘
                                │
                      ┌─────────▼─────────┐
                      │  MySQL Cluster    │
                      │  (Primary + Read  │
                      │   Replicas)       │
                      └───────────────────┘
```

### Recommended Cluster Sizes

| Environment | App Servers | Redis Nodes | Raft Nodes | Event Store | MySQL |
|-------------|-------------|-------------|------------|-------------|-------|
| **Development** | 1 | 1 | 1 | 1 | 1 |
| **Staging** | 2 | 2 (master+replica) | 3 | 3 | 2 (primary+replica) |
| **Production** | 3-10 | 6 (3 shards × 2) | 5 | 5 | 3 (primary+2 replicas) |

### High Availability

- **App Servers:** Stateless, scale horizontally
- **Redis:** Master-replica replication, automatic failover
- **Raft:** 5-node cluster survives 2 failures
- **Event Store:** 5 brokers with replication factor 3
- **MySQL:** Primary-replica replication with automatic failover

---

## Technology Stack

### Required Components

| Layer | Technology | Version | Purpose |
|-------|-----------|---------|---------|
| **Runtime** | PHP | 8.2+ | Application runtime |
| **Framework** | Symfony | 6.4+ | HTTP, DI, Events |
| **Database** | MySQL | 8.0+ | Relational data |
| **Cache** | Redis | 7.0+ | L2 cache, session |
| **Event Store** | Kafka | 3.0+ | Event log |
| **Consensus** | etcd | 3.5+ | Raft implementation |
| **Monitoring** | Prometheus | 2.40+ | Metrics collection |
| **Logging** | Monolog | 3.0+ | PSR-3 logging |

### Optional Enhancements

| Component | Technology | Benefit |
|-----------|-----------|---------|
| **Event Store** | EventStoreDB | Native event sourcing |
| **Search** | Elasticsearch | Fast inventory queries |
| **Queue** | RabbitMQ | Async processing |
| **Tracing** | Jaeger | Distributed tracing |
| **Dashboards** | Grafana | Visualization |

---

## Performance Characteristics

### Latency Targets

| Operation | Target (P50) | Target (P99) | Notes |
|-----------|--------------|--------------|-------|
| **Stock Query** | 5ms | 20ms | From Redis cache |
| **Stock Reservation** | 100ms | 300ms | Via Raft consensus |
| **Event Append** | 20ms | 50ms | To event store |
| **Cache Update** | 5ms | 10ms | Redis write |

### Throughput Targets

| Operation | Target | Notes |
|-----------|--------|-------|
| **Stock Queries** | 100,000 req/s | With cache + replicas |
| **Stock Reservations** | 10,000 req/s | Per Raft cluster |
| **Event Writes** | 50,000 msg/s | Kafka cluster |

### Scalability

- **Horizontal:** Add app servers, Redis shards, Raft clusters
- **Vertical:** Increase resources per node
- **Partitioning:** Shard by SKU ranges (A-F, G-L, M-R, S-Z)

---

## Security Considerations

### Data Protection
- **Encryption at Rest:** MySQL TDE, Redis encryption
- **Encryption in Transit:** TLS 1.3 for all connections
- **Access Control:** Role-based access (RBAC)

### Audit & Compliance
- **Immutable Audit Log:** All events permanently recorded
- **GDPR Compliance:** Right to be forgotten (pseudonymization)
- **ISO 9001:** Complete audit trail for certification

### Monitoring & Alerting
- **Overselling Alert:** If overselling detected (> 0.01%)
- **Consensus Failure:** If Raft cluster loses quorum
- **Event Store Lag:** If event processing > 1 second behind

---

## Related Documentation

- **[02-domain-models.md](02-domain-models.md)** - Domain model classes
- **[03-database-schema.md](03-database-schema.md)** - Database design
- **[04-smart-contract-integration.md](04-smart-contract-integration.md)** - Payment integration
- **[05-consensus-protocol.md](05-consensus-protocol.md)** - Raft consensus details
- **[09-tdd-strategy.md](09-tdd-strategy.md)** - TDD testing approach

---

**Version:** 1.0.0
**Last Updated:** 2025-10-21
**Status:** Architecture Complete
