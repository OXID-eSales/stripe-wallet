# Booking Platform - Architecture Concept

**Version:** 3.1.0 - OXID EE Federation Architecture
**Date:** 2025-10-22
**Status:** 🎯 Architectural Foundation

---

## What Is This?

**The Booking Platform is a centralized booking management hub** that runs as an **OXID EE module** with Payment Component v4.0 and **federates/manages multiple e-commerce shops** across different platforms and locations.

### ❌ Wrong Understanding (v1.0 & v2.0)

```
v1.0: Booking Platform (standalone) → optional e-commerce integration
v2.0: Single e-commerce shop + Booking Module = Booking Platform
```

### ✅ Correct Understanding (v3.1 - OXID EE Federation Architecture)

```
                 CENTRAL HUB (OXID EE 8.x)
                 + OXID Multi-Shop Feature
                         ↓
            + Booking Platform Module
            + Payment Component v4.0
            + Blockchain Inventory Manager
                         ↓
        ┌────────────────┼────────────────┐
        ↓                ↓                ↓
   OXID SUBSHOP 1   OXID SUBSHOP 2   3RD-PARTY SHOP
   Amsterdam (NL)   Paris (FR)       Berlin (Magento)
   Built-in         Built-in         Via Adapter
```

### Real-World Use Case: Travel Operator Federation

**Scenario:** A travel operator has **20 web shops** for their agencies throughout Europe:
- ✅ **OXID EE subshops** - Most agencies use OXID EE multi-shop (Amsterdam, Paris, London, etc.)
- ✅ **3rd-party legacy platforms** - Some agencies have Magento 1.x or WooCommerce (acquired companies)
- ✅ Different languages, currencies, branding per agency
- ✅ Each shop serves a local market with local customers

**Problem:**
- ❌ Inventory scattered across 20 shops
- ❌ No centralized booking management
- ❌ Risk of double-booking between shops
- ❌ No unified availability view
- ❌ Payment processing fragmented

**Solution: OXID EE Booking Platform Hub**
- ✅ **Central OXID EE Hub** with Booking Module runs the master booking system
- ✅ **OXID Multi-Shop Feature** - Native OXID subshops get direct integration
- ✅ **3rd-Party Adapters** - Connect legacy Magento/WooCommerce shops via federation API
- ✅ **Unified Inventory** - Blockchain Inventory Manager prevents double-booking
- ✅ **Unified Payment** - Payment Component v4.0 handles all shops (multi-currency)
- ✅ **Synchronous Operations** - All shops work in real-time
- ✅ **Central Management** - Single OXID admin panel controls all 20 shops

---

## Architecture Philosophy

### The OXID EE Hub-and-Spoke Federation Approach

**The booking platform is an OXID EE MODULE that:**

1. **Runs on OXID EE 8.x** with Payment Component v4.0 as the master booking system
2. **Leverages OXID Multi-Shop** - Native subshops get seamless booking integration
3. **Federates 3rd-party platforms** via REST API Adapters (Magento, WooCommerce, custom platforms)
4. **Manages unified inventory** via Blockchain Inventory Manager (no double-booking)
5. **Centralizes payment processing** via Payment Component v4.0 smart contracts
6. **Provides single OXID admin interface** to control all federated shops
7. **Synchronizes in real-time** - booking on Shop A immediately reflected on Shops B-Z

### Why OXID EE as the Hub?

**OXID EE provides enterprise features required for booking hub:**
- ✅ **Native Multi-Shop** - Manage multiple shops from single installation
- ✅ **Enterprise B2B/B2C** - Customer groups, quotes, contracts, credit limits
- ✅ **Shared Database** - All OXID subshops share tables (oxarticles, oxuser, oxorder)
- ✅ **Payment Component v4.0 Integration** - Already integrated in OXID EE
- ✅ **GraphQL & REST APIs** - Built-in APIs for external integrations
- ✅ **MCP Server** - Model Context Protocol for AI integrations
- ✅ **ERP Integration** - SAP, Dynamics connectors
- ✅ **Extensibility** - Module system for custom functionality

### Two Integration Paths

#### Path 1: OXID EE Subshops (Native Integration)

**For agencies using OXID EE:**
- ✅ Native multi-shop feature (oxshops table)
- ✅ Shared customer database (oxuser)
- ✅ Shared product catalog (oxarticles)
- ✅ Direct booking module access (no adapter needed)
- ✅ Same admin panel
- ✅ Real-time availability (same database)

#### Path 2: 3rd-Party E-Commerce (Federation via Adapter)

**For agencies with Magento, WooCommerce, or custom platforms:**
- ✅ Lightweight adapter plugin installed on 3rd-party shop
- ✅ REST API client connects to OXID hub
- ✅ WebSocket client for real-time sync
- ✅ Local customer database (not shared)
- ✅ Booking widget embedded in storefront
- ✅ Proxy bookings to OXID hub

### Why This Hybrid Approach?

