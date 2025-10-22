# E-Commerce Backend Integration - Detailed Specification

**Version:** 1.0.0
**Date:** 2025-10-22
**Purpose:** Define how the Booking Platform integrates with e-commerce backends (OXID, Shopware, Magento, WooCommerce)

---

## Table of Contents

1. [Integration Concept](#integration-concept)
2. [The E-Commerce Adapter Pattern](#the-e-commerce-adapter-pattern)
3. [Data Flow & Synchronization](#data-flow--synchronization)
4. [Adapter Implementation Examples](#adapter-implementation-examples)
5. [Standalone Mode (No E-Commerce)](#standalone-mode-no-e-commerce)
6. [Migration Scenarios](#migration-scenarios)

---

## Integration Concept

### What Role Does E-Commerce Play?

The e-commerce platform is an **optional backend system** that provides:

1. **Product Catalog** - Bookable resources exposed as products
2. **Customer Management** - Existing customer database, authentication
3. **Order Management** - Invoicing, order history, fulfillment
4. **Storefront UI** - Existing shop interface (optional - can use standalone UI)
5. **Payment Processing** - Existing payment methods (delegated to Payment Component v4.0)

### Architecture Position

```
┌─────────────────────────────────────────────────────────────┐
│                    CLIENT LAYER (Optional)                  │
│  • E-commerce Storefront (OXID/Shopware/Magento)           │
│  • Standalone Booking UI (React/Vue/Mobile App)             │
│  • Kiosk Interface                                          │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│              BOOKING PLATFORM CORE (Required)               │
│  • Booking logic (create, cancel, confirm)                  │
│  • Availability management                                  │
│  • Calendar engine                                          │
│  • Price calculation                                        │
└─────┬──────────────────────────────────────────────────┬────┘
      │                                                   │
      ▼                                                   ▼
┌───────────────────────┐                   ┌──────────────────────┐
│ Payment Component v4  │                   │ Blockchain Inventory │
│ (Required)            │                   │ Manager (Required)   │
└───────────────────────┘                   └──────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────┐
│         E-COMMERCE ADAPTER LAYER (Optional)                 │
│  • OXID Adapter - integrates with OXID eShop                │
│  • Shopware Adapter - integrates with Shopware 6            │
│  • Magento Adapter - integrates with Magento 2              │
│  • WooCommerce Adapter - integrates with WooCommerce        │
│  • Standalone Mode - no e-commerce backend                  │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│           E-COMMERCE PLATFORMS (Optional)                   │
│  • OXID eShop Database (oxarticles, oxorder, oxuser)       │
│  • Shopware Database (product, order, customer)            │
│  • Magento Database (catalog_product, sales_order)         │
└─────────────────────────────────────────────────────────────┘
```

### Key Concept: Backend Integration, Not Frontend

❌ **Wrong understanding**: "E-commerce is where users shop"
✅ **Correct understanding**: "E-commerce is the optional backend for order/customer/product management"

**The booking platform is independent.** It can:
- Work standalone (own UI, own customer DB)
- Integrate with e-commerce backend (share customers, products, orders)
- Mix both (standalone UI + e-commerce backend)

---

## The E-Commerce Adapter Pattern

### Interface Definition

```php
<?php

namespace BookingPlatform\Adapter\Ecommerce;

use BookingPlatform\Domain\Model\Booking;
use BookingPlatform\Domain\Model\BookableResource;
use BookingPlatform\Domain\ValueObject\Money;

interface EcommerceAdapterInterface
{
    // ========================================
    // PRODUCT CATALOG INTEGRATION
    // ========================================

    /**
     * Sync bookable resource to e-commerce product catalog
     *
     * Creates or updates a product that represents a bookable resource
     * (e.g., "Hotel Room Deluxe" or "Concert Ticket - VIP Section")
     */
    public function syncResourceToProduct(BookableResource $resource): ProductId;

    /**
     * Get product ID for a bookable resource
     *
     * Returns null if resource is not synced to e-commerce
     */
    public function getProductIdForResource(ResourceId $resourceId): ?ProductId;

    /**
     * Update product availability in e-commerce
     *
     * Keeps e-commerce stock in sync with booking availability
     */
    public function updateProductAvailability(
        ProductId $productId,
        int $availableQuantity
    ): void;

    // ========================================
    // ORDER MANAGEMENT INTEGRATION
    // ========================================

    /**
     * Create order in e-commerce after booking is confirmed
     *
     * This is called AFTER the booking is confirmed in the booking platform.
     * The order is created as a record of the transaction.
     *
     * @return OrderId The e-commerce order ID
     */
    public function createOrderFromBooking(Booking $booking): OrderId;

    /**
     * Update order status when booking status changes
     *
     * Examples:
     * - Booking confirmed → Order status "completed"
     * - Booking cancelled → Order status "cancelled" + refund
     * - Booking no-show → Order status "failed"
     */
    public function updateOrderStatus(
        OrderId $orderId,
        BookingStatus $newStatus
    ): void;

    /**
     * Cancel order in e-commerce (when booking is cancelled)
     *
     * Triggers refund processing via Payment Component
     */
    public function cancelOrder(OrderId $orderId, RefundAmount $refundAmount): void;

    // ========================================
    // CUSTOMER INTEGRATION
    // ========================================

    /**
     * Get customer details from e-commerce
     *
     * Returns customer info (name, email, address) for booking confirmation
     */
    public function getCustomer(CustomerId $customerId): Customer;

    /**
     * Create customer in e-commerce if not exists
     *
     * Used when booking is made by guest (no e-commerce account)
     */
    public function createCustomerFromBooking(Booking $booking): CustomerId;

    // ========================================
    // PRICING INTEGRATION
    // ========================================

    /**
     * Get product price from e-commerce
     *
     * The booking platform can use e-commerce prices or its own pricing engine.
     * This method fetches the e-commerce price for display/validation.
     */
    public function getProductPrice(ProductId $productId): Money;

    /**
     * Calculate order total with e-commerce discounts/taxes
     *
     * E-commerce may have vouchers, discounts, tax rules that need to be applied
     */
    public function calculateOrderTotal(
        ProductId $productId,
        int $quantity,
        ?VoucherCode $voucherCode = null
    ): OrderTotalCalculation;

    // ========================================
    // INVOICE INTEGRATION
    // ========================================

    /**
     * Generate invoice in e-commerce system
     *
     * Called after payment is completed
     */
    public function generateInvoice(OrderId $orderId): InvoiceId;

    /**
     * Get invoice PDF URL
     */
    public function getInvoiceUrl(InvoiceId $invoiceId): string;
}
```

---

## Data Flow & Synchronization

### Flow 1: User Makes a Booking (With E-Commerce)

```
┌────────────────────────────────────────────────────────────────┐
│ STEP 1: PRODUCT CATALOG SYNC (Background Job)                 │
├────────────────────────────────────────────────────────────────┤
│ Admin creates BookableResource "Hotel Room - Deluxe"          │
│      ↓                                                         │
│ EcommerceAdapter->syncResourceToProduct(resource)             │
│      ↓                                                         │
│ OXID/Shopware: Create product "Hotel Room - Deluxe"          │
│      ↓                                                         │
│ Store mapping: resource_id → product_id                       │
└────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────┐
│ STEP 2: USER BROWSES & SELECTS RESOURCE                       │
├────────────────────────────────────────────────────────────────┤
│ User visits e-commerce storefront (OXID/Shopware)             │
│      ↓                                                         │
│ Browses product "Hotel Room - Deluxe"                         │
│      ↓                                                         │
│ Clicks "Book Now" → Redirects to Booking Platform UI          │
│      ↓                                                         │
│ User selects date/time (e.g., "Jan 15-17, 2026")             │
│      ↓                                                         │
│ Booking Platform: Check availability via Blockchain Inventory │
│      ↓                                                         │
│ Shows available → User clicks "Confirm Booking"               │
└────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────┐
│ STEP 3: THREE-PHASE COMMIT (Distributed Transaction)          │
├────────────────────────────────────────────────────────────────┤
│ PHASE 1: PREPARE                                               │
│   • Payment Component: Create payment contract (DRAFT)        │
│   • Blockchain Inventory: Create lock (resource + time slot)  │
│   • Booking Platform: Create booking (PENDING)                │
│                                                                │
│ PHASE 2: COMMIT                                                │
│   • Payment Component: Authorize payment (card hold)          │
│   • Blockchain Inventory: Confirm lock (consensus)            │
│   • Booking Platform: Update booking (CONFIRMED)              │
│                                                                │
│ PHASE 3: COMPLETE                                              │
│   • Payment Component: Capture payment                        │
│   • Blockchain Inventory: Consume inventory                   │
│   • Booking Platform: Activate booking (ACTIVE)               │
│   • E-Commerce: Create order (via adapter)                    │
│        ↓                                                       │
│   EcommerceAdapter->createOrderFromBooking(booking)           │
│        ↓                                                       │
│   OXID/Shopware: Insert into oxorder/sales_order table        │
│        ↓                                                       │
│   Link: booking.e_commerce_order_id = order.id                │
│        ↓                                                       │
│   E-Commerce: Generate invoice                                │
│        ↓                                                       │
│   Send confirmation email (with invoice link)                 │
└────────────────────────────────────────────────────────────────┘
```

**Key Points:**

1. **E-commerce order is created AFTER booking is confirmed** - not before!
2. **Payment is handled by Payment Component v4.0** - not by e-commerce payment gateway
3. **Inventory is managed by Blockchain Inventory Manager** - not by e-commerce stock system
4. **E-commerce receives final order** for invoicing, customer history, and reporting

---

### Flow 2: User Makes a Booking (Standalone Mode)

```
┌────────────────────────────────────────────────────────────────┐
│ STEP 1: USER BROWSES & SELECTS RESOURCE                       │
├────────────────────────────────────────────────────────────────┤
│ User visits Booking Platform UI (standalone app)               │
│      ↓                                                         │
│ Browses resource "Hotel Room - Deluxe"                        │
│      ↓                                                         │
│ User selects date/time (e.g., "Jan 15-17, 2026")             │
│      ↓                                                         │
│ Booking Platform: Check availability via Blockchain Inventory │
│      ↓                                                         │
│ Shows available → User clicks "Confirm Booking"               │
└────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────┐
│ STEP 2: THREE-PHASE COMMIT (No E-Commerce)                    │
├────────────────────────────────────────────────────────────────┤
│ PHASE 1: PREPARE                                               │
│   • Payment Component: Create payment contract (DRAFT)        │
│   • Blockchain Inventory: Create lock (resource + time slot)  │
│   • Booking Platform: Create booking (PENDING)                │
│                                                                │
│ PHASE 2: COMMIT                                                │
│   • Payment Component: Authorize payment (card hold)          │
│   • Blockchain Inventory: Confirm lock (consensus)            │
│   • Booking Platform: Update booking (CONFIRMED)              │
│                                                                │
│ PHASE 3: COMPLETE                                              │
│   • Payment Component: Capture payment                        │
│   • Blockchain Inventory: Consume inventory                   │
│   • Booking Platform: Activate booking (ACTIVE)               │
│   • ⚠️  NO E-COMMERCE ORDER CREATED (standalone mode)         │
│        ↓                                                       │
│   Booking Platform: Generate invoice internally               │
│        ↓                                                       │
│   Send confirmation email (with booking confirmation)         │
└────────────────────────────────────────────────────────────────┘
```

**Key Difference:**
- No order created in e-commerce
- Booking Platform handles invoicing internally
- Customer management done by Booking Platform

---

## Adapter Implementation Examples

### OXID eShop Adapter

```php
<?php

namespace BookingPlatform\Adapter\Ecommerce\OXID;

use BookingPlatform\Adapter\Ecommerce\EcommerceAdapterInterface;
use BookingPlatform\Domain\Model\Booking;
use BookingPlatform\Domain\Model\BookableResource;

class OxidAdapter implements EcommerceAdapterInterface
{
    private DatabaseConnection $db;
    private string $shopId;

    public function syncResourceToProduct(BookableResource $resource): ProductId
    {
        $productId = $this->findExistingProduct($resource->getId());

        if ($productId === null) {
            // Create new OXID article
            $productId = $this->createOxidArticle($resource);
        } else {
            // Update existing article
            $this->updateOxidArticle($productId, $resource);
        }

        // Store mapping
        $this->storeMappingResourceToProduct($resource->getId(), $productId);

        return $productId;
    }

    private function createOxidArticle(BookableResource $resource): ProductId
    {
        $oxid = $this->generateOxid();

        $this->db->insert('oxarticles', [
            'OXID' => $oxid,
            'OXSHOPID' => $this->shopId,
            'OXARTNUM' => $resource->getSku(),
            'OXTITLE' => $resource->getName(),
            'OXSHORTDESC' => $resource->getShortDescription(),
            'OXLONGDESC' => $resource->getLongDescription(),
            'OXPRICE' => $resource->getBasePrice()->getAmount(),
            'OXACTIVE' => 1,
            'OXACTIVEFROM' => $resource->getAvailableFrom()?->format('Y-m-d H:i:s'),
            'OXACTIVETO' => $resource->getAvailableTo()?->format('Y-m-d H:i:s'),
            'OXSTOCK' => 0, // Managed by Blockchain Inventory
            'OXSTOCKFLAG' => 3, // Don't check stock (we manage availability)
            // Custom fields
            'OXBOOKABLERESOURCEID' => $resource->getId()->toString(),
            'OXISBOOKABLE' => 1,
        ]);

        return new ProductId($oxid);
    }

    public function createOrderFromBooking(Booking $booking): OrderId
    {
        $orderId = $this->generateOxid();

        // Create order header
        $this->db->insert('oxorder', [
            'OXID' => $orderId,
            'OXSHOPID' => $this->shopId,
            'OXUSERID' => $booking->getCustomerId()->toString(),
            'OXORDERDATE' => (new \DateTime())->format('Y-m-d H:i:s'),
            'OXORDERNR' => $this->generateOrderNumber(),
            'OXBILLCOMPANY' => $booking->getCustomerName(),
            'OXBILLEMAIL' => $booking->getCustomerEmail(),
            'OXBILLFNAME' => $booking->getCustomerFirstName(),
            'OXBILLLNAME' => $booking->getCustomerLastName(),
            'OXTOTALORDERSUM' => $booking->getPrice()->getAmount(),
            'OXCURRENCY' => $booking->getPrice()->getCurrency(),
            'OXPAID' => (new \DateTime())->format('Y-m-d H:i:s'), // Already paid
            'OXPAYMENTTYPE' => 'booking_platform',
            'OXTRANSSTATUS' => 'OK',
            // Custom fields
            'OXBOOKINGID' => $booking->getId()->toString(),
            'OXPAYMENTCONTRACTID' => $booking->getPaymentContractId()->toString(),
        ]);

        // Create order article (single line item)
        $this->createOrderArticle($orderId, $booking);

        // Generate invoice
        $this->generateOxidInvoice($orderId);

        return new OrderId($orderId);
    }

    private function createOrderArticle(string $orderId, Booking $booking): void
    {
        $productId = $this->getProductIdForResource($booking->getResourceId());

        $this->db->insert('oxorderarticles', [
            'OXID' => $this->generateOxid(),
            'OXORDERID' => $orderId,
            'OXARTID' => $productId->toString(),
            'OXARTNUM' => $booking->getResource()->getSku(),
            'OXTITLE' => $this->buildOrderArticleTitle($booking),
            'OXSHORTDESC' => $this->buildOrderArticleDescription($booking),
            'OXAMOUNT' => $booking->getQuantity(),
            'OXBPRICE' => $booking->getPrice()->getAmount(),
            'OXVAT' => $booking->getTaxRate(),
            'OXTIMESTAMP' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    private function buildOrderArticleTitle(Booking $booking): string
    {
        return sprintf(
            '%s - %s to %s',
            $booking->getResource()->getName(),
            $booking->getTimeSlot()->getStart()->format('Y-m-d H:i'),
            $booking->getTimeSlot()->getEnd()->format('Y-m-d H:i')
        );
    }

    public function updateOrderStatus(OrderId $orderId, BookingStatus $newStatus): void
    {
        $oxidStatus = match ($newStatus->value) {
            'confirmed' => 'OK',
            'cancelled' => 'CANCEL',
            'no_show' => 'ERROR',
            default => 'NOT_FINISHED',
        };

        $this->db->update('oxorder', [
            'OXTRANSSTATUS' => $oxidStatus,
        ], [
            'OXID' => $orderId->toString(),
        ]);
    }

    public function cancelOrder(OrderId $orderId, RefundAmount $refundAmount): void
    {
        // Update order status
        $this->db->update('oxorder', [
            'OXTRANSSTATUS' => 'CANCEL',
            'OXCANCELLED' => (new \DateTime())->format('Y-m-d H:i:s'),
            'OXREFUNDAMOUNT' => $refundAmount->getAmount(),
        ], [
            'OXID' => $orderId->toString(),
        ]);

        // Refund is handled by Payment Component, not OXID
        // We just record the cancellation in OXID
    }

    private function generateOxid(): string
    {
        return md5(uniqid('', true));
    }

    private function generateOrderNumber(): int
    {
        // OXID order number generation logic
        return (int) $this->db->fetchOne(
            "SELECT MAX(OXORDERNR) + 1 FROM oxorder WHERE OXSHOPID = ?",
            [$this->shopId]
        ) ?? 1;
    }
}
```

---

### Shopware 6 Adapter

```php
<?php

namespace BookingPlatform\Adapter\Ecommerce\Shopware;

use BookingPlatform\Adapter\Ecommerce\EcommerceAdapterInterface;
use BookingPlatform\Domain\Model\Booking;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;

class ShopwareAdapter implements EcommerceAdapterInterface
{
    private EntityRepositoryInterface $productRepository;
    private EntityRepositoryInterface $orderRepository;
    private Context $context;

    public function syncResourceToProduct(BookableResource $resource): ProductId
    {
        $productId = $this->findExistingProduct($resource->getId());

        $productData = [
            'id' => $productId?->toString() ?? $this->generateUuid(),
            'productNumber' => $resource->getSku(),
            'name' => $resource->getName(),
            'description' => $resource->getLongDescription(),
            'active' => true,
            'price' => [
                [
                    'currencyId' => $this->getDefaultCurrencyId(),
                    'gross' => $resource->getBasePrice()->getAmount(),
                    'net' => $resource->getBasePrice()->getAmount() / 1.19,
                    'linked' => true,
                ]
            ],
            'stock' => 999, // Dummy stock (managed by Blockchain Inventory)
            'isCloseout' => false, // Don't check stock
            'customFields' => [
                'booking_platform_resource_id' => $resource->getId()->toString(),
                'booking_platform_enabled' => true,
            ],
        ];

        if ($productId === null) {
            // Create
            $this->productRepository->create([$productData], $this->context);
            $productId = new ProductId($productData['id']);
        } else {
            // Update
            $this->productRepository->update([$productData], $this->context);
        }

        return $productId;
    }

    public function createOrderFromBooking(Booking $booking): OrderId
    {
        $orderId = $this->generateUuid();

        $orderData = [
            'id' => $orderId,
            'orderNumber' => $this->generateOrderNumber(),
            'orderDateTime' => new \DateTime(),
            'currencyId' => $this->getDefaultCurrencyId(),
            'customerId' => $booking->getCustomerId()->toString(),
            'salesChannelId' => $this->getSalesChannelId(),
            'billingAddressId' => $this->getBillingAddressId($booking),
            'stateId' => $this->getOrderStateId('completed'),
            'price' => new CartPrice(
                $booking->getPrice()->getAmount(),
                $booking->getPrice()->getAmount(),
                $booking->getPrice()->getAmount(),
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
                CartPrice::TAX_STATE_GROSS
            ),
            'amountTotal' => $booking->getPrice()->getAmount(),
            'lineItems' => [
                [
                    'id' => $this->generateUuid(),
                    'productId' => $this->getProductIdForResource($booking->getResourceId())->toString(),
                    'label' => $this->buildOrderItemLabel($booking),
                    'quantity' => $booking->getQuantity(),
                    'unitPrice' => $booking->getPrice()->getAmount(),
                    'totalPrice' => $booking->getPrice()->getAmount(),
                    'type' => 'product',
                    'payload' => [
                        'booking_id' => $booking->getId()->toString(),
                        'time_slot_start' => $booking->getTimeSlot()->getStart()->format('c'),
                        'time_slot_end' => $booking->getTimeSlot()->getEnd()->format('c'),
                    ],
                ]
            ],
            'customFields' => [
                'booking_platform_booking_id' => $booking->getId()->toString(),
                'booking_platform_payment_contract_id' => $booking->getPaymentContractId()->toString(),
            ],
        ];

        $this->orderRepository->create([$orderData], $this->context);

        return new OrderId($orderId);
    }

    public function updateOrderStatus(OrderId $orderId, BookingStatus $newStatus): void
    {
        $stateId = match ($newStatus->value) {
            'confirmed' => $this->getOrderStateId('completed'),
            'cancelled' => $this->getOrderStateId('cancelled'),
            'no_show' => $this->getOrderStateId('failed'),
            default => $this->getOrderStateId('open'),
        };

        $this->orderRepository->update([
            [
                'id' => $orderId->toString(),
                'stateId' => $stateId,
            ]
        ], $this->context);
    }

    private function buildOrderItemLabel(Booking $booking): string
    {
        return sprintf(
            '%s | %s - %s',
            $booking->getResource()->getName(),
            $booking->getTimeSlot()->getStart()->format('d.m.Y H:i'),
            $booking->getTimeSlot()->getEnd()->format('H:i')
        );
    }

    private function generateUuid(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->getHex();
    }
}
```

---

## Standalone Mode (No E-Commerce)

When the booking platform runs without any e-commerce backend:

```php
<?php

namespace BookingPlatform\Adapter\Ecommerce;

class StandaloneAdapter implements EcommerceAdapterInterface
{
    /**
     * In standalone mode, we don't sync to any e-commerce
     */
    public function syncResourceToProduct(BookableResource $resource): ProductId
    {
        // No-op: Resources are managed internally
        return new ProductId($resource->getId()->toString());
    }

    /**
     * In standalone mode, we don't create external orders
     */
    public function createOrderFromBooking(Booking $booking): OrderId
    {
        // No-op: Bookings are orders themselves
        // We may still want to generate an internal "order ID" for invoicing
        return new OrderId($booking->getId()->toString());
    }

    /**
     * No external order to update
     */
    public function updateOrderStatus(OrderId $orderId, BookingStatus $newStatus): void
    {
        // No-op
    }

    public function getCustomer(CustomerId $customerId): Customer
    {
        // Fetch from internal booking_customers table
        return $this->customerRepository->findById($customerId);
    }

    public function generateInvoice(OrderId $orderId): InvoiceId
    {
        // Use internal invoice generator
        return $this->invoiceService->generateInvoice($orderId);
    }
}
```

**Key Points:**
- All adapter methods are no-ops or use internal services
- Booking platform manages everything: products, customers, orders, invoices
- Still uses Payment Component v4.0 and Blockchain Inventory Manager

---

## Migration Scenarios

### Scenario 1: Existing OXID Shop Wants Booking Functionality

**Before:**
- OXID eShop with products, customers, orders
- No booking functionality

**After:**
- Install Booking Platform module for OXID
- Configure OXID Adapter
- Create bookable resources → synced to OXID products
- Customers book using existing OXID account
- Orders created in OXID after booking confirmed

**Migration Steps:**

```sql
-- 1. Add custom fields to OXID tables
ALTER TABLE oxarticles
    ADD COLUMN OXBOOKABLERESOURCEID CHAR(32),
    ADD COLUMN OXISBOOKABLE TINYINT(1) DEFAULT 0;

ALTER TABLE oxorder
    ADD COLUMN OXBOOKINGID CHAR(32),
    ADD COLUMN OXPAYMENTCONTRACTID CHAR(32);

-- 2. Create mapping table
CREATE TABLE booking_resource_product_mapping (
    resource_id CHAR(32) PRIMARY KEY,
    product_id CHAR(32) NOT NULL,
    ecommerce_platform VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_product (product_id)
);
```

```php
// 3. Sync existing products to bookable resources (optional)
$products = $this->getOxidBookableProducts();

foreach ($products as $product) {
    $resource = new BookableResource(
        id: ResourceId::generate(),
        name: $product['OXTITLE'],
        sku: $product['OXARTNUM'],
        basePrice: Money::fromAmount($product['OXPRICE'], 'EUR'),
        // ... other fields
    );

    $this->bookingPlatform->createResource($resource);
    $this->adapter->storeMappingResourceToProduct($resource->getId(), $product['OXID']);
}
```

---

### Scenario 2: Standalone Booking Platform Migrates to Shopware

**Before:**
- Booking Platform running standalone
- Own customer database, own invoices

**After:**
- Install Shopware 6
- Configure Shopware Adapter
- Migrate customers to Shopware
- Future bookings create orders in Shopware

**Migration Steps:**

```php
// 1. Migrate customers
$customers = $this->bookingPlatform->getAllCustomers();

foreach ($customers as $customer) {
    $shopwareCustomerId = $this->shopwareAdapter->createCustomer([
        'email' => $customer->getEmail(),
        'firstName' => $customer->getFirstName(),
        'lastName' => $customer->getLastName(),
        // ... other fields
    ]);

    // Store mapping
    $this->db->update('booking_customers', [
        'shopware_customer_id' => $shopwareCustomerId,
    ], [
        'id' => $customer->getId(),
    ]);
}

// 2. Sync resources to Shopware products
$resources = $this->bookingPlatform->getAllResources();

foreach ($resources as $resource) {
    $this->shopwareAdapter->syncResourceToProduct($resource);
}

// 3. Switch from StandaloneAdapter to ShopwareAdapter
$this->config->set('ecommerce.adapter', 'shopware');

// 4. Historical bookings remain standalone
// 5. New bookings create orders in Shopware
```

---

## Summary

### E-Commerce Backend Integration Responsibilities

| Responsibility | Handled By |
|----------------|------------|
| **Booking Logic** | Booking Platform Core |
| **Availability Management** | Blockchain Inventory Manager |
| **Payment Processing** | Payment Component v4.0 |
| **Product Catalog** | E-commerce (via adapter) OR Booking Platform (standalone) |
| **Customer Management** | E-commerce (via adapter) OR Booking Platform (standalone) |
| **Order Management** | E-commerce (via adapter) OR Booking Platform (standalone) |
| **Invoicing** | E-commerce (via adapter) OR Booking Platform (standalone) |

### Key Architectural Principles

1. **Booking Platform is the source of truth** for bookings, not e-commerce
2. **E-commerce receives final orders** after bookings are confirmed
3. **Payment Component handles all payments**, not e-commerce gateways
4. **Blockchain Inventory manages stock**, not e-commerce inventory
5. **Adapter pattern enables platform independence**
6. **Standalone mode is fully supported** (no e-commerce needed)

---

**Next Steps:**
1. Choose adapter: OXID, Shopware, Magento, WooCommerce, or Standalone
2. Configure adapter with database credentials
3. Sync bookable resources to products (if using e-commerce)
4. Test booking flow end-to-end
5. Verify order creation in e-commerce (if applicable)

---

**Related Documents:**
- [01-DETAILED-ARCHITECTURE.md](01-DETAILED-ARCHITECTURE.md) - Full architecture overview
- [00-OVERVIEW.md](../00-OVERVIEW.md) - Executive summary
- [README.md](../README.md) - Documentation index
