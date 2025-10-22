# Booking Platform - Federation Hub for Multi-Shop Booking Management

**Version:** 3.0.0 - Federation Architecture
**Date:** 2025-10-22
**Type:** Central Hub (OXID EE 8.x) + Federated Shop Adapters
**Philosophy:** Hub-and-Spoke | Federation | Microservices | Event-Driven
**Integration:** Payment Component v4.0 + Blockchain Inventory Manager v1.0

---

## Executive Summary

The **Booking Platform** is a **centralized booking management hub** that runs on OXID EE and **federates multiple legacy e-commerce shops** across different platforms and locations. It provides unified inventory management, centralized payment processing, and real-time synchronization across all federated shops.

### Real-World Use Case

**Travel Operator with 20 Agency Shops Across Europe:**

```
Problem:
• 20 legacy e-commerce shops (Magento 1.9, OXID 6.2, Shopware 5.7, etc.)
• Scattered inventory (no unified booking system)
• Risk of double-booking across shops
• No centralized payment processing
• Each shop operates independently

Solution: Booking Platform Federation Hub
┌────────────────────────────────────────────────────────────┐
│         CENTRAL HUB (OXID EE 8.x + Booking Module)         │
│  • Master booking system                                   │
│  • Payment Component v4.0 (centralized)                    │
│  • Blockchain Inventory Manager (unified)                  │
│  • Single admin panel for all 20 shops                     │
└──────────────────────────┬─────────────────────────────────┘
                           │ Federation API + WebSocket
        ┌──────────────────┼──────────────────┐
        ↓                  ↓                  ↓
┌──────────────┐   ┌──────────────┐   ┌──────────────┐
│  SHOP #1     │   │  SHOP #2     │   │  SHOP #20    │
│  Amsterdam   │   │  Paris       │   │  Berlin      │
│  Magento 1.9 │   │  OXID 6.2    │   │  Shopware5.7 │
│  Adapter     │   │  Adapter     │   │  Adapter     │
└──────────────┘   └──────────────┘   └──────────────┘

Result:
✓ Booking on Shop #1 → Instantly blocks on Shops #2-20
✓ No double-booking across 20 shops
✓ Centralized payment processing (multi-currency)
✓ Single admin panel to manage all shops
✓ No need to migrate/replace legacy shops
```

### What It Does

**Creates a federation of legacy e-commerce shops for unified booking management:**

- 🌍 **Multi-Shop Federation** - Connect 20+ legacy shops (any platform)
- 🏨 **Unified Inventory** - Single source of truth via Blockchain
- 💳 **Centralized Payment** - Payment Component v4.0 for all shops
- 🚫 **No Double-Booking** - Real-time sync across all shops
- 🎛️ **Single Admin** - Manage all shops from central hub
- 🔌 **Non-Invasive** - Legacy shops stay as-is, adapter connects them

### Booking Types Supported

- 🎫 **Event Tickets** - Concerts, sports, theaters (across multiple venues)
- 🏨 **Hotel Reservations** - Rooms available to all agency shops
- 📅 **Appointment Scheduling** - Clinics, SPAs (multi-location)
- 🎓 **Course Bookings** - Training across regional centers
- 🚗 **Resource Rentals** - Fleet management across agencies

### Key Innovation: Federation Architecture

```
              CENTRAL HUB (OXID EE 8.x)
              ┌────────────────────────┐
              │ Booking Module (Master)│
              │ Payment Component v4.0 │
              │ Blockchain Inventory   │
              │ Federation Service     │
              └───────────┬────────────┘
                          │ Hub-and-Spoke
        ┌─────────────────┼─────────────────┐
        ↓                 ↓                 ↓
  LEGACY SHOP #1    LEGACY SHOP #2    LEGACY SHOP #20
  (Magento 1.9.4)   (OXID 6.2)        (Shopware 5.7)
  Amsterdam, NL     Paris, FR         Berlin, DE
  Adapter Plugin    Adapter Module    Adapter Plugin
└─────────────────────────────────────────────────────────────┘
                            ↓ OPTIONALLY CONNECTS TO
┌─────────────────────────────────────────────────────────────┐
│    EXTERNAL INVENTORY SOURCES (Optional)                    │
│  • Booking.com API • Amadeus GDS • Expedia • Airbnb        │
└─────────────────────────────────────────────────────────────┘
```