**Travel operators & enterprise merchants have:**
- 🌍 **Multiple agencies** - Some on OXID EE, some on legacy platforms
- 🏢 **Acquisitions** - Acquired companies bring Magento/WooCommerce shops
- 💰 **Budget constraints** - Cannot migrate all shops to OXID overnight
- 🔧 **Custom features** - 3rd-party shops have unique customizations
- 🌐 **Local requirements** - Different languages, currencies, payment gateways

**They need:**
- 📅 **Unified booking management** - One inventory, one availability calendar
- 🚫 **No double-booking** - Booking on OXID subshop blocks 3rd-party shops too
- 💳 **Centralized payment** - Payment Component v4.0 in OXID hub
- 🎛️ **Single admin panel** - OXID EE admin manages all shops (native + federated)
- 📊 **Real-time sync** - Booking anywhere instantly visible everywhere
- 🔌 **Non-invasive** - Don't force migration, provide integration path

**Instead of:**
- ❌ Forcing migration of all shops to OXID (millions in cost, high risk)
- ❌ Migrating customer data from 3rd-party platforms
- ❌ Rebuilding custom features
- ❌ Risk of double-booking across shops
- ❌ Fragmented inventory management

---

## Federation Architecture Components

### Central Hub (OXID EE 8.x with Booking Module)

**The central hub is the MASTER booking system built on OXID EE:**

#### What OXID EE Hub Provides

**OXID EE Core Features:**
- ✅ **Multi-Shop Management** - Native OXID subshops (oxshops table)
- ✅ **Shared Database** - oxarticles, oxuser, oxorder shared across subshops
- ✅ **Central Admin Panel** - Manage all OXID subshops + federated shops
- ✅ **B2B/B2C Features** - Customer groups, quotes, contracts, credit limits
- ✅ **ERP Integration** - SAP, Dynamics connectors
- ✅ **GraphQL & REST APIs** - Built-in APIs
- ✅ **MCP Server** - Model Context Protocol for AI

**Booking Module (Installed on OXID EE):**
- ✅ **Booking Orchestration** - Coordinates bookings across all shops
- ✅ **Inventory Manager** - Blockchain Inventory Manager integration
- ✅ **Availability Calendar** - Unified calendar for all shops
- ✅ **Federation Service** - Manages OXID subshops + 3rd-party shops
- ✅ **WebSocket Server** - Real-time sync to all shops
- ✅ **Shop Registry** - Tracks all registered shops (OXID + 3rd-party)

**Payment Component v4.0 (Already in OXID EE):**
- ✅ **Smart Payment Contracts** - Centralized payment processing
- ✅ **Multi-Currency Gateway** - EUR, USD, GBP, CHF, etc.
- ✅ **Multi-Provider** - Stripe, PayPal, local gateways
- ✅ **Fraud Detection** - Across all shops
- ✅ **PCI-DSS Compliance**
- ✅ **Automatic Refunds**

**Blockchain Inventory Manager:**
- ✅ **Single Source of Truth** - Master inventory ledger
- ✅ **Atomic Locking** - No double-booking across 20 shops
- ✅ **Distributed Consensus** - Hyperledger Fabric
- ✅ **Real-time Availability** - Sub-second inventory updates

**Infrastructure:**
- ✅ **OXID Database** - MySQL/MariaDB (master booking tables)
- ✅ **Redis Cache** - Shared cache for all shops
- ✅ **RabbitMQ** - Message queue for async sync
- ✅ **Monitoring** - Health checks for federated shops

### OXID EE Subshops (Native Multi-Shop)

**OXID subshops get native integration (no adapter needed):**

**Native Features:**
- ✅ **Same Installation** - All subshops share same OXID codebase
- ✅ **Shared Customers** - oxuser table shared across subshops
- ✅ **Shared Products** - oxarticles table, bookable resources
- ✅ **Shared Bookings** - osc_booking_bookings table accessible to all
- ✅ **Same Admin** - Manage from OXID admin panel
- ✅ **Real-time Availability** - Direct database access (no sync delay)
- ✅ **Multi-Language** - OXID translation system
- ✅ **Multi-Currency** - OXID currency system

**Example OXID Subshops:**
- Shop #1: Amsterdam (shop_id: 1, locale: nl_NL, currency: EUR)
- Shop #2: Paris (shop_id: 2, locale: fr_FR, currency: EUR)
- Shop #3: London (shop_id: 3, locale: en_GB, currency: GBP)
- Shop #4: Zurich (shop_id: 4, locale: de_CH, currency: CHF)

### 3rd-Party E-Commerce Shops (Federated via Adapter)

**3rd-party platforms connect via lightweight adapter plugin:**

**Supported 3rd-Party Platforms:**
- ✅ **Magento 1.9.x, 2.x** - For acquired companies
- ✅ **WooCommerce 3.x-8.x** - For WordPress-based shops
- ✅ **Custom Platforms** - Any platform with PHP/REST capability

