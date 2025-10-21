# Domain Models & Class Structure

**Version:** 1.0.0
**Date:** 2025-10-21
**Target Platform:** PHP 8.2+, PSR-12, SOLID, DDD
**Status:** Architecture Specification
**Visual Diagram:** [puml/02-class-diagram.puml](puml/02-class-diagram.puml)

---

## Table of Contents

1. [Domain-Driven Design Overview](#domain-driven-design-overview)
2. [Aggregate Roots](#aggregate-roots)
3. [Entities](#entities)
4. [Value Objects](#value-objects)
5. [Domain Services](#domain-services)
6. [Domain Events](#domain-events)
7. [Repositories](#repositories)
8. [Factory Classes](#factory-classes)

---

## Domain-Driven Design Overview

### Bounded Context

**Inventory Management Context** focuses on:
- Stock tracking across multiple warehouses
- Reservation lifecycle (reserve → commit → ship → release)
- Event-sourced audit trail
- Consensus-based allocation

### Ubiquitous Language

| Term | Definition | Usage |
|------|------------|-------|
| **SKU** | Stock Keeping Unit - unique product identifier | "Reserve 5 units of SKU IPHONE-15" |
| **Reservation** | Temporary hold on stock for a contract | "Stock reservation expires in 5 minutes" |
| **Allocation** | Permanent assignment of stock to order | "Stock allocated to order #12345" |
| **Ledger** | Append-only log of stock events | "Append STOCK_RESERVED to ledger" |
| **Consensus** | Distributed agreement on stock allocation | "Raft leader serializes reservations" |
| **Warehouse** | Physical location storing inventory | "NY warehouse has 100 units available" |

---

## Aggregate Roots

### 1. InventoryItem (Aggregate Root)

**Responsibility:** Represents a product's stock across all warehouses.

**Namespace:** `Osc\Inventory\Domain\Model\InventoryItem`

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\Model;

use Osc\Inventory\Domain\ValueObject\SKU;
use Osc\Inventory\Domain\ValueObject\Quantity;
use Osc\Inventory\Domain\Event\StockReservedEvent;

/**
 * Inventory Item - Aggregate Root
 *
 * Manages stock levels for a single SKU across all warehouses.
 * Enforces business rules for reservations and allocations.
 */
final class InventoryItem
{
    private SKU $sku;
    private string $name;
    private string $description;

    /** @var StockLevel[] Indexed by warehouse ID */
    private array $stockLevels = [];

    /** @var StockReservation[] Active reservations */
    private array $reservations = [];

    private \DateTime $createdAt;
    private \DateTime $updatedAt;

    /** @var DomainEvent[] */
    private array $recordedEvents = [];

    public function __construct(
        SKU $sku,
        string $name,
        string $description
    ) {
        $this->sku = $sku;
        $this->name = $name;
        $this->description = $description;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    /**
     * Reserve stock for a contract
     *
     * Business Rules:
     * - Stock must be available (available >= quantity)
     * - Warehouse must be operational
     * - No duplicate reservations for same contract
     *
     * @throws InsufficientStockException
     * @throws DuplicateReservationException
     */
    public function reserve(
        Quantity $quantity,
        Warehouse $warehouse,
        string $contractId,
        \DateTime $expiresAt
    ): StockReservation {
        // Check stock availability
        $stockLevel = $this->getStockLevel($warehouse->getId());
        if (!$stockLevel->canReserve($quantity)) {
            throw new InsufficientStockException(
                "Cannot reserve {$quantity->value()} units of {$this->sku->value()} from {$warehouse->getName()}"
            );
        }

        // Check for duplicate reservation
        if ($this->hasReservation($contractId)) {
            throw new DuplicateReservationException(
                "Reservation already exists for contract {$contractId}"
            );
        }

        // Create reservation
        $reservation = new StockReservation(
            id: $this->generateReservationId(),
            sku: $this->sku,
            quantity: $quantity,
            warehouse: $warehouse,
            contractId: $contractId,
            expiresAt: $expiresAt
        );

        // Update stock level
        $stockLevel->reserve($quantity);

        // Track reservation
        $this->reservations[] = $reservation;
        $this->updatedAt = new \DateTime();

        // Record domain event
        $this->recordEvent(new StockReservedEvent(
            sku: $this->sku,
            quantity: $quantity,
            warehouseId: $warehouse->getId(),
            contractId: $contractId,
            reservationId: $reservation->getId()
        ));

        return $reservation;
    }

    /**
     * Release a reservation (payment failed or contract expired)
     */
    public function releaseReservation(string $reservationId): void
    {
        $reservation = $this->findReservation($reservationId);
        if (!$reservation) {
            throw new ReservationNotFoundException("Reservation {$reservationId} not found");
        }

        // Update stock level
        $stockLevel = $this->getStockLevel($reservation->getWarehouse()->getId());
        $stockLevel->release($reservation->getQuantity());

        // Remove reservation
        $this->reservations = array_filter(
            $this->reservations,
            fn($r) => $r->getId() !== $reservationId
        );
        $this->updatedAt = new \DateTime();

        // Record event
        $this->recordEvent(new StockReleasedEvent(
            sku: $this->sku,
            quantity: $reservation->getQuantity(),
            warehouseId: $reservation->getWarehouse()->getId(),
            contractId: $reservation->getContractId(),
            reservationId: $reservationId
        ));
    }

    /**
     * Commit reservation (order created, stock permanently allocated)
     */
    public function commitReservation(string $reservationId, string $orderId): void
    {
        $reservation = $this->findReservation($reservationId);
        if (!$reservation) {
            throw new ReservationNotFoundException("Reservation {$reservationId} not found");
        }

        // Update stock level
        $stockLevel = $this->getStockLevel($reservation->getWarehouse()->getId());
        $stockLevel->commit($reservation->getQuantity());

        // Mark reservation as committed
        $reservation->commit($orderId);
        $this->updatedAt = new \DateTime();

        // Record event
        $this->recordEvent(new StockCommittedEvent(
            sku: $this->sku,
            quantity: $reservation->getQuantity(),
            warehouseId: $reservation->getWarehouse()->getId(),
            contractId: $reservation->getContractId(),
            orderId: $orderId,
            reservationId: $reservationId
        ));
    }

    /**
     * Add stock from supplier delivery
     */
    public function receiveStock(Quantity $quantity, Warehouse $warehouse, string $supplierRef): void
    {
        $stockLevel = $this->getStockLevel($warehouse->getId());
        $stockLevel->receive($quantity);
        $this->updatedAt = new \DateTime();

        $this->recordEvent(new StockReceivedEvent(
            sku: $this->sku,
            quantity: $quantity,
            warehouseId: $warehouse->getId(),
            supplierRef: $supplierRef
        ));
    }

    /**
     * Get total available stock across all warehouses
     */
    public function getTotalAvailable(): int
    {
        return array_sum(
            array_map(fn($level) => $level->getAvailable(), $this->stockLevels)
        );
    }

    /**
     * Get total reserved stock across all warehouses
     */
    public function getTotalReserved(): int
    {
        return array_sum(
            array_map(fn($level) => $level->getReserved(), $this->stockLevels)
        );
    }

    /**
     * Add stock level for a warehouse
     */
    public function addWarehouse(Warehouse $warehouse, Quantity $initialStock): void
    {
        if (isset($this->stockLevels[$warehouse->getId()])) {
            throw new \LogicException("Warehouse already tracked");
        }

        $this->stockLevels[$warehouse->getId()] = new StockLevel(
            warehouse: $warehouse,
            available: $initialStock,
            reserved: new Quantity(0),
            committed: new Quantity(0)
        );
        $this->updatedAt = new \DateTime();
    }

    // Getters
    public function getSku(): SKU { return $this->sku; }
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getStockLevels(): array { return $this->stockLevels; }
    public function getReservations(): array { return $this->reservations; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getUpdatedAt(): \DateTime { return $this->updatedAt; }

    // Event Sourcing
    public function getRecordedEvents(): array { return $this->recordedEvents; }
    public function clearRecordedEvents(): void { $this->recordedEvents = []; }

    // Private helpers
    private function getStockLevel(string $warehouseId): StockLevel
    {
        if (!isset($this->stockLevels[$warehouseId])) {
            throw new \InvalidArgumentException("No stock level for warehouse {$warehouseId}");
        }
        return $this->stockLevels[$warehouseId];
    }

    private function hasReservation(string $contractId): bool
    {
        return count(array_filter(
            $this->reservations,
            fn($r) => $r->getContractId() === $contractId
        )) > 0;
    }

    private function findReservation(string $reservationId): ?StockReservation
    {
        foreach ($this->reservations as $reservation) {
            if ($reservation->getId() === $reservationId) {
                return $reservation;
            }
        }
        return null;
    }

    private function generateReservationId(): string
    {
        return 'res_' . bin2hex(random_bytes(16));
    }

    private function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
```

---

### 2. Warehouse (Aggregate Root)

**Responsibility:** Represents a physical warehouse location with capacity and routing information.

**Namespace:** `Osc\Inventory\Domain\Model\Warehouse`

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\Model;

use Osc\Inventory\Domain\ValueObject\Address;
use Osc\Inventory\Domain\ValueObject\Quantity;

/**
 * Warehouse - Aggregate Root
 *
 * Represents a physical location storing inventory.
 * Tracks capacity, load, and operational status.
 */
final class Warehouse
{
    private string $id;
    private string $name;
    private Address $address;
    private int $capacity;  // Maximum items this warehouse can hold
    private float $currentLoad;  // 0.0 to 1.0 (0% to 100%)
    private bool $operational;
    private array $regions;  // Geographic regions served
    private \DateTime $createdAt;
    private \DateTime $updatedAt;

    public function __construct(
        string $id,
        string $name,
        Address $address,
        int $capacity,
        float $currentLoad = 0.0,
        bool $operational = true,
        array $regions = []
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->address = $address;
        $this->capacity = $capacity;
        $this->setCurrentLoad($currentLoad);
        $this->operational = $operational;
        $this->regions = $regions;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    /**
     * Check if warehouse can handle additional items
     */
    public function canAccept(Quantity $quantity): bool
    {
        if (!$this->operational) {
            return false;
        }

        $requiredCapacity = $quantity->value();
        $availableCapacity = $this->capacity * (1 - $this->currentLoad);

        return $availableCapacity >= $requiredCapacity;
    }

    /**
     * Mark warehouse as operational
     */
    public function markOperational(): void
    {
        $this->operational = true;
        $this->updatedAt = new \DateTime();
    }

    /**
     * Mark warehouse as offline (maintenance, emergency)
     */
    public function markOffline(): void
    {
        $this->operational = false;
        $this->updatedAt = new \DateTime();
    }

    /**
     * Update current load (0.0 to 1.0)
     */
    public function setCurrentLoad(float $load): void
    {
        if ($load < 0.0 || $load > 1.0) {
            throw new \InvalidArgumentException("Load must be between 0.0 and 1.0");
        }
        $this->currentLoad = $load;
        $this->updatedAt = new \DateTime();
    }

    /**
     * Check if warehouse serves a region
     */
    public function servesRegion(string $region): bool
    {
        return in_array($region, $this->regions);
    }

    /**
     * Calculate shipping cost to address (simplified)
     */
    public function calculateShippingCost(Address $destination): float
    {
        $distance = $this->address->distanceTo($destination);

        // Simplified cost calculation: $0.10 per km
        return round($distance * 0.10, 2);
    }

    // Getters
    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getAddress(): Address { return $this->address; }
    public function getCapacity(): int { return $this->capacity; }
    public function getCurrentLoad(): float { return $this->currentLoad; }
    public function isOperational(): bool { return $this->operational; }
    public function getRegions(): array { return $this->regions; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getUpdatedAt(): \DateTime { return $this->updatedAt; }
}
```

---

## Entities

### 1. StockReservation (Entity)

**Responsibility:** Represents a temporary hold on stock for a payment contract.

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\Entity;

use Osc\Inventory\Domain\ValueObject\SKU;
use Osc\Inventory\Domain\ValueObject\Quantity;
use Osc\Inventory\Domain\Model\Warehouse;

/**
 * Stock Reservation - Entity
 *
 * Temporary hold on stock linked to a payment contract.
 * Expires after timeout if not committed.
 */
final class StockReservation
{
    // States
    const STATE_ACTIVE = 'active';
    const STATE_COMMITTED = 'committed';
    const STATE_RELEASED = 'released';
    const STATE_EXPIRED = 'expired';

    private string $id;
    private SKU $sku;
    private Quantity $quantity;
    private Warehouse $warehouse;
    private string $contractId;
    private ?string $orderId = null;
    private string $state;
    private \DateTime $createdAt;
    private \DateTime $expiresAt;
    private ?\DateTime $committedAt = null;
    private ?\DateTime $releasedAt = null;

    public function __construct(
        string $id,
        SKU $sku,
        Quantity $quantity,
        Warehouse $warehouse,
        string $contractId,
        \DateTime $expiresAt
    ) {
        $this->id = $id;
        $this->sku = $sku;
        $this->quantity = $quantity;
        $this->warehouse = $warehouse;
        $this->contractId = $contractId;
        $this->expiresAt = $expiresAt;
        $this->state = self::STATE_ACTIVE;
        $this->createdAt = new \DateTime();
    }

    /**
     * Commit reservation (order created)
     */
    public function commit(string $orderId): void
    {
        if ($this->state !== self::STATE_ACTIVE) {
            throw new \LogicException("Cannot commit reservation in state {$this->state}");
        }

        $this->orderId = $orderId;
        $this->state = self::STATE_COMMITTED;
        $this->committedAt = new \DateTime();
    }

    /**
     * Release reservation (payment failed)
     */
    public function release(): void
    {
        if ($this->state !== self::STATE_ACTIVE) {
            throw new \LogicException("Cannot release reservation in state {$this->state}");
        }

        $this->state = self::STATE_RELEASED;
        $this->releasedAt = new \DateTime();
    }

    /**
     * Mark as expired (timeout)
     */
    public function expire(): void
    {
        if ($this->state !== self::STATE_ACTIVE) {
            return;  // Already committed or released
        }

        $this->state = self::STATE_EXPIRED;
        $this->releasedAt = new \DateTime();
    }

    /**
     * Check if reservation has expired
     */
    public function isExpired(): bool
    {
        return new \DateTime() > $this->expiresAt;
    }

    /**
     * Check if reservation is active
     */
    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE && !$this->isExpired();
    }

    // Getters
    public function getId(): string { return $this->id; }
    public function getSku(): SKU { return $this->sku; }
    public function getQuantity(): Quantity { return $this->quantity; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function getContractId(): string { return $this->contractId; }
    public function getOrderId(): ?string { return $this->orderId; }
    public function getState(): string { return $this->state; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getExpiresAt(): \DateTime { return $this->expiresAt; }
    public function getCommittedAt(): ?\DateTime { return $this->committedAt; }
    public function getReleasedAt(): ?\DateTime { return $this->releasedAt; }
}
```

### 2. StockLevel (Entity)

**Responsibility:** Tracks stock quantities for a SKU at a specific warehouse.

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\Entity;

use Osc\Inventory\Domain\ValueObject\Quantity;
use Osc\Inventory\Domain\Model\Warehouse;

/**
 * Stock Level - Entity
 *
 * Tracks available, reserved, and committed stock for a SKU at a warehouse.
 */
final class StockLevel
{
    private Warehouse $warehouse;
    private Quantity $available;  // Available for reservation
    private Quantity $reserved;   // Reserved for contracts
    private Quantity $committed;  // Committed to orders

    public function __construct(
        Warehouse $warehouse,
        Quantity $available,
        Quantity $reserved,
        Quantity $committed
    ) {
        $this->warehouse = $warehouse;
        $this->available = $available;
        $this->reserved = $reserved;
        $this->committed = $committed;
    }

    /**
     * Check if enough stock available for reservation
     */
    public function canReserve(Quantity $quantity): bool
    {
        return $this->available->value() >= $quantity->value();
    }

    /**
     * Reserve stock (available → reserved)
     */
    public function reserve(Quantity $quantity): void
    {
        if (!$this->canReserve($quantity)) {
            throw new InsufficientStockException(
                "Cannot reserve {$quantity->value()} units, only {$this->available->value()} available"
            );
        }

        $this->available = new Quantity($this->available->value() - $quantity->value());
        $this->reserved = new Quantity($this->reserved->value() + $quantity->value());
    }

    /**
     * Release reservation (reserved → available)
     */
    public function release(Quantity $quantity): void
    {
        if ($this->reserved->value() < $quantity->value()) {
            throw new \LogicException("Cannot release more than reserved");
        }

        $this->reserved = new Quantity($this->reserved->value() - $quantity->value());
        $this->available = new Quantity($this->available->value() + $quantity->value());
    }

    /**
     * Commit reservation (reserved → committed)
     */
    public function commit(Quantity $quantity): void
    {
        if ($this->reserved->value() < $quantity->value()) {
            throw new \LogicException("Cannot commit more than reserved");
        }

        $this->reserved = new Quantity($this->reserved->value() - $quantity->value());
        $this->committed = new Quantity($this->committed->value() + $quantity->value());
    }

    /**
     * Receive new stock (supplier delivery)
     */
    public function receive(Quantity $quantity): void
    {
        $this->available = new Quantity($this->available->value() + $quantity->value());
    }

    /**
     * Ship committed stock (committed → 0)
     */
    public function ship(Quantity $quantity): void
    {
        if ($this->committed->value() < $quantity->value()) {
            throw new \LogicException("Cannot ship more than committed");
        }

        $this->committed = new Quantity($this->committed->value() - $quantity->value());
    }

    /**
     * Get total stock (available + reserved + committed)
     */
    public function getTotal(): int
    {
        return $this->available->value() + $this->reserved->value() + $this->committed->value();
    }

    // Getters
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function getAvailable(): int { return $this->available->value(); }
    public function getReserved(): int { return $this->reserved->value(); }
    public function getCommitted(): int { return $this->committed->value(); }
}
```

---

## Value Objects

### 1. SKU (Value Object)

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\ValueObject;

/**
 * SKU - Value Object
 *
 * Stock Keeping Unit - unique product identifier.
 * Immutable, validated on construction.
 */
final class SKU
{
    private string $value;

    public function __construct(string $value)
    {
        $this->validate($value);
        $this->value = strtoupper($value);
    }

    private function validate(string $value): void
    {
        if (empty($value)) {
            throw new \InvalidArgumentException("SKU cannot be empty");
        }

        if (strlen($value) > 50) {
            throw new \InvalidArgumentException("SKU too long (max 50 characters)");
        }

        if (!preg_match('/^[A-Z0-9\-_]+$/i', $value)) {
            throw new \InvalidArgumentException("SKU contains invalid characters");
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(SKU $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

### 2. Quantity (Value Object)

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\ValueObject;

/**
 * Quantity - Value Object
 *
 * Represents a quantity of items. Always non-negative.
 * Immutable.
 */
final class Quantity
{
    private int $value;

    public function __construct(int $value)
    {
        if ($value < 0) {
            throw new \InvalidArgumentException("Quantity cannot be negative");
        }
        $this->value = $value;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function add(Quantity $other): Quantity
    {
        return new self($this->value + $other->value);
    }

    public function subtract(Quantity $other): Quantity
    {
        return new self($this->value - $other->value);
    }

    public function equals(Quantity $other): bool
    {
        return $this->value === $other->value;
    }

    public function greaterThan(Quantity $other): bool
    {
        return $this->value > $other->value;
    }

    public function lessThan(Quantity $other): bool
    {
        return $this->value < $other->value;
    }

    public function __toString(): string
    {
        return (string)$this->value;
    }
}
```

### 3. Address (Value Object)

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\ValueObject;

/**
 * Address - Value Object
 *
 * Represents a physical address.
 * Used for warehouse locations and shipping destinations.
 */
final class Address
{
    private string $street;
    private string $city;
    private string $state;
    private string $zip;
    private string $country;
    private ?float $latitude;
    private ?float $longitude;

    public function __construct(
        string $street,
        string $city,
        string $state,
        string $zip,
        string $country,
        ?float $latitude = null,
        ?float $longitude = null
    ) {
        $this->street = $street;
        $this->city = $city;
        $this->state = $state;
        $this->zip = $zip;
        $this->country = $country;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }

    /**
     * Calculate distance to another address (in kilometers)
     * Uses Haversine formula
     */
    public function distanceTo(Address $other): float
    {
        if (!$this->hasCoordinates() || !$other->hasCoordinates()) {
            throw new \LogicException("Both addresses must have coordinates");
        }

        $earthRadius = 6371;  // km

        $latFrom = deg2rad($this->latitude);
        $lonFrom = deg2rad($this->longitude);
        $latTo = deg2rad($other->latitude);
        $lonTo = deg2rad($other->longitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return round($angle * $earthRadius, 2);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    // Getters
    public function getStreet(): string { return $this->street; }
    public function getCity(): string { return $this->city; }
    public function getState(): string { return $this->state; }
    public function getZip(): string { return $this->zip; }
    public function getCountry(): string { return $this->country; }
    public function getLatitude(): ?float { return $this->latitude; }
    public function getLongitude(): ?float { return $this->longitude; }

    public function toString(): string
    {
        return "{$this->street}, {$this->city}, {$this->state} {$this->zip}, {$this->country}";
    }
}
```

---

## Domain Services

Domain services contain business logic that doesn't naturally fit in a single aggregate.

### 1. InventoryService

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\Service;

use Osc\Inventory\Domain\Model\InventoryItem;
use Osc\Inventory\Domain\Model\Warehouse;
use Osc\Inventory\Domain\ValueObject\Quantity;
use Osc\Payment\Component\Model\PaymentContract;

/**
 * Inventory Service - Domain Service
 *
 * Orchestrates inventory operations across aggregates.
 */
interface InventoryServiceInterface
{
    /**
     * Reserve stock for a payment contract
     *
     * @param PaymentContract $contract
     * @return StockReservation[]
     * @throws InsufficientStockException
     */
    public function reserveStock(PaymentContract $contract): array;

    /**
     * Release stock reservations for a contract
     *
     * @param PaymentContract $contract
     */
    public function releaseStock(PaymentContract $contract): void;

    /**
     * Commit stock reservations (order created)
     *
     * @param PaymentContract $contract
     * @param string $orderId
     */
    public function commitStock(PaymentContract $contract, string $orderId): void;

    /**
     * Get available stock for SKU across all warehouses
     *
     * @param string $sku
     * @return int Total available units
     */
    public function getAvailableStock(string $sku): int;

    /**
     * Check if contract items can be fulfilled
     *
     * @param PaymentContract $contract
     * @return bool
     */
    public function canFulfillContract(PaymentContract $contract): bool;
}
```

### 2. WarehouseAllocator

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\Service;

use Osc\Inventory\Domain\Model\Warehouse;
use Osc\Inventory\Domain\ValueObject\SKU;
use Osc\Inventory\Domain\ValueObject\Quantity;
use Osc\Inventory\Domain\ValueObject\Address;

/**
 * Warehouse Allocator - Domain Service
 *
 * Selects optimal warehouse for fulfillment based on:
 * - Stock availability
 * - Distance to customer
 * - Warehouse load
 * - Shipping cost
 */
interface WarehouseAllocatorInterface
{
    /**
     * Find optimal warehouse to fulfill order
     *
     * @param SKU $sku
     * @param Quantity $quantity
     * @param Address $customerAddress
     * @return Warehouse|null
     */
    public function findOptimal(SKU $sku, Quantity $quantity, Address $customerAddress): ?Warehouse;

    /**
     * Calculate allocation score for warehouse
     * Lower score = better choice
     *
     * @param Warehouse $warehouse
     * @param Address $customerAddress
     * @return float
     */
    public function calculateScore(Warehouse $warehouse, Address $customerAddress): float;

    /**
     * Check if order should be split across warehouses
     *
     * @param array $items [{sku, quantity, warehouse}]
     * @return bool
     */
    public function shouldSplitShipment(array $items): bool;
}
```

---

## Domain Events

All domain events follow a consistent structure and are immutable.

### Event Base Class

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\Event;

/**
 * Domain Event - Base Class
 *
 * All inventory domain events extend this class.
 */
abstract class DomainEvent
{
    private string $eventId;
    private \DateTime $occurredAt;
    private array $metadata;

    public function __construct(array $metadata = [])
    {
        $this->eventId = 'evt_' . bin2hex(random_bytes(16));
        $this->occurredAt = new \DateTime();
        $this->metadata = $metadata;
    }

    public function getEventId(): string { return $this->eventId; }
    public function getOccurredAt(): \DateTime { return $this->occurredAt; }
    public function getMetadata(): array { return $this->metadata; }

    abstract public function getEventName(): string;
    abstract public function getPayload(): array;
}
```

### Stock Reserved Event

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\Event;

use Osc\Inventory\Domain\ValueObject\SKU;
use Osc\Inventory\Domain\ValueObject\Quantity;

/**
 * Stock Reserved Event
 *
 * Fired when stock is reserved for a payment contract.
 */
final class StockReservedEvent extends DomainEvent
{
    private SKU $sku;
    private Quantity $quantity;
    private string $warehouseId;
    private string $contractId;
    private string $reservationId;

    public function __construct(
        SKU $sku,
        Quantity $quantity,
        string $warehouseId,
        string $contractId,
        string $reservationId,
        array $metadata = []
    ) {
        parent::__construct($metadata);
        $this->sku = $sku;
        $this->quantity = $quantity;
        $this->warehouseId = $warehouseId;
        $this->contractId = $contractId;
        $this->reservationId = $reservationId;
    }

    public function getEventName(): string
    {
        return 'inventory.stock_reserved';
    }

    public function getPayload(): array
    {
        return [
            'sku' => $this->sku->value(),
            'quantity' => $this->quantity->value(),
            'warehouse_id' => $this->warehouseId,
            'contract_id' => $this->contractId,
            'reservation_id' => $this->reservationId,
        ];
    }

    // Getters
    public function getSku(): SKU { return $this->sku; }
    public function getQuantity(): Quantity { return $this->quantity; }
    public function getWarehouseId(): string { return $this->warehouseId; }
    public function getContractId(): string { return $this->contractId; }
    public function getReservationId(): string { return $this->reservationId; }
}
```

### Other Domain Events

```php
// StockReleasedEvent - Stock returned to available pool
// StockCommittedEvent - Stock allocated to order
// StockShippedEvent - Physical shipment
// StockReceivedEvent - Supplier delivery
// StockTransferredEvent - Inter-warehouse transfer
// StockAdjustedEvent - Manual stock correction
```

---

## Repositories

Repository interfaces define data access contracts.

### InventoryItemRepository

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\Repository;

use Osc\Inventory\Domain\Model\InventoryItem;
use Osc\Inventory\Domain\ValueObject\SKU;

/**
 * Inventory Item Repository - Interface
 *
 * Persistence contract for InventoryItem aggregate.
 */
interface InventoryItemRepositoryInterface
{
    /**
     * Find inventory item by SKU
     */
    public function findBySku(SKU $sku): ?InventoryItem;

    /**
     * Save inventory item (insert or update)
     */
    public function save(InventoryItem $item): void;

    /**
     * Get items with low stock (below threshold)
     *
     * @param int $threshold
     * @return InventoryItem[]
     */
    public function findLowStock(int $threshold): array;

    /**
     * Get all items in warehouse
     *
     * @param string $warehouseId
     * @return InventoryItem[]
     */
    public function findByWarehouse(string $warehouseId): array;
}
```

---

## Factory Classes

Factories create complex domain objects.

### InventoryItemFactory

```php
<?php

declare(strict_types=1);

namespace Osc\Inventory\Domain\Factory;

use Osc\Inventory\Domain\Model\InventoryItem;
use Osc\Inventory\Domain\ValueObject\SKU;
use Osc\Inventory\Domain\ValueObject\Quantity;

/**
 * Inventory Item Factory
 *
 * Creates InventoryItem aggregates from various sources.
 */
final class InventoryItemFactory
{
    /**
     * Create new inventory item
     */
    public function create(string $sku, string $name, string $description): InventoryItem
    {
        return new InventoryItem(
            sku: new SKU($sku),
            name: $name,
            description: $description
        );
    }

    /**
     * Reconstitute from database data
     */
    public function reconstitute(array $data): InventoryItem
    {
        // Implementation details...
    }

    /**
     * Create from product catalog data
     */
    public function createFromProduct(array $productData): InventoryItem
    {
        return $this->create(
            sku: $productData['sku'],
            name: $productData['name'],
            description: $productData['description'] ?? ''
        );
    }
}
```

---

## Class Relationships Summary

```
Aggregates:
  InventoryItem (root) ──> StockLevel (entity)
                       ──> StockReservation (entity)
  Warehouse (root)

Value Objects:
  SKU, Quantity, Address

Domain Services:
  InventoryService
  WarehouseAllocator

Domain Events:
  StockReservedEvent
  StockReleasedEvent
  StockCommittedEvent
  ...

Repositories:
  InventoryItemRepository
  WarehouseRepository
  StockReservationRepository
```

---

**Version:** 1.0.0
**Last Updated:** 2025-10-21
**Status:** Complete Domain Model Specification