---

## Table of Contents

1. [Why This Architecture?](#why-this-architecture)
2. [Module Installation](#module-installation)
3. [Integration with E-Commerce](#integration-with-e-commerce)
4. [Domain Models](#domain-models)
5. [Three-Phase Booking Process](#three-phase-booking-process)
6. [Platform Adapter Pattern](#platform-adapter-pattern)
7. [B2B Enterprise Features](#b2b-enterprise-features)
8. [Technology Stack](#technology-stack)
9. [Performance Characteristics](#performance-characteristics)
10. [Development Roadmap](#development-roadmap)

---

## Why This Architecture?

### The Business Problem

**Enterprise merchants using OXID EE (or Shopware/Magento) want to add booking capabilities WITHOUT:**

❌ Building a separate booking system
❌ Duplicating customer database
❌ Duplicating product catalog
❌ Duplicating order management
❌ Losing B2B features (quotes, approval workflows, credit limits)
❌ Breaking ERP integration
❌ Managing two separate systems

### The Solution

✅ **Install booking module** into existing e-commerce platform
✅ **Extend existing products** with booking capabilities
✅ **Leverage existing customers**, orders, invoices
✅ **Keep B2B features** (customer groups, contract pricing, approvals)
✅ **Keep ERP integration** (orders flow to ERP as usual)
✅ **One unified system** for products AND bookings

### Example Scenario

**Hotel Chain using OXID EE:**

**Before Module:**
- OXID EE with 10,000 B2B customers (travel agencies)
- Selling hotel amenities, gift cards, vouchers
- Separate booking system for room reservations
- No integration between systems

**After Installing Booking Module:**
- Same OXID EE, same customers
- Products now include "Hotel Room - Deluxe" with booking calendar
- Travel agencies book rooms using existing B2B accounts
- Same credit limits, approval workflows apply
- Orders created in OXID → flow to ERP automatically
- One unified system

---

## Module Installation

### OXID EE Installation

```bash
# 1. Install via composer
composer require osc/booking-platform

# 2. Activate module
vendor/bin/oe-console oe:module:install osc/booking-platform
vendor/bin/oe-console oe:module:activate booking-platform

# 3. Run migrations
vendor/bin/oe-console oe:database:migrate booking-platform

# 4. Clear cache
vendor/bin/oe-console oe:cache:clear
```

**Module Structure:**

```
source/modules/osc/booking-platform/
├── metadata.php (OXID module definition)
├── composer.json
├── Core/
│   ├── Domain/
│   │   ├── Model/
│   │   │   ├── Booking.php
│   │   │   ├── BookableResource.php
│   │   │   ├── TimeSlot.php
│   │   │   └── AvailabilityCalendar.php
│   │   ├── Repository/
│   │   └── ValueObject/
│   ├── Application/
│   │   └── UseCase/
│   │       ├── CreateBooking.php
│   │       ├── CancelBooking.php
│   │       └── CheckAvailability.php
│   └── Infrastructure/
│       ├── Adapter/
│       │   └── OxidAdapter.php
│       ├── Integration/
│       │   ├── PaymentComponent/
│       │   └── BlockchainInventory/
│       └── Persistence/
├── Controller/
│   ├── Admin/
│   │   ├── BookingListController.php
│   │   └── ResourceManagementController.php
│   └── Frontend/
│       └── BookingController.php
├── views/
│   ├── admin/
│   │   └── tpl/
│   └── frontend/
│       └── tpl/
└── migrations/
    ├── 001_create_booking_tables.sql
    ├── 002_extend_oxarticles.sql
    └── 003_extend_oxorder.sql
```

### Shopware 6 Installation

```bash
# 1. Install via composer
composer require osc/booking-platform-shopware

# 2. Install plugin
bin/console plugin:install --activate OscBookingPlatform

# 3. Run migrations
bin/console database:migrate OscBookingPlatform --all

# 4. Clear cache
bin/console cache:clear
```

### Magento 2 Installation

```bash
# 1. Install via composer
composer require osc/booking-platform-magento

# 2. Enable module
bin/magento module:enable Osc_BookingPlatform

# 3. Run setup
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

---

## Integration with E-Commerce

### Product Integration

**BookableResource extends Product:**

```php
<?php

namespace Osc\BookingPlatform\Core\Domain\Model;

class BookableResource
{
    // Links to e-commerce product (oxarticles.OXID)
    private ProductId $productId;

    // Booking-specific properties
    private ResourceType $type;           // HOTEL, EVENT, APPOINTMENT
    private int $capacity;
    private Duration $duration;           // e.g., 60 minutes, 2 nights
    private AvailabilityCalendar $calendar;
    private PricingStrategy $pricing;     // Time-based pricing

    // Product properties (delegated to e-commerce)
    public function getName(): string
    {
        return $this->platformAdapter->getProductName($this->productId);
    }

    public function getBasePrice(): Money
    {
        return $this->platformAdapter->getProductPrice($this->productId);
    }

    public function getDescription(): string
    {
        return $this->platformAdapter->getProductDescription($this->productId);
    }
}
```

**Database Structure:**

```sql
-- Existing OXID table (extended)
ALTER TABLE oxarticles
    ADD COLUMN OSC_BOOKING_ENABLED TINYINT(1) DEFAULT 0,
    ADD COLUMN OSC_BOOKING_RESOURCEID CHAR(32) NULL;

-- New module table
CREATE TABLE osc_booking_resources (
    OXID CHAR(32) PRIMARY KEY,
    OXARTID CHAR(32) NOT NULL,  -- Foreign key to oxarticles
    OXTYPE VARCHAR(32) NOT NULL,
    OXCAPACITY INT NOT NULL,
    OXDURATION INT NOT NULL,
    FOREIGN KEY (OXARTID) REFERENCES oxarticles(OXID)
);
```

**Admin Workflow:**

1. Admin creates product "Hotel Room - Deluxe" in OXID admin (oxarticles)
2. Admin checks "Enable Booking" checkbox
3. Module creates `osc_booking_resources` entry linked to product
4. Admin configures:
   - Resource type: HOTEL
   - Capacity: 1 (single room)
   - Duration: Daily (check-in to check-out)
   - Calendar: Availability rules
   - Pricing: Weekend vs. weekday rates
5. Product appears in storefront with booking calendar

### Customer Integration

**Use existing customer database:**

```php
// No separate customer management!
// Booking uses existing e-commerce customers

$booking = new Booking(
    customerId: CustomerId::fromString($oxUserId),  // oxuser.OXID
    customerEmail: $customer->getEmail(),           // oxuser.OXUSERNAME
    customerName: $customer->getFullName(),         // oxuser.OXFNAME + OXLNAME
    // For B2B customers:
    customerCompany: $customer->getCompany(),       // oxuser.OXCOMPANY
    customerVatId: $customer->getVatId(),           // oxuser.OXUSTID
    // ... booking data
);
```

**Benefits:**
- ✅ Use existing customer accounts
- ✅ Customer groups apply (B2B, VIP, etc.)
- ✅ Existing discounts apply
- ✅ Credit limits enforced
- ✅ Customer history includes bookings

### Order Integration

**Booking creates Order:**

```php
<?php

namespace Osc\BookingPlatform\Core\Application\UseCase;

class CreateBookingUseCase
{
    public function execute(CreateBookingCommand $command): BookingId
    {
        // THREE-PHASE COMMIT

        // PHASE 1: PREPARE
        $contract = $this->paymentComponent->createContract($basket);
        $lock = $this->blockchainInventory->createLock($resource, $timeSlot);
        $booking = new Booking(
            status: BookingStatus::PENDING,
            // ... other data
        );

        // PHASE 2: COMMIT
        $contract->authorize();  // Hold payment
        $lock->confirm();        // Lock inventory
        $booking->confirm();     // Confirm booking

        // PHASE 3: COMPLETE
        $contract->capture();    // Capture payment
        $lock->consume();        // Consume inventory
        $booking->activate();    // Activate booking

        // CREATE ORDER IN E-COMMERCE
        $orderId = $this->platformAdapter->createOrderFromBooking($booking);
        // Creates entry in oxorder:
        // - OXID = order ID
        // - OXUSERID = customer ID
        // - OSC_BOOKING_ID = booking ID
        // - OSC_PAYMENT_CONTRACT_ID = contract ID
        // - OXTOTALORDERSUM = booking price
        // - OXPAID = datetime (already paid via Payment Component)

        $booking->setOrderId($orderId);

        return $booking->getId();
    }
}
```

**Benefits:**
- ✅ Order appears in e-commerce admin
- ✅ Invoice generated automatically
- ✅ Order flows to ERP system
- ✅ Customer sees booking in order history
- ✅ Same order management tools

---

## Domain Models

### Booking (Aggregate Root)

```php
<?php

namespace Osc\BookingPlatform\Core\Domain\Model;

class Booking
{
    private BookingId $id;
    private OrderId $orderId;                    // Links to oxorder.OXID
    private CustomerId $customerId;              // Links to oxuser.OXID
    private ProductId $productId;                // Links to oxarticles.OXID
    private ResourceId $resourceId;              // Links to osc_booking_resources
    private TimeSlot $timeSlot;
    private int $quantity;
    private Money $price;
    private BookingStatus $status;
    private PaymentContractId $paymentContractId;
    private InventoryLockId $inventoryLockId;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    // Domain methods
    public function confirm(): void;
    public function cancel(RefundPolicy $policy): RefundAmount;
    public function checkIn(\DateTimeImmutable $checkInTime): void;
    public function checkOut(\DateTimeImmutable $checkOutTime): void;
    public function noShow(): void;
}
```

### BookableResource (Aggregate Root)

```php
<?php

namespace Osc\BookingPlatform\Core\Domain\Model;

class BookableResource
{
    private ResourceId $id;
    private ProductId $productId;                // Links to oxarticles.OXID
    private ResourceType $type;                  // HOTEL, EVENT, APPOINTMENT
    private int $capacity;
    private Duration $duration;
    private AvailabilityCalendar $calendar;
    private PricingStrategy $pricingStrategy;
    private bool $isActive;

    // Domain methods
    public function checkAvailability(TimeSlot $slot, int $quantity): bool;
    public function getAvailableSlots(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
    public function calculatePrice(TimeSlot $slot, int $quantity): Money;
}
```

### TimeSlot (Value Object)

```php
<?php

namespace Osc\BookingPlatform\Core\Domain\ValueObject;

class TimeSlot
{
    private \DateTimeImmutable $start;
    private \DateTimeImmutable $end;

    public function overlaps(TimeSlot $other): bool;
    public function contains(\DateTimeImmutable $point): bool;
    public function getDuration(): Duration;
}
```

---

## Three-Phase Booking Process

### Phase 1: PREPARE

```
User selects:
  • Resource: "Hotel Room - Deluxe"
  • Dates: Jan 15-17, 2026
  • Quantity: 2 rooms

System:
  ✓ Create payment contract (DRAFT)
  ✓ Request inventory lock (PENDING)
  ✓ Create booking record (PENDING)

State: All prepared, nothing committed yet
```

### Phase 2: COMMIT

```
System:
  ✓ Authorize payment (card hold)
  ✓ Confirm inventory lock (blockchain consensus)
  ✓ Confirm booking (CONFIRMED)

State: Locked, can proceed to completion
```

### Phase 3: COMPLETE

```
System:
  ✓ Capture payment
  ✓ Consume inventory
  ✓ Activate booking (ACTIVE)
  ✓ Create order in e-commerce (oxorder)
  ✓ Generate invoice
  ✓ Send confirmation email

State: Booking complete, order created
```

### Rollback on Failure

```
If ANY phase fails:
  ✓ Release inventory lock
  ✓ Cancel payment contract
  ✓ Delete booking record
  ✓ Refund customer (if payment captured)

Example: Payment declined
  → Lock released automatically
  → Booking not created
  → Inventory available again
```

---

## Platform Adapter Pattern

### Why Adapters?

**The booking module is e-commerce platform agnostic.**

Same booking logic works with:
- OXID EE 7.4, 7.5, 8.0+
- Shopware 6.x
- Magento 2.x

**Achieved via adapter pattern:**

```php
interface PlatformAdapterInterface
{
    // Product operations
    public function createBookableProduct(BookableResource $resource): ProductId;
    public function getProduct(ProductId $id): Product;
    public function updateProductAvailability(ProductId $id, int $quantity): void;

    // Order operations
    public function createOrderFromBooking(Booking $booking): OrderId;
    public function updateOrderStatus(OrderId $id, BookingStatus $status): void;
    public function generateInvoice(OrderId $id): InvoiceId;

    // Customer operations
    public function getCustomer(CustomerId $id): Customer;
    public function getCustomerBookingHistory(CustomerId $id): array;

    // Pricing operations
    public function calculateOrderTotal(ProductId $id, int $quantity): Money;
    public function applyDiscount(OrderId $id, DiscountCode $code): void;
}
```

### OXID Adapter Example

```php
<?php

namespace Osc\BookingPlatform\Infrastructure\Adapter;

class OxidAdapter implements PlatformAdapterInterface
{
    public function createOrderFromBooking(Booking $booking): OrderId
    {
        $orderId = md5(uniqid('', true));

        // Insert into oxorder
        $this->db->insert('oxorder', [
            'OXID' => $orderId,
            'OXSHOPID' => $this->shopId,
            'OXUSERID' => $booking->getCustomerId()->toString(),
            'OXORDERDATE' => (new \DateTime())->format('Y-m-d H:i:s'),
            'OXORDERNR' => $this->generateOrderNumber(),
            'OXTOTALORDERSUM' => $booking->getPrice()->getAmount(),
            'OXCURRENCY' => $booking->getPrice()->getCurrency(),
            'OXPAID' => (new \DateTime())->format('Y-m-d H:i:s'),
            'OXPAYMENTTYPE' => 'booking_platform',
            'OXTRANSSTATUS' => 'OK',
            // Booking-specific
            'OSC_BOOKING_ID' => $booking->getId()->toString(),
            'OSC_PAYMENT_CONTRACT_ID' => $booking->getPaymentContractId()->toString(),
        ]);

        // Insert into oxorderarticles
        $this->createOrderArticle($orderId, $booking);

        return new OrderId($orderId);
    }
}
```

---

## B2B Enterprise Features

### Leverage Existing B2B Capabilities

**OXID EE provides enterprise B2B features. The booking module leverages them:**

#### 1. Customer Groups & Pricing

```php
// B2B customer gets corporate rate
$customer = $this->platformAdapter->getCustomer($customerId);
$customerGroup = $customer->getGroup(); // "TRAVEL_AGENCY"

$price = $resource->calculatePrice($timeSlot, $quantity);

// Apply customer group discount
if ($customerGroup === 'TRAVEL_AGENCY') {
    $price = $price->multiply(0.85); // 15% discount
}
```

#### 2. Quote System

```
B2B Customer workflow:
  1. Browse "Hotel Room - Deluxe"
  2. Select dates: Jan 15-30 (15 nights)
  3. Quantity: 50 rooms
  4. Click "Request Quote" (instead of "Book Now")
  5. Quote created in OXID → Manager reviews → Approves with custom price
  6. Customer accepts quote → Booking created → Order created
```

#### 3. Approval Workflows

```
Corporate policy:
  • Bookings < $1,000: Employee can book directly
  • Bookings > $1,000: Manager approval required

Employee books conference room ($2,500):
  → Booking created (status: PENDING_APPROVAL)
  → Manager gets notification
  → Manager approves → Booking confirmed → Order created
```

#### 4. Credit Limits

```
B2B Customer: Travel Agency Ltd.
  • Credit limit: $50,000
  • Current balance: $45,000
  • Available credit: $5,000

Tries to book $8,000 worth of rooms:
  → Declined (exceeds credit limit)
  → Booking not created

Tries to book $3,000 worth of rooms:
  → Approved (within credit limit)
  → Booking created → Order created → Balance: $48,000
```

#### 5. Contract Pricing

```
Corporate Contract with Hotel Chain:
  • Negotiated rate: $120/night (regular: $150)
  • Valid: 2026-01-01 to 2026-12-31
  • Volume commitment: 1,000 room-nights

When corporate customer books:
  → Contract rate applied automatically
  → Booking created at $120/night
```

---

## Technology Stack

### Required Components

| Component | Technology | Version | Purpose |
|-----------|-----------|---------|---------|
| **E-Commerce Platform** | OXID EE / Shopware / Magento | 7.x+ / 6.x / 2.x | Foundation |
| **PHP** | PHP | 8.1+ | Module language |
| **Database** | MySQL/MariaDB | 8.0+ / 10.6+ | Data persistence |
| **Payment Component** | Payment Component v4.0 | 4.0+ | Smart payment contracts |
| **Blockchain Inventory** | Hyperledger Fabric | 2.4+ | Inventory management |

### Optional Components

| Component | Technology | Purpose |
|-----------|-----------|---------|
| **Cache** | Redis | 6.0+ | Availability caching |
| **Queue** | RabbitMQ | 3.8+ | Async processing |
| **Search** | Elasticsearch | 8.0+ | Availability search |

---

## Performance Characteristics

### Benchmarks (Target)

| Operation | Target Latency | Notes |
|-----------|---------------|-------|
| Check availability | <100ms | Redis cache |
| Create booking | <500ms | 3-phase commit |
| Confirm booking | <1s | Blockchain consensus |
| Cancel booking | <500ms | Immediate |
| Load calendar | <200ms | Cached |

### Scalability

| Metric | Target | Strategy |
|--------|--------|----------|
| **Concurrent bookings** | 10,000/sec | Horizontal scaling |
| **Bookable resources** | 1M+ | Partitioned by type |
| **Bookings/day** | 50M+ | Time-series optimization |

---

## Development Roadmap

### Phase 1: Core Module (Weeks 1-8) ✅ DESIGN COMPLETE

- ✅ Architecture design
- ✅ Module structure (OXID, Shopware, Magento)
- ✅ Platform adapter interface
- 🔄 Database schema (next)
- 🔄 Domain models implementation
- 🔄 TDD strategy

### Phase 2: Integration (Weeks 9-12)

- 🔄 Payment Component v4.0 integration
- 🔄 Blockchain Inventory Manager integration
- 🔄 OXID EE adapter implementation
- 🔄 Admin panel (resource management)
- 🔄 Frontend (booking calendar)

### Phase 3: Enterprise Features (Weeks 13-16)

- 🔄 B2B features (quotes, approvals, credit limits)
- 🔄 Multi-store support
- 🔄 External inventory sources (Booking.com, Amadeus)
- 🔄 Shopware adapter
- 🔄 Magento adapter

---

## Summary

### What This Module Does

✅ **Transforms OXID EE (or Shopware/Magento) into booking platform**
✅ **Extends existing products** with booking capabilities
✅ **Leverages existing customers, orders, invoices**
✅ **Keeps B2B features** (quotes, approvals, credit limits, contract pricing)
✅ **Integrates with enterprise components** (Payment Component, Blockchain Inventory)
✅ **Remains platform agnostic** via adapter layer

### Installation Targets

**Primary:** OXID EE 7.x, 8.x (Full B2B + B2C enterprise features)
**Secondary:** Shopware 6.x, Magento 2.x (via adapters)

### Key Benefits

- 🎯 No duplicate systems (one unified platform)
- 🎯 Leverage existing customer base
- 🎯 Keep B2B features (quotes, contracts, credit limits)
- 🎯 Keep ERP integration
- 🎯 Enterprise-grade (no double-booking, smart payments)
- 🎯 E-commerce platform agnostic

---

**Next Steps:**
1. **For Architects**: Read [architecture/00-ARCHITECTURE-CONCEPT.md](architecture/00-ARCHITECTURE-CONCEPT.md)
2. **For Developers**: Review module structure and adapter pattern
3. **For Merchants**: Understand B2B integration benefits
4. **For Stakeholders**: Review ROI of unified system vs. separate booking platform

---

**Status:** 📝 Documentation Phase v2.0 (Module Architecture)
**Next Milestone:** Database schema & TDD strategy for OXID EE module