**Adapter Components (Installed on Each 3rd-Party Shop):**
- ✅ **REST API Client** - Connects to OXID hub federation API
- ✅ **WebSocket Client** - Receives real-time updates
- ✅ **Booking Widget** - JavaScript widget embedded in storefront
- ✅ **Webhook Receiver** - Handles BookingCreated, InventoryChanged events
- ✅ **Local Cache DB** - Read-only cache synced from hub
- ✅ **Configuration** - Hub URL, shop ID, API key

**3rd-Party Shop Characteristics:**
- ✅ **Local Frontend** - Own branded storefront (unchanged)
- ✅ **Local Customers** - Own customer database (not shared with OXID)
- ✅ **Proxy Bookings** - Send booking requests to OXID hub
- ✅ **Real-time Sync** - Receive availability updates via WebSocket
- ✅ **Local Orders** - Create local order after booking confirmed by hub

**Example 3rd-Party Shops:**
- Shop #15: Berlin (Magento 1.9.4, acquired in 2020)
- Shop #17: Rome (WooCommerce 8.x, franchise partner)
- Shop #19: Barcelona (Custom PHP platform, legacy system)

---

## Federation Architecture - OXID EE Hub with Multi-Shop + 3rd-Party Integration

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                     CENTRAL HUB (OXID EE 8.x)                                │
│                     Single Installation, Multi-Shop Enabled                  │
│ ┌──────────────────────────────────────────────────────────────────────────┐ │
│ │         Booking Platform Module (Master)                                 │ │
│ │  • Booking Orchestrator                                                  │ │
│ │  • Inventory Manager (Blockchain)                                        │ │
│ │  • Availability Calendar (Unified)                                       │ │
│ │  • Federation Service (OXID subshops + 3rd-party)                        │ │
│ │  • WebSocket Server (real-time sync)                                     │ │
│ │  • Shop Registry (oxshops + external shops)                              │ │
│ └──────────────────────────────────────────────────────────────────────────┘ │
│ ┌──────────────────────────────────────────────────────────────────────────┐ │
│ │         Payment Component v4.0 (Already Integrated)                      │ │
│ │  • Smart Payment Contracts                                               │ │
│ │  • Multi-Currency Gateway (EUR, USD, GBP, CHF, etc.)                     │ │
│ │  • Fraud Detection (all shops)                                           │ │
│ └──────────────────────────────────────────────────────────────────────────┘ │
│ ┌──────────────────────────────────────────────────────────────────────────┐ │
│ │         Blockchain Inventory Manager                                     │ │
│ │  • Single source of truth for inventory                                  │ │
│ │  • Atomic locking (no double-booking across all shops)                   │ │
│ └──────────────────────────────────────────────────────────────────────────┘ │
│ ┌──────────────────────────────────────────────────────────────────────────┐ │
│ │         OXID EE Shared Database                                          │ │
│ │  • oxshops (subshop registry)                                            │ │
│ │  • oxarticles (shared products)                                          │ │
│ │  • oxuser (shared customers - OXID subshops only)                        │ │
│ │  • oxorder (shared orders)                                               │ │
│ │  • osc_booking_bookings (master booking table)                           │ │
│ │  • osc_federation_shops (3rd-party shop registry)                        │ │
│ └──────────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────────┘
                                         │
            ┌────────────────────────────┼────────────────────────────┐
            │ OXID Native Multi-Shop     │ 3rd-Party Federation       │
            ▼                            ▼                            ▼
