# TDD Strategy for Blockchain Inventory Management

**Version:** 1.0.0
**Date:** 2025-10-21
**Target Platform:** PHP 8.2+, PSR-12, SOLID, TDD, DDD, EDD
**Status:** Test Planning & Strategy
**Visual Diagram:** [puml/09-tdd-strategy.puml](puml/09-tdd-strategy.puml)

---

## Table of Contents

1. [Overview](#overview)
2. [Priority Classification](#priority-classification)
3. [Critical Priority Blocks](#critical-priority-blocks)
4. [Test Pyramid Strategy](#test-pyramid-strategy)
5. [Test Organization](#test-organization)
6. [Coverage Goals](#coverage-goals)
7. [Test Data & Fixtures](#test-data--fixtures)
8. [Mocking Strategy](#mocking-strategy)
9. [CI/CD Integration](#cicd-integration)

---

## Overview

This document defines the Test-Driven Development (TDD) strategy for the blockchain-inspired inventory management system. All code MUST be written test-first following these principles:

### TDD Workflow

```
1. RED: Write failing test
2. GREEN: Write minimal code to pass
3. REFACTOR: Improve code quality
4. REPEAT
```

### Test-First Benefits

- **Zero Regression**: Tests catch breaking changes immediately
- **Better Design**: TDD forces modular, testable architecture
- **Living Documentation**: Tests document expected behavior
- **Confidence**: Deploy with certainty that system works

---

## Priority Classification

### 🔴 CRITICAL (P0)
Must implement FIRST. System cannot function without these. Security, data integrity, money handling.

### 🟠 HIGH (P1)
Core business logic. Required for minimum viable product (MVP).

### 🟡 MEDIUM (P2)
Important features. Enhance reliability and user experience.

### 🟢 LOW (P3)
Nice to have. Can be implemented later.

---

## Critical Priority Blocks

### Block 1: Inventory Integrity & Race Conditions 🔴 CRITICAL (P0)

**Why Critical:** Overselling directly impacts revenue, customer trust, and compliance.

#### 1.1 Consensus Protocol - Stock Reservation (P0-A)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration + E2E
- **Components:**
  - `ConsensusProtocol::reserveStock()` - Raft-based reservation
  - `StockAllocationService::allocate()` - Warehouse selection
  - `InventoryLedger::append()` - Event persistence
  - Race condition prevention
  - Concurrent access handling

**Critical Test Scenarios:**
```php
// tests/Unit/Consensus/RaftProtocol_StockReservation_CRITICAL_Test.php

✅ testConcurrentReservations_OnlyOneSucceeds()
   // 10 customers try to reserve last item → Only 1 succeeds

✅ testRaceCondition_SerializedByLeader()
   // Parallel requests → Serialized log entries → No overselling

✅ testLeaderElection_PreservesConsistency()
   // Leader fails mid-reservation → New leader elected → Consistency maintained

✅ testStockReservation_AtomicWithContractCondition()
   // Stock reserved AND contract condition fulfilled OR both fail

✅ testReservationTimeout_AutoRollback()
   // Contract expires → Stock automatically released

✅ testIdempotency_DuplicateRequests()
   // Same contract ID → Same result (no double reservation)
```

**Implementation Order:**
1. Write test for single reservation (baseline)
2. Write test for concurrent reservations (race condition)
3. Implement Raft leader election
4. Implement log replication
5. Test leader failover scenarios
6. Implement idempotency checks

---

#### 1.2 Event Ledger - Immutable Audit Trail (P0-B)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - `InventoryLedger::append()` - Append-only event log
  - `InventoryLedger::verifyIntegrity()` - Hash chain validation
  - `EventStore` integration (Kafka/EventStoreDB)
  - Event ordering guarantees

**Critical Test Scenarios:**
```php
// tests/Unit/Ledger/InventoryLedger_Integrity_CRITICAL_Test.php

✅ testAppendEvent_ImmutableAfterCreation()
   // Event cannot be modified once appended

✅ testHashChain_DetectsTampering()
   // Modify event → Hash chain breaks → Detected

✅ testEventOrdering_ChronologicalGuarantee()
   // Events ordered by timestamp → Replay produces consistent state

✅ testMultipleWarehouses_SeparateLedgers()
   // Each warehouse maintains independent ledger

✅ testLedgerRecovery_FromSnapshot()
   // Replay events from snapshot → Correct current state

✅ testConcurrentAppends_SerializedWrites()
   // Multiple events → Serialized by event store
```

**Implementation Order:**
1. Define event schema (JSON)
2. Implement hash chain (SHA-256)
3. Write test for tampering detection
4. Implement append-only storage
5. Test event ordering guarantees
6. Implement snapshot mechanism

---

#### 1.3 Stock Release on Contract Failure (P0-C)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - `ReleaseStockHandler` - Subscribes to PaymentFailedEvent
  - `InventoryService::releaseStock()` - Return stock to available pool
  - Automatic rollback on timeout
  - Multiple item rollback (all-or-nothing)

**Critical Test Scenarios:**
```php
// tests/Unit/Handler/ReleaseStockHandler_CRITICAL_Test.php

✅ testPaymentFailed_StockReleased()
   // PaymentFailedEvent → Stock returned to available pool

✅ testContractExpired_AutoRelease()
   // Contract expires after 5 minutes → Stock automatically released

✅ testMultipleItems_AllOrNothing()
   // Order has 3 items, payment fails → All 3 released

✅ testAlreadyReleased_Idempotent()
   // Duplicate PaymentFailedEvent → No double-release

✅ testReleaseLinksToReservation_AuditTrail()
   // STOCK_RELEASED event references original STOCK_RESERVED event

✅ testCacheInvalidation_OnRelease()
   // Stock released → Cache updated immediately
```

**Implementation Order:**
1. Subscribe ReleaseStockHandler to PaymentFailedEvent
2. Write test for single item release
3. Implement stock release logic
4. Test multiple items (transaction)
5. Implement contract expiry scheduler
6. Test cache invalidation

---

### Block 2: Payment Contract Integration 🔴 CRITICAL (P0)

#### 2.1 Smart Contract Conditions (P0-D)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - `ReserveStockHandler` - Subscribes to PaymentAuthorizedEvent
  - `ContractCondition::TYPE_STOCK_RESERVED` - Inventory condition
  - `PaymentContract::fulfillCondition()` - Mark condition as met
  - Condition ordering (payment before stock)

**Critical Test Scenarios:**
```php
// tests/Integration/SmartContract/StockReservationCondition_CRITICAL_Test.php

✅ testPaymentAuthorized_TriggersStockReservation()
   // PaymentAuthorizedEvent → ReserveStockHandler executes

✅ testStockAvailable_ConditionFulfilled()
   // Stock reserved successfully → Contract condition fulfilled

✅ testStockUnavailable_ConditionFailed()
   // Out of stock → Contract condition failed → Payment void

✅ testConditionOrder_PaymentBeforeStock()
   // Payment must be authorized before stock reservation

✅ testAllConditionsMet_ContractReady()
   // Payment + Stock + Fraud → Contract READY_TO_COMMIT

✅ testContractCancelled_StockNotReserved()
   // Contract cancelled before stock check → No reservation attempt
```

**Implementation Order:**
1. Create ContractCondition::TYPE_STOCK_RESERVED
2. Write test for condition fulfillment
3. Implement ReserveStockHandler
4. Test condition ordering
5. Implement failure scenarios
6. Test contract state transitions

---

#### 2.2 Contract Lifecycle Integration (P0-E)
- **Coverage Required:** 100%
- **Test Types:** Integration + E2E
- **Components:**
  - `PaymentContract` lifecycle (DRAFT → PENDING → COMMITTED → FULFILLED)
  - Stock reservation timing
  - Order creation after conditions met
  - Stock commitment on capture

**Critical Test Scenarios:**
```php
// tests/Integration/Contract/InventoryLifecycle_CRITICAL_Test.php

✅ testContractCreated_NoStockReserved()
   // Contract created → Stock NOT reserved yet

✅ testPaymentAuthorized_StockReserved()
   // Payment authorized → Stock reserved, contract condition met

✅ testAllConditionsMet_OrderCreated()
   // Payment + Stock + Fraud → Order created, stock committed

✅ testPaymentCaptured_StockShipped()
   // Payment captured → Stock marked as shipped

✅ testContractExpired_StockReleased()
   // Contract timeout → Stock released, condition failed

✅ testMultipleItemsContract_AtomicReservation()
   // Order has 3 SKUs → All reserved or none (transaction)
```

**Implementation Order:**
1. Map contract states to stock states
2. Write test for stock reservation timing
3. Implement stock reservation on PaymentAuthorized
4. Test stock commitment on order creation
5. Implement stock shipment on PaymentCaptured
6. Test contract expiry rollback

---

### Block 3: Data Persistence & Repository Layer 🔴 CRITICAL (P0)

#### 3.1 Repository Pattern (P0-F)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - `InventoryLedgerRepository` - Event persistence
  - `StockReservationRepository` - Reservation tracking
  - `WarehouseRepository` - Warehouse data
  - Foreign key constraints
  - Transaction atomicity

**Critical Test Scenarios:**
```php
// tests/Unit/Repository/InventoryLedgerRepository_CRITICAL_Test.php

✅ testAppendEvent_EnforcesRequiredFields()
   // Missing required field → Exception thrown

✅ testGetEventsBySkuAndWarehouse_ChronologicalOrder()
   // Query events → Returned in timestamp order

✅ testConcurrentAppends_NoRaceCondition()
   // Two threads append → Both succeed, serialized

✅ testForeignKeyConstraint_ReferencesWarehouse()
   // Invalid warehouse ID → FK constraint violation

✅ testTransactionRollback_OnError()
   // Error during append → Transaction rolled back

✅ testSnapshotCreation_EveryNEvents()
   // After 10,000 events → Snapshot created
```

**Implementation Order:**
1. Create inventory_ledger table
2. Write test for required fields
3. Implement repository CRUD operations
4. Test foreign key constraints
5. Implement transaction handling
6. Test concurrent access

---

### Block 4: Multi-Warehouse Coordination 🟠 HIGH (P1)

#### 4.1 Warehouse Selection Algorithm (P1-A)
- **Coverage Required:** 95%
- **Test Types:** Unit + Integration
- **Components:**
  - `WarehouseAllocator::findOptimal()` - Distance, cost, load
  - `ShippingCostCalculator` - Cost calculations
  - `DistanceCalculator` - Geo-distance
  - Split shipment optimization

**Test Scenarios:**
```php
// tests/Unit/Service/WarehouseAllocator_Test.php

✅ testNearestWarehouse_Selected()
   // Customer in NY → NY warehouse selected (over LA)

✅ testLoadBalancing_OverridesDistance()
   // NY warehouse at 90% capacity → Chicago selected

✅ testSplitShipment_CostOptimized()
   // 2 items in different warehouses → Split if cost-effective

✅ testConsolidatedShipment_TimeOptimized()
   // 2 items → Transfer + ship from one warehouse if faster

✅ testRegionalPolicy_MinStockEnforced()
   // Stock below threshold → Reorder triggered
```

---

#### 4.2 Stock Transfer Between Warehouses (P1-B)
- **Coverage Required:** 95%
- **Test Types:** Integration
- **Components:**
  - `StockTransferService` - Initiate transfers
  - `STOCK_TRANSFERRED` event - Source warehouse
  - `STOCK_RECEIVED` event - Destination warehouse
  - Transfer tracking

**Test Scenarios:**
```php
// tests/Integration/Service/StockTransferService_Test.php

✅ testTransferInitiated_BothLedgersUpdated()
   // Transfer 50 units NY → LA → Both ledgers reflect change

✅ testTransferInTransit_StockUnavailable()
   // Stock in transit → Not available at source or destination

✅ testTransferCompleted_DestinationStockIncreased()
   // Received at destination → Available stock increased

✅ testAutomatedReorder_LowStockTrigger()
   // Stock below threshold → Auto-transfer triggered
```

---

### Block 5: Performance & Caching 🟡 MEDIUM (P2)

#### 5.1 Multi-Level Cache (P2-A)
- **Coverage Required:** 90%
- **Test Types:** Integration
- **Components:**
  - L1 Cache: Local memory (1-5ms)
  - L2 Cache: Redis (5-20ms)
  - L3 Cache: Event Store projection (50-200ms)
  - Cache invalidation
  - Cache consistency

**Test Scenarios:**
```php
// tests/Integration/Cache/MultiLevelCache_Test.php

✅ testStockQuery_ServedFromL1Cache()
   // Query stock → L1 cache hit → 1-5ms latency

✅ testL1Miss_FallsBackToL2()
   // L1 miss → Query Redis (L2) → Cache populated

✅ testCacheInvalidation_OnStockChange()
   // Stock reserved → L1 and L2 invalidated

✅ testCacheWarmup_OnSystemStart()
   // System starts → Top 1000 SKUs preloaded to cache

✅ testCacheTTL_Expiration()
   // Cache expires after 1 hour → Refreshed from L3
```

---

#### 5.2 CQRS Pattern (P2-B)
- **Coverage Required:** 90%
- **Test Types:** Integration
- **Components:**
  - Command: `ReserveStockCommand` - Write path
  - Query: `GetStockLevelQuery` - Read path
  - Read models (projections)
  - Eventual consistency

**Test Scenarios:**
```php
// tests/Integration/CQRS/ReadWriteSeparation_Test.php

✅ testWriteCommand_UpdatesEventStore()
   // ReserveStockCommand → Event appended to store

✅ testReadQuery_ServedFromProjection()
   // GetStockLevelQuery → Read from projection (not event store)

✅ testEventualConsistency_WithinLatencyBound()
   // Write → Read within 100ms → Consistent result

✅ testProjectionRebuild_FromEventStore()
   // Projection corrupted → Rebuild from events
```

---

## Test Pyramid Strategy

### Test Distribution

```
        ┌───────────┐
        │ E2E Tests │  10% (Slow, High Value)
        │   (100)   │
        ├───────────┤
        │Integration│  30% (Medium Speed)
        │   (300)   │
        ├───────────┤
        │Unit Tests │  60% (Fast, Low Level)
        │   (600)   │
        └───────────┘
```

### Unit Tests (60% - ~600 tests)
- **Speed**: < 1s for entire suite
- **Scope**: Single class, mocked dependencies
- **Examples**: Domain models, value objects, algorithms

### Integration Tests (30% - ~300 tests)
- **Speed**: < 30s for entire suite
- **Scope**: Multiple classes, real database
- **Examples**: Repositories, event handlers, consensus protocol

### E2E Tests (10% - ~100 tests)
- **Speed**: < 5 minutes for entire suite
- **Scope**: Full system, real dependencies
- **Examples**: Complete checkout flow, multi-warehouse scenarios

---

## Test Organization

### Directory Structure

```
tests/
├── Unit/
│   ├── Domain/
│   │   ├── Model/
│   │   │   ├── InventoryItemTest.php
│   │   │   ├── StockReservationTest.php
│   │   │   └── WarehouseTest.php
│   │   ├── ValueObject/
│   │   │   ├── SKUTest.php
│   │   │   ├── QuantityTest.php
│   │   │   └── StockLevelTest.php
│   │   └── Service/
│   │       ├── InventoryServiceTest.php
│   │       ├── WarehouseAllocatorTest.php
│   │       └── StockTransferServiceTest.php
│   ├── Infrastructure/
│   │   ├── Repository/
│   │   │   ├── InventoryLedgerRepositoryTest.php
│   │   │   └── StockReservationRepositoryTest.php
│   │   ├── Consensus/
│   │   │   └── RaftProtocolTest.php
│   │   └── Cache/
│   │       └── MultiLevelCacheTest.php
│   └── Application/
│       ├── Handler/
│       │   ├── ReserveStockHandlerTest.php
│       │   └── ReleaseStockHandlerTest.php
│       └── Command/
│           └── ReserveStockCommandTest.php
├── Integration/
│   ├── SmartContract/
│   │   └── StockReservationConditionTest.php
│   ├── EventSourcing/
│   │   └── InventoryLedgerIntegrationTest.php
│   ├── Consensus/
│   │   └── RaftConsensusIntegrationTest.php
│   └── Repository/
│       └── RepositoryTransactionTest.php
├── E2E/
│   ├── CheckoutFlow/
│   │   ├── SingleItemOrderTest.php
│   │   └── MultiItemOrderTest.php
│   ├── MultiWarehouse/
│   │   ├── SplitShipmentTest.php
│   │   └── StockTransferTest.php
│   └── Performance/
│       └── ConcurrentReservationsTest.php
└── Fixtures/
    ├── ContractFixture.php
    ├── WarehouseFixture.php
    └── InventoryFixture.php
```

---

## Coverage Goals

### Overall Coverage: 95%+

| Component | Coverage Target | Rationale |
|-----------|----------------|-----------|
| **Domain Models** | 100% | Core business logic |
| **Event Handlers** | 100% | Critical workflows |
| **Repositories** | 100% | Data integrity |
| **Consensus Protocol** | 100% | Race condition prevention |
| **Services** | 95% | Business logic |
| **Controllers** | 90% | Input validation |
| **Factories** | 90% | Object creation |
| **Cache Layer** | 90% | Performance optimization |
| **Admin UI** | 80% | Lower risk |

### Mutation Testing

Use **Infection** to verify test quality:

```bash
composer require --dev infection/infection

./vendor/bin/infection --min-msi=85
```

**Mutation Score Index (MSI) Target**: 85%+

---

## Test Data & Fixtures

### Fixture Classes

```php
// tests/Fixtures/WarehouseFixture.php

class WarehouseFixture {
    public static function createNewYorkWarehouse(): Warehouse {
        return new Warehouse(
            id: 'warehouse-ny-001',
            name: 'New York Distribution Center',
            address: new Address(
                street: '123 Main St',
                city: 'New York',
                state: 'NY',
                zip: '10001',
                country: 'US'
            ),
            capacity: 100000,
            currentLoad: 0.25  // 25% loaded
        );
    }
}
```

### Test Database

Use **separate test database** with automatic cleanup:

```php
// phpunit.xml
<php>
    <env name="DB_DATABASE" value="inventory_test"/>
    <env name="REDIS_DATABASE" value="15"/>  <!-- Use separate Redis DB -->
</php>
```

**Database Migrations:**
```bash
# Before tests
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test

# After tests
php bin/console doctrine:database:drop --env=test --force
```

---

## Mocking Strategy

### What to Mock

✅ **Mock These:**
- External APIs (Raft cluster in unit tests)
- Event Store (Kafka/EventStoreDB in unit tests)
- Cache (Redis in unit tests)
- Time-dependent operations (use ClockInterface)

❌ **Don't Mock These:**
- Domain models (test real objects)
- Value objects (lightweight, no side effects)
- Repositories in integration tests (use real DB)

### Example: Mocking Consensus Protocol

```php
// tests/Unit/Handler/ReserveStockHandlerTest.php

use Mockery;

public function testStockReservation_SuccessfulConsensus(): void
{
    // Mock consensus protocol
    $consensus = Mockery::mock(ConsensusProtocolInterface::class);
    $consensus->shouldReceive('reserveStock')
        ->once()
        ->with(
            Mockery::type('string'),  // SKU
            Mockery::type('int'),     // Quantity
            Mockery::type('string')   // Contract ID
        )
        ->andReturn(new ReservationResult(success: true, reservationId: 'res_123'));

    // Test handler
    $handler = new ReserveStockHandler(
        consensusProtocol: $consensus,
        ledgerRepository: $this->ledgerRepo,
        contractRepository: $this->contractRepo
    );

    $event = new PaymentAuthorizedEvent($contract);
    $handler->handle($event);

    // Assert contract condition fulfilled
    $this->assertTrue($contract->getCondition('TYPE_STOCK_RESERVED')->isFulfilled());
}
```

---

## CI/CD Integration

### GitHub Actions Workflow

```yaml
# .github/workflows/test.yml

name: Inventory Manager Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: inventory_test
      redis:
        image: redis:7.0

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, pdo_mysql, redis
          coverage: xdebug

      - name: Install Dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run Unit Tests
        run: ./vendor/bin/phpunit --testsuite unit --coverage-clover coverage.xml

      - name: Run Integration Tests
        run: ./vendor/bin/phpunit --testsuite integration

      - name: Run E2E Tests
        run: ./vendor/bin/phpunit --testsuite e2e

      - name: Check Coverage
        run: |
          COVERAGE=$(php -r "echo round((simplexml_load_file('coverage.xml')->project->metrics['coveredstatements'] / simplexml_load_file('coverage.xml')->project->metrics['statements']) * 100, 2);")
          if (( $(echo "$COVERAGE < 95" | bc -l) )); then
            echo "Coverage $COVERAGE% is below 95% threshold"
            exit 1
          fi

      - name: Upload Coverage to Codecov
        uses: codecov/codecov-action@v3
        with:
          files: ./coverage.xml
```

---

## Best Practices

### 1. Test Naming Convention

```
test{MethodName}_{Scenario}_{ExpectedOutcome}()
```

**Examples:**
- `testReserveStock_ConcurrentRequests_OnlyOneSucceeds()`
- `testReleaseStock_PaymentFailed_StockReturned()`
- `testFindOptimalWarehouse_DistanceVsLoad_LoadPreferred()`

### 2. Arrange-Act-Assert (AAA) Pattern

```php
public function testReserveStock_SuccessfulReservation(): void
{
    // ARRANGE: Set up test data
    $contract = ContractFixture::createWithItems([
        ['sku' => 'IPHONE-15', 'quantity' => 1]
    ]);
    $warehouse = WarehouseFixture::createNewYorkWarehouse();
    $this->seedStock('IPHONE-15', $warehouse, 10);

    // ACT: Execute the operation
    $result = $this->inventoryService->reserveStock($contract, $warehouse);

    // ASSERT: Verify the outcome
    $this->assertTrue($result->isSuccess());
    $this->assertEquals(9, $this->getAvailableStock('IPHONE-15', $warehouse));
    $this->assertEventAppended('STOCK_RESERVED');
}
```

### 3. Test Independence

Each test MUST be independent:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->clearDatabase();
    $this->seedTestData();
}

protected function tearDown(): void
{
    $this->clearDatabase();
    parent::tearDown();
}
```

### 4. Test Data Builders

```php
class ContractBuilder {
    private array $items = [];
    private string $state = 'draft';

    public function withItem(string $sku, int $qty): self {
        $this->items[] = ['sku' => $sku, 'quantity' => $qty];
        return $this;
    }

    public function inState(string $state): self {
        $this->state = $state;
        return $this;
    }

    public function build(): PaymentContract {
        return new PaymentContract(/* ... */);
    }
}

// Usage
$contract = (new ContractBuilder())
    ->withItem('IPHONE-15', 1)
    ->withItem('MACBOOK-PRO', 1)
    ->inState('pending')
    ->build();
```

---

## Implementation Checklist

### Phase 1: Critical Tests (P0)
- [ ] Consensus protocol - stock reservation
- [ ] Event ledger - immutable audit trail
- [ ] Stock release on contract failure
- [ ] Smart contract conditions
- [ ] Contract lifecycle integration
- [ ] Repository layer

### Phase 2: Core Business Logic (P1)
- [ ] Warehouse selection algorithm
- [ ] Stock transfer between warehouses
- [ ] Multi-item reservation (transaction)
- [ ] Automated stock reordering

### Phase 3: Performance & Optimization (P2)
- [ ] Multi-level cache
- [ ] CQRS pattern
- [ ] Event batching
- [ ] Snapshotting

### Phase 4: Monitoring & Operations (P3)
- [ ] Health checks
- [ ] Metrics collection
- [ ] Alerting
- [ ] Performance profiling

---

## Related Documentation

- **[02-domain-models.md](02-domain-models.md)** - Domain model classes to test
- **[03-database-schema.md](03-database-schema.md)** - Repository layer test targets
- **[04-smart-contract-integration.md](04-smart-contract-integration.md)** - Integration test scenarios
- **[05-consensus-protocol.md](05-consensus-protocol.md)** - Consensus protocol testing
- **[08-performance-optimization.md](08-performance-optimization.md)** - Performance test scenarios

---

**Version:** 1.0.0
**Last Updated:** 2025-10-21
**Status:** Test Planning Complete
