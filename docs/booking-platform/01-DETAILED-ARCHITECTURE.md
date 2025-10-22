# Booking Platform Module - Detailed Architecture

**Version:** 2.0.0
**Date:** 2025-10-22
**Type:** E-Commerce Module/Plugin
**Philosophy:** Clean Architecture | Domain-Driven Design | Event-Driven | TDD
**Integration:** Payment Component v4.0 | Blockchain Inventory Manager

---

## Table of Contents

1. [Module Architecture](#module-architecture)
2. [Clean Architecture Layers](#clean-architecture-layers)
3. [Platform Adapter Pattern](#platform-adapter-pattern)
4. [Domain Models Deep Dive](#domain-models-deep-dive)
5. [Three-Phase Commit Protocol](#three-phase-commit-protocol)
6. [Event-Driven Architecture](#event-driven-architecture)
7. [Database Design](#database-design)
8. [B2B Integration](#b2b-integration)
9. [External Inventory Sources](#external-inventory-sources)
10. [Testing Strategy](#testing-strategy)

---

## Module Architecture

### Concept: E-Commerce Extension

The Booking Platform is **NOT a standalone application**. It is a **module/plugin** that extends existing e-commerce platforms.

```
┌─────────────────────────────────────────────────────────────┐
│              OXID EE (Foundation)                           │
│  • Existing products (oxarticles)                           │
│  • Existing customers (oxuser)                              │
│  • Existing orders (oxorder)                                │
│  • B2B features (quotes, approvals, credit limits)          │
│  • ERP integration                                          │
└────────────────────────┬────────────────────────────────────┘
                         │ composer require osc/booking-platform
                         ▼
┌─────────────────────────────────────────────────────────────┐
│         Booking Platform Module (Installed)                 │
│  • Extends oxarticles with booking capabilities             │
│  • Creates bookings linked to oxorder                       │
│  • Uses existing oxuser for customers                       │
│  • Adds booking-specific tables (osc_booking_*)            │
│  • Integrates with Payment Component v4.0                   │
│  • Integrates with Blockchain Inventory Manager            │
└─────────────────────────────────────────────────────────────┘
```

### Module Installation Structure

#### OXID EE Module

```
source/modules/osc/booking-platform/
├── metadata.php                        # OXID module definition
├── composer.json                       # Dependencies
├── README.md                           # Module documentation
│
├── Core/                               # Core booking logic (Clean Architecture)
│   ├── Domain/                         # Domain layer (business logic)
│   │   ├── Model/
│   │   │   ├── Booking.php            # Aggregate root
│   │   │   ├── BookableResource.php   # Aggregate root
│   │   │   ├── TimeSlot.php           # Entity
│   │   │   ├── AvailabilityCalendar.php
│   │   │   └── PricingStrategy.php
│   │   ├── ValueObject/
│   │   │   ├── BookingId.php
│   │   │   ├── ResourceId.php
│   │   │   ├── TimeRange.php
│   │   │   ├── Money.php
│   │   │   └── BookingStatus.php
│   │   ├── Repository/
│   │   │   ├── BookingRepositoryInterface.php
│   │   │   └── ResourceRepositoryInterface.php
│   │   ├── Service/
│   │   │   ├── AvailabilityChecker.php
│   │   │   └── PriceCalculator.php
│   │   └── Event/
│   │       ├── BookingCreated.php
│   │       ├── BookingConfirmed.php
│   │       └── BookingCancelled.php
│   │
│   ├── Application/                    # Application layer (use cases)
│   │   ├── UseCase/
│   │   │   ├── CreateBooking/
│   │   │   │   ├── CreateBookingUseCase.php
│   │   │   │   ├── CreateBookingCommand.php
│   │   │   │   └── CreateBookingHandler.php
│   │   │   ├── CancelBooking/
│   │   │   │   ├── CancelBookingUseCase.php
│   │   │   │   └── CancelBookingCommand.php
│   │   │   ├── CheckAvailability/
│   │   │   │   ├── CheckAvailabilityQuery.php
│   │   │   │   └── CheckAvailabilityHandler.php
│   │   │   └── ConfirmBooking/
│   │   │       ├── ConfirmBookingUseCase.php
│   │   │       └── ConfirmBookingCommand.php
│   │   └── EventHandler/
│   │       ├── BookingCreatedHandler.php
│   │       └── BookingConfirmedHandler.php
│   │
│   └── Infrastructure/                 # Infrastructure layer
│       ├── Adapter/                    # Platform adapters
│       │   ├── PlatformAdapterInterface.php
│       │   ├── OxidAdapter.php        # OXID EE implementation
│       │   ├── ShopwareAdapter.php    # Shopware 6 implementation
│       │   └── MagentoAdapter.php     # Magento 2 implementation
│       ├── Integration/                # External component integration
│       │   ├── PaymentComponent/
│       │   │   ├── PaymentComponentClient.php
│       │   │   └── ContractFactory.php
│       │   └── BlockchainInventory/
│       │       ├── InventoryClient.php
│       │       └── LockManager.php
│       ├── Persistence/                # Database access
│       │   ├── OxidBookingRepository.php
│       │   └── OxidResourceRepository.php
│       └── ExternalInventory/          # External providers (optional)
│           ├── BookingComAdapter.php
│           └── AmadeusAdapter.php
│
├── Controller/                         # OXID controllers
│   ├── Admin/
│   │   ├── BookingListController.php
│   │   ├── ResourceManagementController.php
│   │   └── CalendarConfigController.php
│   └── Frontend/
│       ├── BookingController.php
│       └── AvailabilityController.php
│
├── views/                              # Templates
│   ├── admin/
│   │   └── tpl/
│   │       ├── booking_list.tpl
│   │       ├── resource_management.tpl
│   │       └── calendar_config.tpl
│   └── frontend/
│       └── tpl/
│           ├── booking_calendar.tpl
│           ├── booking_form.tpl
│           └── booking_confirmation.tpl
│
├── translations/                       # i18n
│   ├── en/
│   │   └── booking_lang.php
│   └── de/
│       └── booking_lang.php
│
├── migrations/                         # Database migrations
│   ├── 001_create_booking_tables.sql
│   ├── 002_extend_oxarticles.sql
│   ├── 003_extend_oxorder.sql
│   └── 004_create_timeslot_tables.sql
│
└── tests/                              # TDD tests
    ├── Unit/
    │   ├── Domain/
    │   └── Application/
    ├── Integration/
    └── Acceptance/
```

---

## Clean Architecture Layers

### Layer 1: Domain Layer (Core Business Logic)

**Purpose:** Contains business logic, completely independent of frameworks, databases, or external systems.

#### Aggregate Roots

##### Booking

```php
<?php

namespace Osc\BookingPlatform\Core\Domain\Model;

use Osc\BookingPlatform\Core\Domain\ValueObject\BookingId;
use Osc\BookingPlatform\Core\Domain\ValueObject\BookingStatus;
use Osc\BookingPlatform\Core\Domain\ValueObject\Money;
use Osc\BookingPlatform\Core\Domain\Event\BookingCreated;
use Osc\BookingPlatform\Core\Domain\Event\BookingConfirmed;

class Booking
{
    private BookingId $id;
    private OrderId $orderId;                    // Links to oxorder.OXID
    private CustomerId $customerId;              // Links to oxuser.OXID
    private ProductId $productId;                // Links to oxarticles.OXID
    private ResourceId $resourceId;
    private TimeSlot $timeSlot;
    private int $quantity;
    private Money $price;
    private BookingStatus $status;
    private PaymentContractId $paymentContractId;
    private InventoryLockId $inventoryLockId;
    private array $domainEvents = [];
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        BookingId $id,
        CustomerId $customerId,
        ProductId $productId,
        ResourceId $resourceId,
        TimeSlot $timeSlot,
        int $quantity,
        Money $price
    ) {
        $this->id = $id;
        $this->customerId = $customerId;
        $this->productId = $productId;
        $this->resourceId = $resourceId;
        $this->timeSlot = $timeSlot;
        $this->quantity = $quantity;
        $this->price = $price;
        $this->status = BookingStatus::PENDING();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new BookingCreated($this->id, $this->customerId));
    }

    public static function create(
        CustomerId $customerId,
        ProductId $productId,
        ResourceId $resourceId,
        TimeSlot $timeSlot,
        int $quantity,
        Money $price
    ): self {
        return new self(
            BookingId::generate(),
            $customerId,
            $productId,
            $resourceId,
            $timeSlot,
            $quantity,
            $price
        );
    }

    public function confirm(PaymentContractId $contractId, InventoryLockId $lockId): void
    {
        $this->assertStatus([BookingStatus::PENDING()]);

        $this->paymentContractId = $contractId;
        $this->inventoryLockId = $lockId;
        $this->status = BookingStatus::CONFIRMED();
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new BookingConfirmed($this->id));
    }

    public function activate(OrderId $orderId): void
    {
        $this->assertStatus([BookingStatus::CONFIRMED()]);

        $this->orderId = $orderId;
        $this->status = BookingStatus::ACTIVE();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function cancel(RefundPolicy $policy): RefundAmount
    {
        $this->assertStatus([
            BookingStatus::PENDING(),
            BookingStatus::CONFIRMED(),
            BookingStatus::ACTIVE()
        ]);

        $refundAmount = $policy->calculateRefund($this->price, $this->timeSlot);

        $this->status = BookingStatus::CANCELLED();
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new BookingCancelled($this->id, $refundAmount));

        return $refundAmount;
    }

    public function checkIn(\DateTimeImmutable $checkInTime): void
    {
        $this->assertStatus([BookingStatus::ACTIVE()]);

        if ($checkInTime->getTimestamp() < $this->timeSlot->getStart()->getTimestamp()) {
            throw new EarlyCheckInException();
        }

        $this->status = BookingStatus::CHECKED_IN();
        $this->updatedAt = $checkInTime;
    }

    public function complete(): void
    {
        $this->assertStatus([BookingStatus::CHECKED_IN()]);

        $this->status = BookingStatus::COMPLETED();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function noShow(): void
    {
        $this->assertStatus([BookingStatus::ACTIVE()]);

        $this->status = BookingStatus::NO_SHOW();
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function assertStatus(array $allowedStatuses): void
    {
        foreach ($allowedStatuses as $allowed) {
            if ($this->status->equals($allowed)) {
                return;
            }
        }

        throw new InvalidBookingStateException(
            "Cannot perform operation in status {$this->status->value()}"
        );
    }

    private function recordEvent(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    // Getters
    public function getId(): BookingId { return $this->id; }
    public function getOrderId(): ?OrderId { return $this->orderId; }
    public function getCustomerId(): CustomerId { return $this->customerId; }
    public function getProductId(): ProductId { return $this->productId; }
    public function getResourceId(): ResourceId { return $this->resourceId; }
    public function getTimeSlot(): TimeSlot { return $this->timeSlot; }
    public function getQuantity(): int { return $this->quantity; }
    public function getPrice(): Money { return $this->price; }
    public function getStatus(): BookingStatus { return $this->status; }
    public function getPaymentContractId(): ?PaymentContractId { return $this->paymentContractId; }
    public function getInventoryLockId(): ?InventoryLockId { return $this->inventoryLockId; }
}
```

##### BookableResource

```php
<?php

namespace Osc\BookingPlatform\Core\Domain\Model;

class BookableResource
{
    private ResourceId $id;
    private ProductId $productId;                // Links to oxarticles.OXID
    private ResourceType $type;                  // HOTEL, EVENT, APPOINTMENT, RENTAL
    private int $capacity;
    private Duration $duration;
    private AvailabilityCalendar $calendar;
    private PricingStrategy $pricingStrategy;
    private bool $isActive;

    private function __construct(
        ResourceId $id,
        ProductId $productId,
        ResourceType $type,
        int $capacity,
        Duration $duration
    ) {
        $this->id = $id;
        $this->productId = $productId;
        $this->type = $type;
        $this->capacity = $capacity;
        $this->duration = $duration;
        $this->calendar = AvailabilityCalendar::default();
        $this->pricingStrategy = PricingStrategy::fixed();
        $this->isActive = true;
    }

    public static function create(
        ProductId $productId,
        ResourceType $type,
        int $capacity,
        Duration $duration
    ): self {
        if ($capacity <= 0) {
            throw new InvalidCapacityException("Capacity must be positive");
        }

        return new self(
            ResourceId::generate(),
            $productId,
            $type,
            $capacity,
            $duration
        );
    }

    public function checkAvailability(TimeSlot $slot, int $quantity): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if (!$this->calendar->isAvailable($slot)) {
            return false;
        }

        // Check capacity (simplified - actual implementation queries database)
        return $quantity <= $this->capacity;
    }

    public function getAvailableSlots(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        return $this->calendar->getAvailableSlots($from, $to, $this->duration);
    }

    public function calculatePrice(TimeSlot $slot, int $quantity): Money
    {
        return $this->pricingStrategy->calculate($slot, $quantity, $this->capacity);
    }

    public function setPricingStrategy(PricingStrategy $strategy): void
    {
        $this->pricingStrategy = $strategy;
    }

    public function setCalendar(AvailabilityCalendar $calendar): void
    {
        $this->calendar = $calendar;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    // Getters
    public function getId(): ResourceId { return $this->id; }
    public function getProductId(): ProductId { return $this->productId; }
    public function getType(): ResourceType { return $this->type; }
    public function getCapacity(): int { return $this->capacity; }
    public function getDuration(): Duration { return $this->duration; }
    public function isActive(): bool { return $this->isActive; }
}
```

#### Value Objects

```php
<?php

namespace Osc\BookingPlatform\Core\Domain\ValueObject;

class BookingStatus
{
    private string $value;

    private const PENDING = 'pending';
    private const CONFIRMED = 'confirmed';
    private const ACTIVE = 'active';
    private const CHECKED_IN = 'checked_in';
    private const COMPLETED = 'completed';
    private const CANCELLED = 'cancelled';
    private const NO_SHOW = 'no_show';

    private function __construct(string $value)
    {
        if (!in_array($value, [
            self::PENDING,
            self::CONFIRMED,
            self::ACTIVE,
            self::CHECKED_IN,
            self::COMPLETED,
            self::CANCELLED,
            self::NO_SHOW,
        ])) {
            throw new \InvalidArgumentException("Invalid booking status: $value");
        }

        $this->value = $value;
    }

    public static function PENDING(): self { return new self(self::PENDING); }
    public static function CONFIRMED(): self { return new self(self::CONFIRMED); }
    public static function ACTIVE(): self { return new self(self::ACTIVE); }
    public static function CHECKED_IN(): self { return new self(self::CHECKED_IN); }
    public static function COMPLETED(): self { return new self(self::COMPLETED); }
    public static function CANCELLED(): self { return new self(self::CANCELLED); }
    public static function NO_SHOW(): self { return new self(self::NO_SHOW); }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function value(): string
    {
        return $this->value;
    }
}
```

```php
<?php

namespace Osc\BookingPlatform\Core\Domain\ValueObject;

class TimeSlot
{
    private \DateTimeImmutable $start;
    private \DateTimeImmutable $end;

    public function __construct(\DateTimeImmutable $start, \DateTimeImmutable $end)
    {
        if ($start >= $end) {
            throw new \InvalidArgumentException("Start time must be before end time");
        }

        $this->start = $start;
        $this->end = $end;
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $this->end > $other->start;
    }

    public function contains(\DateTimeImmutable $point): bool
    {
        return $point >= $this->start && $point < $this->end;
    }

    public function getDuration(): Duration
    {
        $diff = $this->start->diff($this->end);
        return Duration::fromDateInterval($diff);
    }

    public function getStart(): \DateTimeImmutable { return $this->start; }
    public function getEnd(): \DateTimeImmutable { return $this->end; }
}
```

---

### Layer 2: Application Layer (Use Cases)

**Purpose:** Orchestrates domain logic, coordinates with infrastructure, handles transactions.

```php
<?php

namespace Osc\BookingPlatform\Core\Application\UseCase\CreateBooking;

use Osc\BookingPlatform\Core\Domain\Model\Booking;
use Osc\BookingPlatform\Core\Domain\Repository\BookingRepositoryInterface;
use Osc\BookingPlatform\Core\Domain\Repository\ResourceRepositoryInterface;
use Osc\BookingPlatform\Core\Infrastructure\Adapter\PlatformAdapterInterface;
use Osc\BookingPlatform\Core\Infrastructure\Integration\PaymentComponent\PaymentComponentClient;
use Osc\BookingPlatform\Core\Infrastructure\Integration\BlockchainInventory\InventoryClient;

class CreateBookingUseCase
{
    public function __construct(
        private BookingRepositoryInterface $bookingRepository,
        private ResourceRepositoryInterface $resourceRepository,
        private PlatformAdapterInterface $platformAdapter,
        private PaymentComponentClient $paymentComponent,
        private InventoryClient $inventoryClient
    ) {}

    public function execute(CreateBookingCommand $command): BookingId
    {
        // THREE-PHASE COMMIT PROTOCOL

        // ========================================
        // PHASE 1: PREPARE
        // ========================================

        // 1.1. Validate resource exists and is available
        $resource = $this->resourceRepository->findById($command->resourceId);
        if (!$resource) {
            throw new ResourceNotFoundException();
        }

        if (!$resource->checkAvailability($command->timeSlot, $command->quantity)) {
            throw new ResourceNotAvailableException();
        }

        // 1.2. Get customer from e-commerce platform
        $customer = $this->platformAdapter->getCustomer($command->customerId);

        // 1.3. Check B2B credit limit (if applicable)
        if ($customer->isB2B()) {
            $this->platformAdapter->checkCreditLimit(
                $customer->getId(),
                $command->price
            );
        }

        // 1.4. Create payment contract (DRAFT state)
        $contract = $this->paymentComponent->createContract([
            'customer_id' => $customer->getId(),
            'amount' => $command->price->getAmount(),
            'currency' => $command->price->getCurrency(),
            'description' => "Booking for {$resource->getName()}",
        ]);

        // 1.5. Request inventory lock (PENDING state)
        $lock = $this->inventoryClient->createLock([
            'resource_id' => $resource->getId(),
            'time_slot_start' => $command->timeSlot->getStart(),
            'time_slot_end' => $command->timeSlot->getEnd(),
            'quantity' => $command->quantity,
            'ttl' => 900, // 15 minutes
        ]);

        // 1.6. Create booking entity (PENDING state)
        $booking = Booking::create(
            $customer->getId(),
            $resource->getProductId(),
            $resource->getId(),
            $command->timeSlot,
            $command->quantity,
            $command->price
        );

        try {
            // ========================================
            // PHASE 2: COMMIT
            // ========================================

            // 2.1. Authorize payment (card hold)
            $contract->authorize([
                'payment_method' => $command->paymentMethod,
                'card_token' => $command->cardToken,
            ]);

            // 2.2. Confirm inventory lock (blockchain consensus)
            $lock->confirm();

            // 2.3. Confirm booking
            $booking->confirm($contract->getId(), $lock->getId());

            // ========================================
            // PHASE 3: COMPLETE
            // ========================================

            // 3.1. Capture payment
            $contract->capture();

            // 3.2. Consume inventory (mark as sold)
            $lock->consume();

            // 3.3. Create order in e-commerce platform
            $orderId = $this->platformAdapter->createOrderFromBooking($booking);

            // 3.4. Activate booking (link to order)
            $booking->activate($orderId);

            // 3.5. Persist booking
            $this->bookingRepository->save($booking);

            // 3.6. Dispatch domain events
            foreach ($booking->releaseEvents() as $event) {
                $this->eventDispatcher->dispatch($event);
            }

            return $booking->getId();

        } catch (\Exception $e) {
            // ========================================
            // ROLLBACK ON FAILURE
            // ========================================

            // Release inventory lock
            $lock->release();

            // Cancel payment contract
            $contract->cancel();

            // Delete booking (not persisted yet)
            // No action needed - not saved

            throw new BookingCreationFailedException(
                "Booking creation failed: " . $e->getMessage(),
                previous: $e
            );
        }
    }
}
```

---

## Platform Adapter Pattern

### Why Adapters?

The booking module works with multiple e-commerce platforms (OXID, Shopware, Magento). Each platform has different:
- Database schemas
- APIs
- Data access patterns
- Naming conventions

**Solution:** Adapter pattern abstracts platform differences.

### Interface

```php
<?php

namespace Osc\BookingPlatform\Core\Infrastructure\Adapter;

interface PlatformAdapterInterface
{
    // ========================================
    // PRODUCT OPERATIONS
    // ========================================

    /**
     * Create bookable product in e-commerce catalog
     */
    public function createBookableProduct(BookableResource $resource): ProductId;

    /**
     * Get product details
     */
    public function getProduct(ProductId $id): Product;

    /**
     * Update product availability display
     */
    public function updateProductAvailability(ProductId $id, int $quantity): void;

    // ========================================
    // ORDER OPERATIONS
    // ========================================

    /**
     * Create order in e-commerce after booking confirmed
     */
    public function createOrderFromBooking(Booking $booking): OrderId;

    /**
     * Update order status when booking status changes
     */
    public function updateOrderStatus(OrderId $id, BookingStatus $status): void;

    /**
     * Generate invoice
     */
    public function generateInvoice(OrderId $id): InvoiceId;

    // ========================================
    // CUSTOMER OPERATIONS
    // ========================================

    /**
     * Get customer details
     */
    public function getCustomer(CustomerId $id): Customer;

    /**
     * Get customer booking history
     */
    public function getCustomerBookingHistory(CustomerId $id): array;

    /**
     * Check B2B credit limit
     */
    public function checkCreditLimit(CustomerId $id, Money $amount): bool;

    // ========================================
    // PRICING OPERATIONS
    // ========================================

    /**
     * Calculate order total with discounts/taxes
     */
    public function calculateOrderTotal(ProductId $id, int $quantity): Money;

    /**
     * Apply discount code
     */
    public function applyDiscount(OrderId $id, DiscountCode $code): void;

    // ========================================
    // B2B OPERATIONS
    // ========================================

    /**
     * Check if approval required for this customer/amount
     */
    public function requiresApproval(CustomerId $id, Money $amount): bool;

    /**
     * Create quote for bulk booking
     */
    public function createQuote(Booking $booking): QuoteId;
}
```

### OXID EE Adapter Implementation

```php
<?php

namespace Osc\BookingPlatform\Infrastructure\Adapter;

class OxidAdapter implements PlatformAdapterInterface
{
    public function __construct(
        private DatabaseConnection $db,
        private string $shopId
    ) {}

    public function createOrderFromBooking(Booking $booking): OrderId
    {
        $orderId = $this->generateOxid();

        // Get customer from OXID
        $customer = $this->db->fetchOne(
            "SELECT * FROM oxuser WHERE OXID = ?",
            [$booking->getCustomerId()->toString()]
        );

        // Get product from OXID
        $product = $this->db->fetchOne(
            "SELECT * FROM oxarticles WHERE OXID = ?",
            [$booking->getProductId()->toString()]
        );

        // Insert into oxorder
        $this->db->insert('oxorder', [
            'OXID' => $orderId,
            'OXSHOPID' => $this->shopId,
            'OXUSERID' => $booking->getCustomerId()->toString(),
            'OXORDERDATE' => (new \DateTime())->format('Y-m-d H:i:s'),
            'OXORDERNR' => $this->generateOrderNumber(),
            'OXBILLCOMPANY' => $customer['OXCOMPANY'],
            'OXBILLEMAIL' => $customer['OXUSERNAME'],
            'OXBILLFNAME' => $customer['OXFNAME'],
            'OXBILLLNAME' => $customer['OXLNAME'],
            'OXTOTALORDERSUM' => $booking->getPrice()->getAmount(),
            'OXCURRENCY' => $booking->getPrice()->getCurrency(),
            'OXPAID' => (new \DateTime())->format('Y-m-d H:i:s'), // Already paid
            'OXPAYMENTTYPE' => 'booking_platform',
            'OXTRANSSTATUS' => 'OK',
            // Booking-specific fields (added by migration)
            'OSC_BOOKING_ID' => $booking->getId()->toString(),
            'OSC_PAYMENT_CONTRACT_ID' => $booking->getPaymentContractId()->toString(),
        ]);

        // Insert into oxorderarticles
        $this->db->insert('oxorderarticles', [
            'OXID' => $this->generateOxid(),
            'OXORDERID' => $orderId,
            'OXARTID' => $product['OXID'],
            'OXARTNUM' => $product['OXARTNUM'],
            'OXTITLE' => $this->buildOrderArticleTitle($booking, $product),
            'OXSHORTDESC' => $this->buildOrderArticleDescription($booking),
            'OXAMOUNT' => $booking->getQuantity(),
            'OXBPRICE' => $booking->getPrice()->getAmount(),
            'OXVAT' => $product['OXVAT'],
            'OXTIMESTAMP' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        return new OrderId($orderId);
    }

    private function buildOrderArticleTitle(Booking $booking, array $product): string
    {
        return sprintf(
            '%s - %s to %s',
            $product['OXTITLE'],
            $booking->getTimeSlot()->getStart()->format('Y-m-d H:i'),
            $booking->getTimeSlot()->getEnd()->format('Y-m-d H:i')
        );
    }

    public function checkCreditLimit(CustomerId $id, Money $amount): bool
    {
        // Get B2B customer credit limit from OXID
        $customer = $this->db->fetchOne(
            "SELECT OSC_CREDIT_LIMIT, OSC_CURRENT_BALANCE FROM oxuser WHERE OXID = ?",
            [$id->toString()]
        );

        if (!$customer['OSC_CREDIT_LIMIT']) {
            return true; // No credit limit set
        }

        $available = $customer['OSC_CREDIT_LIMIT'] - $customer['OSC_CURRENT_BALANCE'];

        return $amount->getAmount() <= $available;
    }

    public function requiresApproval(CustomerId $id, Money $amount): bool
    {
        // Check OXID customer group approval settings
        $customer = $this->db->fetchOne(
            "SELECT og.OSC_APPROVAL_THRESHOLD
             FROM oxuser u
             JOIN oxgroups og ON u.OXID = og.OXID
             WHERE u.OXID = ?",
            [$id->toString()]
        );

        if (!$customer['OSC_APPROVAL_THRESHOLD']) {
            return false; // No approval threshold set
        }

        return $amount->getAmount() > $customer['OSC_APPROVAL_THRESHOLD'];
    }

    private function generateOxid(): string
    {
        return md5(uniqid('', true));
    }

    private function generateOrderNumber(): int
    {
        return (int) $this->db->fetchOne(
            "SELECT MAX(OXORDERNR) + 1 FROM oxorder WHERE OXSHOPID = ?",
            [$this->shopId]
        ) ?? 1;
    }
}
```

---

## Three-Phase Commit Protocol

### Distributed Transaction Guarantee

Booking involves three independent systems:
1. **Payment Component** - Payment authorization/capture
2. **Blockchain Inventory Manager** - Inventory locking
3. **E-Commerce Platform** - Order creation

**Problem:** How to ensure all three succeed or all three rollback?

**Solution:** Three-Phase Commit Protocol with compensating actions.

### Phase Breakdown

```
PHASE 1: PREPARE
┌─────────────────────────────────────────────────────────┐
│ Payment Component:    Create contract (DRAFT)          │
│ Blockchain Inventory: Request lock (PENDING)           │
│ Booking Platform:     Create booking (PENDING)         │
│ E-Commerce:           No action yet                    │
└─────────────────────────────────────────────────────────┘
                      ↓ All prepared successfully
PHASE 2: COMMIT
┌─────────────────────────────────────────────────────────┐
│ Payment Component:    Authorize payment (card hold)    │
│ Blockchain Inventory: Confirm lock (consensus)         │
│ Booking Platform:     Confirm booking (CONFIRMED)      │
│ E-Commerce:           No action yet                    │
└─────────────────────────────────────────────────────────┘
                      ↓ All committed successfully
PHASE 3: COMPLETE
┌─────────────────────────────────────────────────────────┐
│ Payment Component:    Capture payment                  │
│ Blockchain Inventory: Consume inventory                │
│ Booking Platform:     Activate booking (ACTIVE)        │
│ E-Commerce:           Create order + invoice           │
└─────────────────────────────────────────────────────────┘

ON FAILURE (Any Phase):
┌─────────────────────────────────────────────────────────┐
│ Payment Component:    Cancel contract, refund          │
│ Blockchain Inventory: Release lock                     │
│ Booking Platform:     Delete booking                   │
│ E-Commerce:           No order created                 │
└─────────────────────────────────────────────────────────┘
```

### Implementation with Saga Pattern

```php
<?php

namespace Osc\BookingPlatform\Core\Application\Saga;

class BookingSaga
{
    private array $compensations = [];

    public function execute(CreateBookingCommand $command): BookingId
    {
        try {
            // PHASE 1: PREPARE
            $contract = $this->preparePayment($command);
            $this->addCompensation(fn() => $contract->cancel());

            $lock = $this->prepareInventory($command);
            $this->addCompensation(fn() => $lock->release());

            $booking = $this->prepareBooking($command);
            // Booking not persisted yet, no compensation needed

            // PHASE 2: COMMIT
            $this->commitPayment($contract);
            $this->commitInventory($lock);
            $this->commitBooking($booking, $contract, $lock);

            // PHASE 3: COMPLETE
            $this->completePayment($contract);
            $this->completeInventory($lock);
            $orderId = $this->completeOrder($booking);
            $this->completeBooking($booking, $orderId);

            return $booking->getId();

        } catch (\Exception $e) {
            $this->compensate();
            throw $e;
        }
    }

    private function addCompensation(callable $compensation): void
    {
        $this->compensations[] = $compensation;
    }

    private function compensate(): void
    {
        // Execute compensations in reverse order
        foreach (array_reverse($this->compensations) as $compensation) {
            try {
                $compensation();
            } catch (\Exception $e) {
                // Log compensation failure but continue
                $this->logger->error("Compensation failed", ['exception' => $e]);
            }
        }
    }
}
```

---

## Database Design

### Module Tables

```sql
-- ========================================
-- BOOKING CORE TABLES
-- ========================================

CREATE TABLE osc_booking_bookings (
    OXID CHAR(32) PRIMARY KEY,
    OXORDERID CHAR(32) NULL,                    -- Links to oxorder (after activation)
    OXUSERID CHAR(32) NOT NULL,                 -- Links to oxuser
    OXARTID CHAR(32) NOT NULL,                  -- Links to oxarticles
    OXRESOURCEID CHAR(32) NOT NULL,             -- Links to osc_booking_resources
    OXTIMESLOTID CHAR(32) NOT NULL,             -- Links to osc_booking_timeslots
    OXPAYMENTCONTRACTID CHAR(32) NOT NULL,      -- Links to payment_contract.id
    OXINVENTORYLOCKID CHAR(32) NOT NULL,        -- Links to blockchain lock
    OXSTATUS VARCHAR(32) NOT NULL,              -- PENDING, CONFIRMED, ACTIVE, etc.
    OXQUANTITY INT NOT NULL,
    OXPRICE DECIMAL(10,2) NOT NULL,
    OXCURRENCY CHAR(3) NOT NULL,
    OXCREATEDAT DATETIME NOT NULL,
    OXUPDATEDAT DATETIME NOT NULL,
    INDEX idx_order (OXORDERID),
    INDEX idx_user (OXUSERID),
    INDEX idx_resource (OXRESOURCEID),
    INDEX idx_status (OXSTATUS),
    FOREIGN KEY (OXUSERID) REFERENCES oxuser(OXID),
    FOREIGN KEY (OXARTID) REFERENCES oxarticles(OXID)
);

CREATE TABLE osc_booking_resources (
    OXID CHAR(32) PRIMARY KEY,
    OXARTID CHAR(32) NOT NULL,                  -- Links to oxarticles
    OXTYPE VARCHAR(32) NOT NULL,                -- HOTEL, EVENT, APPOINTMENT, RENTAL
    OXCAPACITY INT NOT NULL,
    OXDURATION INT NOT NULL,                    -- Duration in minutes
    OXISACTIVE TINYINT(1) DEFAULT 1,
    OXCREATEDAT DATETIME NOT NULL,
    INDEX idx_product (OXARTID),
    INDEX idx_type (OXTYPE),
    FOREIGN KEY (OXARTID) REFERENCES oxarticles(OXID)
);

CREATE TABLE osc_booking_timeslots (
    OXID CHAR(32) PRIMARY KEY,
    OXRESOURCEID CHAR(32) NOT NULL,
    OXSTARTTIME DATETIME NOT NULL,
    OXENDTIME DATETIME NOT NULL,
    OXCAPACITY INT NOT NULL,
    OXBOOKED INT DEFAULT 0,
    OXAVAILABLE INT GENERATED ALWAYS AS (OXCAPACITY - OXBOOKED) STORED,
    INDEX idx_resource (OXRESOURCEID),
    INDEX idx_time (OXSTARTTIME, OXENDTIME),
    INDEX idx_available (OXAVAILABLE),
    FOREIGN KEY (OXRESOURCEID) REFERENCES osc_booking_resources(OXID)
);

CREATE TABLE osc_booking_calendars (
    OXID CHAR(32) PRIMARY KEY,
    OXRESOURCEID CHAR(32) NOT NULL,
    OXRULETYPE VARCHAR(32) NOT NULL,            -- DAILY, WEEKLY, CUSTOM
    OXRULEDATA JSON NOT NULL,                   -- Rule configuration
    OXSTARTDATE DATE NOT NULL,
    OXENDDATE DATE NULL,
    INDEX idx_resource (OXRESOURCEID),
    FOREIGN KEY (OXRESOURCEID) REFERENCES osc_booking_resources(OXID)
);

CREATE TABLE osc_booking_pricing_rules (
    OXID CHAR(32) PRIMARY KEY,
    OXRESOURCEID CHAR(32) NOT NULL,
    OXRULENAME VARCHAR(255) NOT NULL,
    OXRULETYPE VARCHAR(32) NOT NULL,            -- TIME_BASED, QUANTITY_BASED, SEASONAL
    OXRULEDATA JSON NOT NULL,                   -- Rule configuration
    OXPRIORITY INT DEFAULT 0,
    OXISACTIVE TINYINT(1) DEFAULT 1,
    INDEX idx_resource (OXRESOURCEID),
    FOREIGN KEY (OXRESOURCEID) REFERENCES osc_booking_resources(OXID)
);

-- ========================================
-- EXTEND EXISTING OXID TABLES
-- ========================================

ALTER TABLE oxarticles
    ADD COLUMN OSC_BOOKING_ENABLED TINYINT(1) DEFAULT 0,
    ADD COLUMN OSC_BOOKING_RESOURCEID CHAR(32) NULL,
    ADD INDEX idx_booking_enabled (OSC_BOOKING_ENABLED);

ALTER TABLE oxorder
    ADD COLUMN OSC_BOOKING_ID CHAR(32) NULL,
    ADD COLUMN OSC_PAYMENT_CONTRACT_ID CHAR(32) NULL,
    ADD INDEX idx_booking (OSC_BOOKING_ID);

ALTER TABLE oxuser
    ADD COLUMN OSC_CREDIT_LIMIT DECIMAL(10,2) NULL,
    ADD COLUMN OSC_CURRENT_BALANCE DECIMAL(10,2) DEFAULT 0,
    ADD COLUMN OSC_APPROVAL_THRESHOLD DECIMAL(10,2) NULL;
```

---

## B2B Integration

### Customer Groups & Pricing

```php
// Apply customer group discount
$customer = $platformAdapter->getCustomer($customerId);
$customerGroup = $customer->getGroup(); // B2B, VIP, CORPORATE

$basePrice = $resource->calculatePrice($timeSlot, $quantity);

// OXID handles discount via customer group
$finalPrice = $platformAdapter->applyCustomerGroupDiscount(
    $basePrice,
    $customerGroup
);
```

### Quote System

```php
// For bulk bookings > threshold, create quote instead of booking
if ($quantity > 10) {
    $quoteId = $platformAdapter->createQuote([
        'customer_id' => $customerId,
        'resource_id' => $resourceId,
        'time_slot' => $timeSlot,
        'quantity' => $quantity,
        'requested_price' => $estimatedPrice,
    ]);

    // Manager reviews quote in OXID admin
    // On approval, quote converts to booking
}
```

### Approval Workflows

```php
// Check if booking requires approval
if ($platformAdapter->requiresApproval($customerId, $price)) {
    $booking->setPendingApproval();

    // Notify manager via OXID notification system
    $platformAdapter->sendApprovalRequest(
        $customerId,
        $booking->getId(),
        $price
    );

    // On approval, booking proceeds to phase 2 commit
}
```

---

## Testing Strategy

### TDD Approach

```php
<?php

namespace Osc\BookingPlatform\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Osc\BookingPlatform\Core\Domain\Model\Booking;

class BookingTest extends TestCase
{
    public function test_create_booking_with_valid_data(): void
    {
        // Arrange
        $customerId = CustomerId::generate();
        $productId = ProductId::generate();
        $resourceId = ResourceId::generate();
        $timeSlot = new TimeSlot(
            new \DateTimeImmutable('2026-01-15 14:00:00'),
            new \DateTimeImmutable('2026-01-15 16:00:00')
        );
        $quantity = 2;
        $price = Money::fromAmount(150.00, 'EUR');

        // Act
        $booking = Booking::create(
            $customerId,
            $productId,
            $resourceId,
            $timeSlot,
            $quantity,
            $price
        );

        // Assert
        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertTrue($booking->getStatus()->equals(BookingStatus::PENDING()));
        $this->assertEquals($quantity, $booking->getQuantity());
        $this->assertEquals($price, $booking->getPrice());
    }

    public function test_confirm_booking_with_payment_and_lock(): void
    {
        // Arrange
        $booking = $this->createPendingBooking();
        $contractId = PaymentContractId::generate();
        $lockId = InventoryLockId::generate();

        // Act
        $booking->confirm($contractId, $lockId);

        // Assert
        $this->assertTrue($booking->getStatus()->equals(BookingStatus::CONFIRMED()));
        $this->assertEquals($contractId, $booking->getPaymentContractId());
        $this->assertEquals($lockId, $booking->getInventoryLockId());
    }

    public function test_cannot_confirm_already_confirmed_booking(): void
    {
        // Arrange
        $booking = $this->createConfirmedBooking();

        // Act & Assert
        $this->expectException(InvalidBookingStateException::class);
        $booking->confirm(PaymentContractId::generate(), InventoryLockId::generate());
    }
}
```

---

**Next:** [02-MODULE-INTEGRATION.md](02-MODULE-INTEGRATION.md) - Detailed integration patterns