┌────────────────────┐  ┌────────────────────┐  ┌────────────────────────┐
│ OXID SUBSHOP #1    │  │ OXID SUBSHOP #2    │  │ 3RD-PARTY SHOP         │
│ Amsterdam Agency   │  │ Paris Agency       │  │ Berlin Agency          │
├────────────────────┤  ├────────────────────┤  ├────────────────────────┤
│ Integration:       │  │ Integration:       │  │ Platform:              │
│  NATIVE            │  │  NATIVE            │  │  Magento 1.9.4         │
│  (no adapter)      │  │  (no adapter)      │  │  (acquired 2020)       │
│                    │  │                    │  │                        │
│ OXID Shop ID: 1    │  │ OXID Shop ID: 2    │  │ Integration:           │
│ Domain:            │  │ Domain:            │  │  FEDERATED             │
│  amsterdam.tr.com  │  │  paris.tr.com      │  │  (via adapter)         │
│                    │  │                    │  │                        │
│ Frontend:          │  │ Frontend:          │  │ Frontend:              │
│  Dutch (nl_NL)     │  │  French (fr_FR)    │  │  German (de_DE)        │
│  EUR currency      │  │  EUR currency      │  │  EUR currency          │
│  Custom theme      │  │  Custom theme      │  │  Magento theme         │
│                    │  │                    │  │                        │
│ Database:          │  │ Database:          │  │ Database:              │
│  SHARED oxuser     │  │  SHARED oxuser     │  │  LOCAL (not shared)    │
│  SHARED oxarticles │  │  SHARED oxarticles │  │  5,000 local customers │
│  SHARED bookings   │  │  SHARED bookings   │  │                        │
│  8K customers      │  │  12K customers     │  │ Adapter Installed:     │
│                    │  │                    │  │  MagentoAdapter        │
│ Availability:      │  │ Availability:      │  │  • REST Client         │
│  REAL-TIME         │  │  REAL-TIME         │  │  • Booking Widget      │
│  (same DB)         │  │  (same DB)         │  │  • WebSocket Client    │
│                    │  │                    │  │  • Local Cache DB      │
└────────────────────┘  └────────────────────┘  └────────────────────────┘
         │                       │                         │
         └───────────────────────┴─────────────────────────┘
                                 │
                  All shops sync in real-time
                  (OXID subshops: instant, 3rd-party: WebSocket)

         Example Flow - OXID Subshop Booking:
         1. Customer on OXID Subshop #1 (Amsterdam) books Room 101
         2. Booking module creates booking directly (same DB)
         3. Blockchain locks inventory
         4. Payment Component v4.0 processes payment
         5. Booking status: CONFIRMED
         6. OXID Subshop #2 (Paris) sees unavailable instantly (same DB)
         7. WebSocket broadcasts to 3rd-party shops (Berlin Magento)
         8. Room 101 unavailable everywhere

         Example Flow - 3rd-Party Shop Booking:
         1. Customer on Berlin (Magento) books Room 101
         2. MagentoAdapter sends POST /bookings/create to OXID hub
         3. OXID hub locks inventory, processes payment
         4. OXID hub creates booking (CONFIRMED)
         5. OXID Subshops #1-2 see unavailable instantly (same DB)
         6. WebSocket broadcasts to other 3rd-party shops
         7. Room 101 unavailable everywhere
```

---

## Key Architectural Decisions

### 1. OXID EE as Central Hub

**Why OXID EE is the hub (not Magento or WooCommerce):**

- ✅ **Native Multi-Shop** - Manage multiple subshops from single installation (oxshops table)
- ✅ **Enterprise B2B/B2C** - Customer groups, quotes, contracts, credit limits
- ✅ **Shared Database** - All OXID subshops share oxarticles, oxuser, oxorder
- ✅ **Payment Component v4.0** - Already integrated in OXID EE
- ✅ **GraphQL & REST APIs** - Built-in federation APIs
- ✅ **MCP Server** - Model Context Protocol for AI integrations
- ✅ **Module System** - Extensible architecture for booking module
- ✅ **ERP Integration** - SAP, Dynamics connectors

**Single source of truth:**
- ✅ All bookings in OXID database (osc_booking_bookings)
- ✅ Blockchain Inventory Manager prevents double-booking
- ✅ Payment Component v4.0 processes all payments (multi-currency)
- ✅ OXID admin panel manages all shops (OXID subshops + 3rd-party)

### 2. Dual Integration Strategy

**Path A: OXID EE Subshops (Native, Preferred)**

**For agencies on OXID EE:**
- ✅ **Zero adapter needed** - Native multi-shop feature
- ✅ **Instant availability** - Shared database (no sync delay)
- ✅ **Shared customers** - oxuser table shared across subshops
- ✅ **Shared products** - oxarticles table (bookable resources)
- ✅ **Same admin** - OXID admin panel
- ✅ **Same codebase** - Booking module installed once, works for all subshops

**Path B: 3rd-Party E-Commerce (Federated, For Legacy)**

**For acquired companies or franchise partners with Magento/WooCommerce:**
- ✅ **Lightweight adapter** - PHP plugin installed on 3rd-party shop
- ✅ **REST API integration** - Connects to OXID hub federation API
- ✅ **WebSocket sync** - Real-time availability updates
- ✅ **Local customers** - Keep existing customer database (not shared)
- ✅ **Proxy bookings** - Send booking requests to OXID hub
- ✅ **Non-invasive** - No migration required, shop stays as-is

### 3. Platform Adapter Layer (3rd-Party Integration Only)

**Adapter interface for 3rd-party shops:**

```php
interface ThirdPartyShopAdapterInterface
{
    // Shop Registration (once during setup)
    public function registerShop(ShopConfig $config): ShopId;
    public function authenticateShop(ShopId $id, string $apiKey): bool;

    // Booking Operations (3rd-Party Shop → OXID Hub)
    public function createBookingRequest(BookingRequest $request): BookingId;
    public function cancelBookingRequest(BookingId $id, string $reason): void;
    public function getBookingStatus(BookingId $id): BookingStatus;

    // Real-Time Sync (OXID Hub → 3rd-Party Shop via WebSocket)
    public function onBookingCreated(Booking $booking): void;
    public function onBookingCancelled(BookingId $id): void;
    public function onInventoryChanged(ResourceId $id, int $available): void;
    public function onPriceChanged(ResourceId $id, Money $price): void;

