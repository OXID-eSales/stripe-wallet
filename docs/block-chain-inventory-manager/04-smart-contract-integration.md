# Smart Contract Integration with Payment Component

**Version:** 1.0.0
**Date:** 2025-10-21
**Target Platform:** PHP 8.2+, PSR-12, SOLID, DDD, EDD
**Status:** Integration Specification
**Visual Diagram:** [puml/08-smart-contract-integration.puml](puml/08-smart-contract-integration.puml)

---

## Table of Contents

1. [Integration Overview](#integration-overview)
2. [Contract Condition Pattern](#contract-condition-pattern)
3. [Event-Driven Integration](#event-driven-integration)
4. [Implementation Guide](#implementation-guide)
5. [Configuration](#configuration)
6. [Testing Strategy](#testing-strategy)
7. [Troubleshooting](#troubleshooting)

---

## Integration Overview

The **inventory management system** integrates seamlessly with the **payment component** using the **smart-contract pattern**. Stock operations are automatically triggered by payment lifecycle events, ensuring:

✅ **Atomic Operations**: Payment authorization and stock reservation happen together
✅ **Automatic Rollback**: Failed payments automatically release reserved stock
✅ **No Manual Intervention**: Event-driven architecture handles all workflows
✅ **Complete Audit Trail**: All operations logged in immutable ledger

### Architecture Integration Points

```
┌────────────────────────────────────────────────────────────┐
│                    Payment Component                        │
│  ┌──────────────────────────────────────────────────────┐  │
│  │           PaymentContract (Aggregate)                 │  │
│  │  • OXSTATE: draft → pending → committed → fulfilled  │  │
│  │  • OXCONDITIONS: [payment_auth, stock_reserved]     │  │
│  └──────────────────────────────────────────────────────┘  │
│                         ▼ Emits Events                      │
│     PaymentAuthorized | PaymentFailed | ContractExpired    │
└────────────────────────────────────────────────────────────┘
                         ▼ Event Bus (PSR-14)
┌────────────────────────────────────────────────────────────┐
│              Inventory Management System                    │
│  ┌──────────────────────────────────────────────────────┐  │
│  │         Event Handlers (Subscribers)                  │  │
│  │  • ReserveStockHandler (PaymentAuthorizedEvent)     │  │
│  │  • ReleaseStockHandler (PaymentFailedEvent)         │  │
│  │  • CommitStockHandler (OrderCreatedEvent)           │  │
│  │  • ShipStockHandler (OrderShippedEvent)             │  │
│  └──────────────────────────────────────────────────────┘  │
│                         ▼ Domain Operations                 │
│     InventoryService → InventoryItem → Raft Consensus       │
└────────────────────────────────────────────────────────────┘
```

### Key Integration Components

| Component | Purpose | Location |
|-----------|---------|----------|
| **PaymentContract** | Payment lifecycle manager | Payment Component |
| **ContractCondition** | Condition that must be fulfilled | Payment Component |
| **ReserveStockHandler** | Reserves stock on payment auth | Inventory System |
| **ReleaseStockHandler** | Releases stock on payment failure | Inventory System |
| **CommitStockHandler** | Commits stock on order creation | Inventory System |
| **InventoryService** | Orchestrates stock operations | Inventory System |

---

## Contract Condition Pattern

### Adding Stock Reservation Condition

When a payment contract is created, the **TYPE_STOCK_RESERVED** condition is automatically added:

```php
<?php

declare(strict_types=1);

namespace Osc\Payment\Component\Handler;

use Osc\Payment\Component\Event\ContractCreatedEvent;
use Osc\Payment\Component\Entity\ContractCondition;
use Osc\Payment\Component\Repository\ContractRepositoryInterface;

/**
 * Add Stock Reservation Condition to Contract
 *
 * Listens to: ContractCreatedEvent
 * Priority: High (before payment authorization)
 */
final class AddStockConditionHandler
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository
    ) {}

    public function handle(ContractCreatedEvent $event): void
    {
        $contract = $event->getContract();

        // Add stock reservation condition for each item
        $basketSnapshot = $contract->getBasketSnapshot();

        foreach ($basketSnapshot->getItems() as $item) {
            $contract->addCondition(new ContractCondition(
                type: ContractCondition::TYPE_STOCK_RESERVED,
                data: [
                    'sku' => $item['articleId'],
                    'quantity' => $item['amount'],
                    'warehouse' => null,  // Will be determined by allocator
                ]
            ));
        }

        $this->contractRepository->save($contract);
    }
}
```

**Contract Condition Types:**
```php
// In ContractCondition entity
public const TYPE_PAYMENT_AUTHORIZED = 'payment_authorized';
public const TYPE_FRAUD_CHECK = 'fraud_check';
public const TYPE_STOCK_RESERVED = 'stock_reserved';  // ← Inventory condition
public const TYPE_COMPLIANCE_CHECK = 'compliance_check';
```

### Contract State Transitions

```
DRAFT
  ↓ addCondition(TYPE_STOCK_RESERVED)
PENDING
  ↓ Payment authorized
  ↓ Stock reserved (condition fulfilled)
  ↓ All conditions met
READY_TO_COMMIT
  ↓ Order created
COMMITTED
  ↓ Payment captured
  ↓ Stock shipped
FULFILLED
```

---

## Event-Driven Integration

### Event Flow Diagram

```
User clicks "Place Order"
    ↓
[Payment Component] Create Contract (state: DRAFT)
    ↓
ContractCreatedEvent → AddStockConditionHandler
    ↓ Add TYPE_STOCK_RESERVED condition
    ↓
[Payment Component] Transition to PENDING
    ↓
User authorizes payment (Stripe/PayPal)
    ↓
PaymentAuthorizedEvent → ReserveStockHandler
    ↓ InventoryService.reserveStock()
    ↓ Raft consensus reserves stock
    ↓ Emit StockReservedEvent
    ↓ Contract.fulfillCondition(TYPE_STOCK_RESERVED)
    ↓
[Payment Component] All conditions met → READY_TO_COMMIT
    ↓
[Payment Component] Create Order
    ↓
OrderCreatedEvent → CommitStockHandler
    ↓ InventoryService.commitStock()
    ↓ Stock: reserved → committed
    ↓
[Payment Component] Transition to COMMITTED
    ↓
Warehouse ships product
    ↓
OrderShippedEvent → ShipStockHandler
    ↓ InventoryService.shipStock()
    ↓ Stock: committed → shipped (removed from inventory)
    ↓
[Payment Component] Transition to FULFILLED
```

### Event Handlers

#### 1. ReserveStockHandler

**Triggered By:** `PaymentAuthorizedEvent`
**Purpose:** Reserve stock when payment is authorized

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Application\Handler;

use Osc\Payment\Component\Event\PaymentAuthorizedEvent;
use Osc\Inventory\Domain\Service\InventoryServiceInterface;
use Osc\Payment\Component\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Reserve Stock Handler
 *
 * Subscribes to: PaymentAuthorizedEvent
 * Priority: High (critical path)
 */
final class ReserveStockHandler
{
    public function __construct(
        private InventoryServiceInterface $inventoryService,
        private ContractRepositoryInterface $contractRepository,
        private LoggerInterface $logger
    ) {}

    public function handle(PaymentAuthorizedEvent $event): void
    {
        $contractId = $event->getContractId();
        $contract = $this->contractRepository->find($contractId);

        if (!$contract) {
            $this->logger->error('Contract not found for stock reservation', [
                'contract_id' => $contractId,
            ]);
            return;
        }

        try {
            // Reserve stock via Raft consensus
            $reservations = $this->inventoryService->reserveStock($contract);

            // Fulfill contract condition
            $contract->fulfillCondition(
                type: 'TYPE_STOCK_RESERVED',
                data: [
                    'reservations' => array_map(
                        fn($r) => [
                            'reservation_id' => $r->getId(),
                            'sku' => $r->getSku()->value(),
                            'quantity' => $r->getQuantity()->value(),
                            'warehouse_id' => $r->getWarehouse()->getId(),
                            'expires_at' => $r->getExpiresAt()->format(\DateTime::ATOM),
                        ],
                        $reservations
                    ),
                ]
            );

            $this->contractRepository->save($contract);

            $this->logger->info('Stock reserved successfully', [
                'contract_id' => $contractId,
                'reservation_count' => count($reservations),
            ]);

        } catch (InsufficientStockException $e) {
            // Stock unavailable - fail condition
            $contract->failCondition('TYPE_STOCK_RESERVED', $e->getMessage());
            $this->contractRepository->save($contract);

            $this->logger->warning('Insufficient stock for contract', [
                'contract_id' => $contractId,
                'error' => $e->getMessage(),
            ]);

            throw $e;  // Re-throw to trigger payment void
        }
    }
}
```

#### 2. ReleaseStockHandler

**Triggered By:** `PaymentFailedEvent`, `ContractExpiredEvent`, `ContractCancelledEvent`
**Purpose:** Return stock to available pool

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Application\Handler;

use Osc\Payment\Component\Event\PaymentFailedEvent;
use Osc\Payment\Component\Event\ContractExpiredEvent;
use Osc\Inventory\Domain\Service\InventoryServiceInterface;
use Osc\Payment\Component\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Release Stock Handler
 *
 * Subscribes to:
 * - PaymentFailedEvent
 * - ContractExpiredEvent
 * - ContractCancelledEvent
 *
 * Priority: High (prevent stock leakage)
 */
final class ReleaseStockHandler
{
    public function __construct(
        private InventoryServiceInterface $inventoryService,
        private ContractRepositoryInterface $contractRepository,
        private LoggerInterface $logger
    ) {}

    public function handle(
        PaymentFailedEvent|ContractExpiredEvent $event
    ): void {
        $contractId = $event->getContractId();
        $contract = $this->contractRepository->find($contractId);

        if (!$contract) {
            $this->logger->warning('Contract not found for stock release', [
                'contract_id' => $contractId,
            ]);
            return;
        }

        try {
            // Release all reservations for this contract
            $this->inventoryService->releaseStock($contract);

            $this->logger->info('Stock released successfully', [
                'contract_id' => $contractId,
                'reason' => get_class($event),
            ]);

        } catch (\Exception $e) {
            // Log but don't fail - idempotent operation
            $this->logger->error('Failed to release stock', [
                'contract_id' => $contractId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

#### 3. CommitStockHandler

**Triggered By:** `OrderCreatedEvent`
**Purpose:** Permanently allocate stock to order

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Application\Handler;

use Osc\Payment\Component\Event\OrderCreatedEvent;
use Osc\Inventory\Domain\Service\InventoryServiceInterface;
use Osc\Payment\Component\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Commit Stock Handler
 *
 * Subscribes to: OrderCreatedEvent
 * Priority: High (order fulfillment)
 */
final class CommitStockHandler
{
    public function __construct(
        private InventoryServiceInterface $inventoryService,
        private ContractRepositoryInterface $contractRepository,
        private LoggerInterface $logger
    ) {}

    public function handle(OrderCreatedEvent $event): void
    {
        $contractId = $event->getContractId();
        $orderId = $event->getOrderId();
        $contract = $this->contractRepository->find($contractId);

        if (!$contract) {
            $this->logger->error('Contract not found for stock commitment', [
                'contract_id' => $contractId,
                'order_id' => $orderId,
            ]);
            return;
        }

        try {
            // Commit reservations (reserved → committed)
            $this->inventoryService->commitStock($contract, $orderId);

            $this->logger->info('Stock committed to order', [
                'contract_id' => $contractId,
                'order_id' => $orderId,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to commit stock', [
                'contract_id' => $contractId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            throw $e;  // Critical error - order creation should fail
        }
    }
}
```

#### 4. ShipStockHandler

**Triggered By:** `OrderShippedEvent`
**Purpose:** Remove stock from warehouse inventory

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Application\Handler;

use Osc\Payment\Component\Event\OrderShippedEvent;
use Osc\Inventory\Domain\Service\InventoryServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Ship Stock Handler
 *
 * Subscribes to: OrderShippedEvent
 * Priority: Normal (post-fulfillment)
 */
final class ShipStockHandler
{
    public function __construct(
        private InventoryServiceInterface $inventoryService,
        private LoggerInterface $logger
    ) {}

    public function handle(OrderShippedEvent $event): void
    {
        $orderId = $event->getOrderId();

        try {
            // Mark stock as shipped (committed → removed)
            $this->inventoryService->shipStock($orderId);

            $this->logger->info('Stock marked as shipped', [
                'order_id' => $orderId,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to mark stock as shipped', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

---

## Implementation Guide

### Step 1: Install Dependencies

```bash
# Inventory system depends on payment component
composer require osc/payment-component:^3.0
composer require osc/inventory-management:^1.0

# Event dispatcher (PSR-14)
composer require symfony/event-dispatcher

# Logging (PSR-3)
composer require monolog/monolog
```

### Step 2: Register Event Subscribers

**Symfony Configuration** (`config/services.yaml`):

```yaml
services:
  # Inventory Event Handlers
  Osc\Inventory\Application\Handler\ReserveStockHandler:
    arguments:
      $inventoryService: '@Osc\Inventory\Domain\Service\InventoryServiceInterface'
      $contractRepository: '@Osc\Payment\Component\Repository\ContractRepositoryInterface'
      $logger: '@monolog.logger.inventory'
    tags:
      - { name: kernel.event_listener, event: payment.authorized, method: handle, priority: 100 }

  Osc\Inventory\Application\Handler\ReleaseStockHandler:
    arguments:
      $inventoryService: '@Osc\Inventory\Domain\Service\InventoryServiceInterface'
      $contractRepository: '@Osc\Payment\Component\Repository\ContractRepositoryInterface'
      $logger: '@monolog.logger.inventory'
    tags:
      - { name: kernel.event_listener, event: payment.failed, method: handle, priority: 100 }
      - { name: kernel.event_listener, event: contract.expired, method: handle, priority: 100 }
      - { name: kernel.event_listener, event: contract.cancelled, method: handle, priority: 100 }

  Osc\Inventory\Application\Handler\CommitStockHandler:
    arguments:
      $inventoryService: '@Osc\Inventory\Domain\Service\InventoryServiceInterface'
      $contractRepository: '@Osc\Payment\Component\Repository\ContractRepositoryInterface'
      $logger: '@monolog.logger.inventory'
    tags:
      - { name: kernel.event_listener, event: order.created, method: handle, priority: 100 }

  Osc\Inventory\Application\Handler\ShipStockHandler:
    arguments:
      $inventoryService: '@Osc\Inventory\Domain\Service\InventoryServiceInterface'
      $logger: '@monolog.logger.inventory'
    tags:
      - { name: kernel.event_listener, event: order.shipped, method: handle, priority: 50 }

  # Payment Component Handler (adds stock condition)
  Osc\Payment\Component\Handler\AddStockConditionHandler:
    arguments:
      $contractRepository: '@Osc\Payment\Component\Repository\ContractRepositoryInterface'
    tags:
      - { name: kernel.event_listener, event: contract.created, method: handle, priority: 200 }
```

### Step 3: Configure Reservation Timeout

**Configuration** (`config/packages/inventory.yaml`):

```yaml
inventory:
  # Stock reservation timeout (minutes)
  reservation_timeout: 5

  # Warehouse allocation strategy
  warehouse_allocator:
    strategy: 'distance_optimized'  # or 'cost_optimized', 'load_balanced'
    max_distance_km: 5000

  # Raft consensus
  consensus:
    enabled: true
    cluster_nodes:
      - 'raft-node-1:2379'
      - 'raft-node-2:2379'
      - 'raft-node-3:2379'

  # Event store
  event_store:
    type: 'kafka'  # or 'eventstoredb'
    brokers:
      - 'kafka-1:9092'
      - 'kafka-2:9092'
      - 'kafka-3:9092'
    topic: 'inventory-events'

  # Cache
  cache:
    driver: 'redis'
    ttl: 3600  # 1 hour
    prefix: 'inventory:'
```

### Step 4: Database Migration

```sql
-- Run inventory system migrations
-- See 03-database-schema.md for complete schema

-- Key tables:
-- osc_inventory_item
-- osc_inventory_stock_level
-- osc_inventory_warehouse
-- osc_inventory_reservation
-- osc_inventory_ledger
-- osc_inventory_ledger_snapshot

-- Add contract reference to payment tables
ALTER TABLE osc_payment_contract
    ADD COLUMN OXSTOCKRESERVED TINYINT(1) DEFAULT 0
        COMMENT 'Stock reservation status';
```

### Step 5: Deploy Raft Cluster

```bash
# Deploy etcd cluster (Raft implementation)
docker-compose up -d etcd-1 etcd-2 etcd-3

# Verify cluster health
docker exec etcd-1 etcdctl endpoint health

# Deploy inventory service
docker-compose up -d inventory-service
```

---

## Configuration

### Environment Variables

```bash
# Inventory System
INVENTORY_RESERVATION_TIMEOUT=300  # 5 minutes in seconds
INVENTORY_WAREHOUSE_STRATEGY=distance_optimized
INVENTORY_ENABLE_CONSENSUS=true

# Raft Consensus
RAFT_CLUSTER_NODES=raft-node-1:2379,raft-node-2:2379,raft-node-3:2379
RAFT_ELECTION_TIMEOUT=300  # milliseconds
RAFT_HEARTBEAT_INTERVAL=100  # milliseconds

# Event Store
EVENT_STORE_TYPE=kafka
EVENT_STORE_BROKERS=kafka-1:9092,kafka-2:9092,kafka-3:9092
EVENT_STORE_TOPIC=inventory-events

# Cache
INVENTORY_CACHE_DRIVER=redis
INVENTORY_CACHE_TTL=3600
REDIS_HOST=redis-cluster
REDIS_PORT=6379

# Monitoring
PROMETHEUS_ENABLED=true
PROMETHEUS_PORT=9090
GRAFANA_ENABLED=true
```

### Feature Flags

```php
<?php

return [
    'inventory' => [
        'enable_consensus' => env('INVENTORY_ENABLE_CONSENSUS', true),
        'enable_multi_warehouse' => env('INVENTORY_MULTI_WAREHOUSE', true),
        'enable_stock_transfer' => env('INVENTORY_STOCK_TRANSFER', false),
        'enable_event_sourcing' => env('INVENTORY_EVENT_SOURCING', true),
        'enable_cqrs' => env('INVENTORY_CQRS', true),
    ],
];
```

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Tests\Unit\Handler;

use PHPUnit\Framework\TestCase;
use Osc\Inventory\Application\Handler\ReserveStockHandler;
use Osc\Payment\Component\Event\PaymentAuthorizedEvent;

class ReserveStockHandlerTest extends TestCase
{
    public function testReserveStock_Success(): void
    {
        // Arrange
        $inventoryService = $this->createMock(InventoryServiceInterface::class);
        $contractRepo = $this->createMock(ContractRepositoryInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $handler = new ReserveStockHandler($inventoryService, $contractRepo, $logger);
        $event = new PaymentAuthorizedEvent('contract_123');

        // Act
        $handler->handle($event);

        // Assert
        $this->assertTrue($contract->isConditionFulfilled('TYPE_STOCK_RESERVED'));
    }

    public function testReserveStock_InsufficientStock_FailsCondition(): void
    {
        // Test insufficient stock scenario
        $this->expectException(InsufficientStockException::class);
        // ...
    }
}
```

### Integration Tests

```php
<?php

namespace Tests\Integration\SmartContract;

use Tests\IntegrationTestCase;

class StockReservationIntegrationTest extends IntegrationTestCase
{
    public function testPaymentAuthorized_StockReserved_ConditionFulfilled(): void
    {
        // Arrange: Create contract with basket
        $contract = $this->createContract([
            'items' => [
                ['sku' => 'IPHONE-15', 'quantity' => 1],
            ],
        ]);

        // Act: Authorize payment
        $this->dispatchEvent(new PaymentAuthorizedEvent($contract->getId()));

        // Assert: Stock reserved and condition fulfilled
        $contract = $this->contractRepository->find($contract->getId());
        $this->assertTrue($contract->isConditionFulfilled('TYPE_STOCK_RESERVED'));

        // Assert: Stock level decreased
        $stockLevel = $this->getStockLevel('IPHONE-15', 'warehouse-ny');
        $this->assertEquals(99, $stockLevel->getAvailable());
        $this->assertEquals(1, $stockLevel->getReserved());
    }
}
```

### E2E Tests

```php
<?php

namespace Tests\E2E;

use Tests\E2ETestCase;

class CompleteCheckoutFlowTest extends E2ETestCase
{
    public function testCompleteCheckout_WithStockReservation(): void
    {
        // 1. User adds item to basket
        $this->addToBasket('IPHONE-15', 1);

        // 2. User proceeds to checkout
        $this->proceedToCheckout();

        // 3. Payment authorization (Stripe test mode)
        $this->authorizePayment('pm_card_visa');

        // 4. Verify stock reserved
        $this->assertStockReserved('IPHONE-15', 1);

        // 5. Order created
        $this->assertOrderCreated();

        // 6. Stock committed
        $this->assertStockCommitted('IPHONE-15', 1);

        // 7. Order shipped
        $this->shipOrder();

        // 8. Stock removed from inventory
        $this->assertStockShipped('IPHONE-15', 1);
    }
}
```

---

## Troubleshooting

### Common Issues

#### Issue 1: Stock Not Reserved After Payment Authorization

**Symptoms:**
- Payment authorized successfully
- Contract condition `TYPE_STOCK_RESERVED` remains pending
- No stock reservation created

**Diagnosis:**
```bash
# Check event dispatcher
tail -f var/log/payment.log | grep PaymentAuthorizedEvent

# Check inventory handler logs
tail -f var/log/inventory.log | grep ReserveStockHandler

# Check Raft cluster health
docker exec etcd-1 etcdctl endpoint health
```

**Solutions:**
1. Verify event subscriber registration in `services.yaml`
2. Check Raft cluster connectivity
3. Verify stock availability in warehouse
4. Check for exceptions in logs

#### Issue 2: Stock Not Released After Payment Failure

**Symptoms:**
- Payment failed or contract expired
- Stock remains reserved (not returned to available pool)
- Memory leak in reserved stock

**Diagnosis:**
```bash
# Check release handler execution
tail -f var/log/inventory.log | grep ReleaseStockHandler

# Check reservation expiry
mysql> SELECT * FROM osc_inventory_reservation
       WHERE OXSTATE = 'active' AND OXEXPIRESAT < NOW();
```

**Solutions:**
1. Run contract expiry scheduler: `php bin/console inventory:expire-reservations`
2. Check `ReleaseStockHandler` is subscribed to all relevant events
3. Verify idempotency - may already be released

#### Issue 3: Raft Consensus Timeout

**Symptoms:**
- Stock reservation takes > 2 seconds
- Timeouts in logs
- "Raft leader unreachable" errors

**Diagnosis:**
```bash
# Check Raft cluster status
etcdctl member list

# Check network latency
ping raft-node-1
ping raft-node-2
ping raft-node-3

# Check Raft logs
docker logs raft-node-1 | grep -i error
```

**Solutions:**
1. Increase Raft timeout: `RAFT_ELECTION_TIMEOUT=500`
2. Check network connectivity between nodes
3. Verify sufficient resources (CPU, memory)
4. Restart unhealthy nodes

---

## Performance Optimization

### Caching Strategy

```php
<?php

// L1 Cache: Local memory (APCu)
$stockLevel = apcu_fetch("stock:{$sku}:{$warehouseId}");

if (!$stockLevel) {
    // L2 Cache: Redis
    $stockLevel = $redis->get("inventory:stock:{$sku}:{$warehouseId}");

    if (!$stockLevel) {
        // L3: Event store projection
        $stockLevel = $this->eventStore->getProjection($sku, $warehouseId);

        // Warm caches
        $redis->setex("inventory:stock:{$sku}:{$warehouseId}", 3600, $stockLevel);
    }

    apcu_store("stock:{$sku}:{$warehouseId}", $stockLevel, 300);
}
```

### Async Processing

```php
<?php

// Use message queue for non-critical operations
$this->messageBus->dispatch(new ShipStockMessage($orderId), [
    new DelayStamp(5000),  // 5 second delay
]);
```

### Batch Operations

```php
<?php

// Reserve multiple items in single Raft operation
$this->inventoryService->reserveStockBatch($contract);
```

---

## Related Documentation

- **[02-domain-models.md](02-domain-models.md)** - Domain model implementation
- **[03-database-schema.md](03-database-schema.md)** - Database design
- **[09-tdd-strategy.md](09-tdd-strategy.md)** - Testing approach
- **[../payment-component/01-02-architecture-smart-contracts.md](../payment-component/01-02-architecture-smart-contracts.md)** - Payment contract pattern

---

**Version:** 1.0.0
**Last Updated:** 2025-10-21
**Status:** ✅ Integration Specification Complete