    // Local Integration (3rd-party shop creates local order)
    public function getLocalCustomer(CustomerId $id): Customer;
    public function createLocalOrder(Booking $booking): OrderId;

    // Health Monitoring
    public function ping(): HealthStatus;
    public function getShopInfo(): ShopInfo; // platform, version, locale, currency
}
```

**Adapter Implementations (3rd-Party Only):**
- `MagentoFederatedAdapter` - Magento 1.9.x, 2.x (PHP extension + REST client)
- `WooCommerceFederatedAdapter` - WooCommerce 3.x-8.x (WordPress plugin + REST client)
- `CustomPlatformAdapter` - Custom PHP platforms (generic REST client)

**Note:** OXID subshops do NOT need adapters - they access booking module directly.

### 4. Installation Architecture

**Two types of installation:**

#### A) Central Hub Installation (OXID EE 8.x)

```
central-hub.travel-operator.com (OXID EE 8.x)
├── source/
│   └── modules/
│       └── osc/
│           └── booking-platform-hub/    ← MASTER MODULE
│               ├── metadata.php
│               ├── composer.json
│               ├── Core/
│               │   ├── Domain/
│               │   │   ├── Model/
│               │   │   │   ├── Booking.php
│               │   │   │   ├── BookableResource.php
│               │   │   │   ├── TimeSlot.php
│               │   │   │   └── FederatedShop.php    ← NEW!
│               │   │   └── Repository/
│               │   ├── Application/
│               │   │   ├── UseCase/
│               │   │   │   ├── CreateBooking.php
│               │   │   │   ├── FederateShop.php    ← NEW!
│               │   │   │   └── SyncToShops.php      ← NEW!
│               │   └── Infrastructure/
│               │       ├── Federation/
│               │       │   ├── FederationService.php
│               │       │   ├── WebSocketServer.php
│               │       │   └── ShopRegistry.php
│               │       └── Integration/
│               │           ├── PaymentComponent/
│               │           └── BlockchainInventory/
│               ├── Controller/
│               │   └── Admin/
│               │       └── FederationController.php
│               └── views/admin/
│                   └── federation_dashboard.tpl
```

#### B) Legacy Shop Installation (Adapter Plugin)

```
amsterdam-shop.travel-operator.com (Magento 1.9.4)
├── app/
│   └── code/
│       └── local/
│           └── Osc/
│               └── BookingFederation/    ← ADAPTER PLUGIN
│                   ├── etc/
│                   │   └── config.xml
│                   ├── Model/
│                   │   ├── Adapter.php
│                   │   ├── WebhookReceiver.php
│                   │   └── RestClient.php
│                   ├── Block/
│                   │   └── BookingWidget.php
│                   └── controllers/
│                       └── WebhookController.php

paris-shop.travel-operator.com (OXID 6.2)
├── source/
│   └── modules/
│       └── osc/
│           └── booking-federation/    ← ADAPTER MODULE
│               ├── metadata.php
│               ├── Model/
│               │   ├── Adapter.php
│               │   ├── WebhookReceiver.php
│               │   └── RestClient.php
│               ├── views/
│               │   └── booking_widget.tpl
│               └── translations/

berlin-shop.travel-operator.com (Shopware 5.7)
├── custom/
│   └── plugins/
│       └── OscBookingFederation/    ← ADAPTER PLUGIN
│           ├── Bootstrap.php
│           ├── Models/
│           │   ├── Adapter.php
│           │   ├── WebhookReceiver.php
│           │   └── RestClient.php
│           └── Views/
│               └── booking_widget.tpl
```

### 5. Database Architecture

**Two types of databases:**

#### A) Central Hub Database (Master)

```sql
-- CENTRAL HUB DATABASE (single source of truth)

-- Federated shops registry
CREATE TABLE osc_federation_shops (
    OXID CHAR(32) PRIMARY KEY,
    OXNAME VARCHAR(255) NOT NULL,        -- "Amsterdam Agency"
    OXPLATFORM VARCHAR(64) NOT NULL,     -- "magento1", "oxid6", "shopware5"
    OXVERSION VARCHAR(32) NOT NULL,      -- "1.9.4", "6.2.0", "5.7.0"
    OXURL VARCHAR(512) NOT NULL,         -- "https://amsterdam-shop.travel-operator.com"
    OXAPIKEY CHAR(64) NOT NULL,          -- API key for authentication
    OXLOCALE VARCHAR(10) NOT NULL,       -- "nl_NL", "fr_FR", "de_DE"
    OXCURRENCY VARCHAR(3) NOT NULL,      -- "EUR", "USD", "GBP"
    OXSTATUS VARCHAR(32) NOT NULL,       -- "active", "inactive", "error"
    OXLASTSYNC DATETIME,                 -- Last successful sync
    OXACTIVE TINYINT(1) DEFAULT 1
);

-- Core booking tables (MASTER)
CREATE TABLE osc_booking_bookings (
    OXID CHAR(32) PRIMARY KEY,
    OXSHOPID CHAR(32) NOT NULL,          -- Which shop created this booking
    OXUSERID VARCHAR(255) NOT NULL,      -- Customer ID (may differ per shop)
    OXUSEREMAIL VARCHAR(255) NOT NULL,   -- Email for identification
    OXRESOURCEID CHAR(32) NOT NULL,
    OXTIMESLOTID CHAR(32) NOT NULL,
    OXPAYMENTCONTRACTID CHAR(32) NOT NULL,
    OXINVENTORYLOCKID CHAR(32) NOT NULL,
    OXSTATUS VARCHAR(32) NOT NULL,
    OXQUANTITY INT NOT NULL,
    OXTOTALPRICE DECIMAL(10,2) NOT NULL,
    OXCURRENCY VARCHAR(3) NOT NULL,
    OXCREATEDAT DATETIME NOT NULL,
    FOREIGN KEY (OXSHOPID) REFERENCES osc_federation_shops(OXID),
    FOREIGN KEY (OXRESOURCEID) REFERENCES osc_booking_resources(OXID)
);

-- Bookable resources (MASTER)
CREATE TABLE osc_booking_resources (
    OXID CHAR(32) PRIMARY KEY,
    OXTYPE VARCHAR(32) NOT NULL,      -- 'hotel_room', 'event_ticket', etc.
    OXNAME VARCHAR(255) NOT NULL,     -- "Hotel Room 101"
    OXCAPACITY INT NOT NULL,
    OXDURATION INT NOT NULL,          -- minutes
    OXBASEPRICE DECIMAL(10,2) NOT NULL,
    OXCURRENCY VARCHAR(3) NOT NULL,
    OXISBOOKABLE TINYINT(1) DEFAULT 1
);

-- Time slots (MASTER)
CREATE TABLE osc_booking_timeslots (
    OXID CHAR(32) PRIMARY KEY,
    OXRESOURCEID CHAR(32) NOT NULL,
    OXSTARTTIME DATETIME NOT NULL,
    OXENDTIME DATETIME NOT NULL,
    OXCAPACITY INT NOT NULL,
    OXBOOKED INT DEFAULT 0,
    OXAVAILABLE INT GENERATED ALWAYS AS (OXCAPACITY - OXBOOKED),
    FOREIGN KEY (OXRESOURCEID) REFERENCES osc_booking_resources(OXID),
    INDEX idx_availability (OXRESOURCEID, OXSTARTTIME, OXAVAILABLE)
);

-- Sync tracking
CREATE TABLE osc_federation_sync_log (
    OXID CHAR(32) PRIMARY KEY,
    OXSHOPID CHAR(32) NOT NULL,
    OXBOOKINGID CHAR(32) NOT NULL,
    OXACTION VARCHAR(32) NOT NULL,    -- "created", "updated", "cancelled"
    OXSTATUS VARCHAR(32) NOT NULL,    -- "pending", "success", "failed"
    OXERROR TEXT NULL,
    OXSYNCEDAT DATETIME,
    FOREIGN KEY (OXSHOPID) REFERENCES osc_federation_shops(OXID)
);
```

#### B) Legacy Shop Database (Local Cache)

```sql
-- LEGACY SHOP DATABASE (read-only cache, synced from hub)

-- Cached bookings (synced from hub)
CREATE TABLE osc_booking_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hub_booking_id VARCHAR(32) NOT NULL,  -- Links to hub
    resource_id VARCHAR(32) NOT NULL,
    timeslot_id VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL,
    quantity INT NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL,
    synced_at DATETIME NOT NULL,
    UNIQUE KEY (hub_booking_id)
);

-- Cached availability (synced from hub)
CREATE TABLE osc_availability_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id VARCHAR(32) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    capacity INT NOT NULL,
    booked INT NOT NULL,
    available INT NOT NULL,
    synced_at DATETIME NOT NULL,
    INDEX idx_availability (resource_id, start_time)
);

-- Adapter configuration
CREATE TABLE osc_federation_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(255) NOT NULL,
    config_value TEXT,
    UNIQUE KEY (config_key)
);
-- Stores: hub_url, shop_id, api_key, websocket_url
```

---

## Federation Booking Flow

### End-to-End Booking Flow Across Federated Shops

**Customer books on Legacy Shop #1 (Amsterdam, Magento 1.9.4):**

```
STEP 1: Customer Browses (Legacy Shop)
┌────────────────────────────────────────┐
│ amsterdam-shop.travel-operator.com     │
│ (Magento 1.9.4, Dutch, EUR)            │
├────────────────────────────────────────┤
│ Customer: Jan de Vries                 │
│ Action: Views "Hotel Room 101"         │
│ Widget: Loads booking calendar         │
│         → REST call to Central Hub     │
│         → Shows availability from hub  │
└────────────────────────────────────────┘

STEP 2: Customer Selects Dates
┌────────────────────────────────────────┐
│ Widget on Amsterdam Shop               │
├────────────────────────────────────────┤
│ Jan selects: 2025-11-01 to 2025-11-03 │
│ Widget queries hub: GET /availability  │
│ Hub responds: "Available (120 EUR/nt)" │
│ Jan clicks "Book Now"                  │
└────────────────────────────────────────┘

STEP 3: Booking Request to Hub
┌────────────────────────────────────────┐
│ MagentoAdapter (on Amsterdam Shop)     │
├────────────────────────────────────────┤
│ POST /hub/api/bookings/create          │
│ {                                      │
│   "shop_id": "shop_amsterdam_001",     │
│   "customer_email": "jan@example.nl",  │
│   "resource_id": "room_101",           │
│   "start": "2025-11-01",               │
│   "end": "2025-11-03",                 │
│   "currency": "EUR"                    │
│ }                                      │
└────────────────────────────────────────┘

STEP 4: Hub Processes Booking (3-Phase Commit)
┌────────────────────────────────────────┐
│ Central Hub (OXID EE 8.x)              │
├────────────────────────────────────────┤
│ PHASE 1: PREPARE                       │
│ • Payment Component: Create contract   │
│ • Blockchain Inventory: Request lock   │
│ • Booking: Create (PENDING)            │
│                                        │
│ PHASE 2: COMMIT                        │
│ • Payment: Authorize 240 EUR           │
│ • Blockchain: Confirm lock             │
│ • Booking: Confirm (CONFIRMED)         │
│                                        │
│ PHASE 3: COMPLETE                      │
│ • Payment: Capture 240 EUR             │
│ • Blockchain: Consume inventory        │
│ • Booking: Activate (ACTIVE)           │
│ • Sync: Broadcast to 20 shops          │
└────────────────────────────────────────┘

STEP 5: Real-Time Sync to All Shops
┌────────────────────────────────────────┐
│ WebSocket Broadcast from Hub           │
├────────────────────────────────────────┤
│ Event: "booking.created"               │
│ {                                      │
│   "booking_id": "bk_123456",           │
│   "resource_id": "room_101",           │
│   "start": "2025-11-01",               │
│   "end": "2025-11-03",                 │
│   "status": "active"                   │
│ }                                      │
│                                        │
│ Recipients:                            │
│ • Amsterdam Shop (local order created) │
│ • Paris Shop (cache updated)           │
│ • Berlin Shop (cache updated)          │
│ • ... (18 other shops)                 │
└────────────────────────────────────────┘

STEP 6: Customer on Paris Shop Sees Updated Availability
┌────────────────────────────────────────┐
│ paris-shop.travel-operator.com         │
│ (OXID 6.2, French, EUR)                │
├────────────────────────────────────────┤
│ Customer: Marie Dubois                 │
│ Action: Views "Hotel Room 101"         │
│ Widget: Shows Room 101 UNAVAILABLE     │
│         for 2025-11-01 to 2025-11-03   │
│         (synced from hub in real-time) │
│ Marie sees: "Booked" (red)             │
└────────────────────────────────────────┘
```

### Customer Management Integration

**Use existing customer database:**

```php
// No separate customer management!
// Use OXID's oxuser table

$customer = $this->platformAdapter->getCustomer($customerId);
// Returns OXID customer with all existing data:
// - Customer number, company, VAT ID (B2B)
// - Multiple delivery addresses
// - Payment methods
// - Order history
// - Customer groups, discounts

$booking = new Booking(
    customerId: $customer->getId(),  // Links to oxuser.OXID
    customerEmail: $customer->getEmail(),
    // ... booking-specific data
);
```

### Order Management Integration

**Booking is linked to Order:**

```php
// Three-phase commit:

// PHASE 1: PREPARE
$contract = $paymentComponent->createContract($basket);
$lock = $blockchainInventory->createLock($resource, $timeSlot);
$booking = new Booking(status: BookingStatus::PENDING);

// PHASE 2: COMMIT
$contract->authorize();
$lock->confirm();
$booking->confirm();

// PHASE 3: COMPLETE
$contract->capture();
$lock->consume();
$booking->activate();

// Create order in e-commerce
$orderId = $this->platformAdapter->createOrderFromBooking($booking);
// Creates entry in oxorder with:
// - OXID = order ID
// - OSC_BOOKING_ID = booking ID
// - OSC_PAYMENT_CONTRACT_ID = contract ID

$booking->setOrderId($orderId);
```

**Benefits:**
- Order appears in OXID admin panel
- Invoice generated automatically
- Order status updates reflected in booking
- Existing ERP integration works
- B2B features available (quotes, approval workflows)

### B2B Features Integration

**Leverage OXID EE B2B capabilities:**

- **Customer Groups**: Different pricing for B2B vs. B2C
- **Quote System**: Request quote for large bookings (e.g., 100 hotel rooms)
- **Approval Workflows**: Manager approval for employee bookings
- **Credit Limits**: B2B customers book on account
- **Volume Discounts**: Automatic discounts for bulk bookings
- **Contract Pricing**: Pre-negotiated rates for corporate clients

---

### Customer Management (Federated)

**Each shop keeps its own customers (NOT shared):**

```php
// Amsterdam Shop (Magento 1.9.4)
// Customer: Jan de Vries (customer_id: 12345, magento local DB)

// Paris Shop (OXID 6.2)
// Customer: Marie Dubois (OXID: abc123, oxuser table)

// Central Hub stores email as identifier
$booking = new Booking(
    shopId: 'shop_amsterdam_001',
    userId: '12345',                    // Local to Amsterdam shop
    userEmail: 'jan@example.nl',       // Universal identifier
    // ...
);
```

**Why not share customers?**
- ❌ Legacy shops have different schemas
- ❌ GDPR compliance (data locality)
- ❌ Different authentication systems
- ✅ Email is universal identifier

---

## Summary

### What This OXID EE Federation Architecture Does

✅ **OXID EE as Central Hub** - Master booking system with native multi-shop feature
✅ **Native OXID Subshops** - Seamless integration via shared database (instant sync)
✅ **3rd-Party Integration** - Magento, WooCommerce via lightweight adapters
✅ **Unified Inventory** - Single source of truth via Blockchain Inventory Manager
✅ **No Double-Booking** - Booking anywhere instantly blocks everywhere
✅ **Centralized Payment** - Payment Component v4.0 (multi-currency, multi-provider)
✅ **Dual Integration Path** - OXID subshops (native) + 3rd-party (federated)
✅ **Single OXID Admin** - Manage OXID subshops + 3rd-party shops from one panel
✅ **Real-Time Sync** - OXID subshops (instant), 3rd-party (WebSocket)

### What This Architecture Does NOT Do

❌ Replace or migrate 3rd-party shops (non-invasive integration)
❌ Force all shops to OXID EE (hybrid approach allowed)
❌ Share customers with 3rd-party shops (OXID subshops share, 3rd-party local)
❌ Process bookings locally on 3rd-party shops (proxied to OXID hub)
❌ Require frontend changes to 3rd-party shops (widget injection only)

### Deployment Model

**Central Hub (Required):**
- **Platform:** OXID EE 8.x with multi-shop enabled
- **Location:** Central datacenter (e.g., Frankfurt, AWS EU-Central-1)
- **Components:** Booking Module + Payment Component v4.0 + Blockchain Inventory Manager
- **Database:** OXID database (MySQL/MariaDB) + Redis cache + RabbitMQ
- **Manages:** OXID subshops (native) + 3rd-party shops (federated)

**OXID EE Subshops (Preferred, Native Integration):**
- **Platform:** OXID EE subshops (same installation as hub)
- **Location:** Same server as hub (shared codebase)
- **Integration:** Native (no adapter needed)
- **Database:** Shared OXID database (oxshops, oxuser, oxarticles, oxorder)
- **Sync:** Instant (same database)
- **Examples:** Amsterdam (shop_id: 1), Paris (shop_id: 2), London (shop_id: 3)

**3rd-Party E-Commerce Shops (Legacy, Federated Integration):**
- **Platforms:** Magento 1.9.x/2.x, WooCommerce 3.x-8.x, Custom
- **Location:** Local servers or cloud (distributed)
- **Integration:** Adapter plugin (REST client + WebSocket client)
- **Database:** Local cache DB (read-only, synced from OXID hub)
- **Sync:** Real-time via WebSocket
- **Examples:** Berlin (Magento, acquired company), Rome (WooCommerce, franchise)

### Use Cases

**Perfect for:**
- 🌍 **Travel operators** - Multiple agency shops (OXID subshops + acquired Magento shops)
- 🏢 **Franchise networks** - Central brand on OXID, franchises on WooCommerce/Magento
- 🏨 **Hotel chains** - Regional booking sites (some OXID, some legacy)
- 🎫 **Event ticketing** - Multiple venues (OXID subshops for owned venues, 3rd-party for partners)
- 🚗 **Car rental** - Central fleet management on OXID, local agencies on mixed platforms

### Why OXID EE?

**Technical Reasons:**
- ✅ Native multi-shop feature (oxshops table)
- ✅ Shared database architecture (perfect for unified inventory)
- ✅ Payment Component v4.0 already integrated
- ✅ GraphQL & REST APIs for federation
- ✅ Enterprise B2B/B2C features
- ✅ MCP Server for AI integrations
- ✅ Module system for extensibility

**Business Reasons:**
- ✅ One OXID license covers multiple subshops
- ✅ Single admin panel for all OXID subshops
- ✅ Shared customer database (cross-shop loyalty, unified CRM)
- ✅ ERP integration (SAP, Dynamics)
- ✅ Can federate non-OXID shops (hybrid approach)

---

**Next:** [01-DETAILED-ARCHITECTURE.md](01-DETAILED-ARCHITECTURE.md) - Detailed OXID EE federation architecture and API specifications
