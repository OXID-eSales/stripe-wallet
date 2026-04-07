# Payment Component + Stripe - First 5 Implementation Tickets
## Sprint 1: Foundation with Component/Provider Separation + TDD

**Project:** OXID Payment Extension (Component + Stripe Provider)
**Sprint Goal:** Build reusable payment component foundation + Stripe implementation in one OXID extension
**Architecture:** Component (future package) + Stripe (provider-specific) in single extension
**Sprint Duration:** 2 weeks
**Created:** 2025-10-16
**Updated:** 2025-10-16 (Added test organization strategy)

---

## Test Organization Strategy

**Important:** Tests are split between component tests (provider-agnostic) and provider tests (provider-specific). For complete details, see:

- **[10-test-organization.md](10-test-organization.md)** - Complete test organization strategy
- **[09-tdd-strategy.md](09-tdd-strategy.md)** - TDD strategy with test split

**Key Testing Principles:**
- **Component Tests** (`tests/Component/`): Mock `PaymentAdapterInterface`, test business logic without provider SDK dependencies, 95%+ coverage, fast execution
- **Provider Tests** (`tests/Stripe/`, `tests/Unzer/`): Mock or use real provider SDKs, test adapter implementations, 90%+ coverage, slower execution
- **Separate Test Suites**: Independent execution with different coverage requirements
- **Separate CI/CD Jobs**: Component tests run in parallel with provider tests

**Test Suites:**
```bash
# Run component tests only (fast, no external dependencies)
vendor/bin/phpunit --testsuite=Component

# Run Stripe adapter tests only
vendor/bin/phpunit --testsuite=Stripe

# Run all tests
vendor/bin/phpunit
```

---

## Architecture Overview

### Directory Structure Philosophy

```
┌─────────────────────────────────────────────────────────────────┐
│                    OXID Extension Structure                     │
│                                                                 │
│  extensions/osc/stripe/                                   │
│  ├── src/Component/          ← Reusable (future package)       │
│  │   ├── Contract/           ← Interfaces                      │
│  │   ├── Event/              ← Event-driven foundation         │
│  │   ├── Model/              ← Domain models                   │
│  │   ├── Repository/         ← Data access                     │
│  │   └── Service/            ← Business logic                  │
│  │                                                              │
│  ├── src/Stripe/             ← Stripe-specific implementation  │
│  │   ├── Handler/            ← Stripe event handlers           │
│  │   ├── Service/            ← Stripe API integration          │
│  │   ├── Webhook/            ← Stripe webhooks                 │
│  │   └── Controller/         ← Stripe controllers              │
│  │                                                              │
│  ├── migration/              ← Database migrations             │
│  ├── tests/                  ← All tests                       │
│  └── metadata.php            ← OXID module metadata            │
└─────────────────────────────────────────────────────────────────┘

Key Principles:
✅ Component = Provider-agnostic (Stripe, Paymenter, Adyen, etc.)
✅ Stripe = Stripe-specific implementation only
✅ Clean separation enables future extraction to Composer package
```

---

## Complete File Structure

```
extensions/osc/stripe/
│
├── composer.json                         # Package definition
├── metadata.php                          # OXID module metadata
├── README.md                            # Documentation
├── phpunit.xml.dist                     # PHPUnit configuration
├── phpstan.neon                         # PHPStan configuration
├── phpcs.xml                            # Code standards
│
├── src/
│   │
│   ├── Component/                       # 🔷 REUSABLE COMPONENT (Future Package)
│   │   │
│   │   ├── Contract/                    # Interfaces & Contracts
│   │   │   ├── EventDispatcherInterface.php
│   │   │   ├── PaymentServiceInterface.php
│   │   │   ├── PaymentTransactionRepositoryInterface.php
│   │   │   ├── OrderRepositoryInterface.php
│   │   │   ├── ModuleSettingsInterface.php
│   │   │   └── WebhookHandlerInterface.php
│   │   │
│   │   ├── Event/                       # Event-Driven Layer
│   │   │   ├── EventContext.php         # Request data cache
│   │   │   ├── EventDispatcher.php      # PSR-14 dispatcher
│   │   │   │
│   │   │   └── Domain/                  # Domain Events
│   │   │       ├── PaymentInitiatedEvent.php
│   │   │       ├── PaymentAuthorizedEvent.php
│   │   │       ├── PaymentCapturedEvent.php
│   │   │       ├── PaymentFailedEvent.php
│   │   │       ├── PaymentRefundedEvent.php
│   │   │       ├── OrderCreatedEvent.php
│   │   │       ├── OrderCompletedEvent.php
│   │   │       └── WebhookReceivedEvent.php
│   │   │
│   │   ├── EventHandler/                # Abstract Event Handlers
│   │   │   ├── AbstractPaymentHandler.php
│   │   │   ├── AbstractWebhookHandler.php
│   │   │   └── AbstractOrderHandler.php
│   │   │
│   │   ├── Model/                       # Domain Models
│   │   │   ├── PaymentOrderStates.php   # State constants
│   │   │   ├── PaymentTransaction.php   # Transaction model
│   │   │   ├── PaymentOrderState.php    # Order payment state (references oxorder)
│   │   │   ├── PaymentCustomer.php      # Customer payment data (references oxuser)
│   │   │   └── PaymentBasketSnapshot.php # Basket snapshot (references oxorder)
│   │   │
│   │   ├── Repository/                  # Data Access Layer
│   │   │   ├── PaymentTransactionRepository.php
│   │   │   └── OrderRepository.php
│   │   │
│   │   ├── Service/                     # Business Services
│   │   │   ├── AbstractPaymentService.php
│   │   │   ├── OrderManager.php
│   │   │   ├── BasketSummaryService.php
│   │   │   └── OrderProcessTrackingService.php
│   │   │
│   │   └── Webhook/                     # Webhook Foundation
│   │       ├── WebhookHandlerBase.php
│   │       ├── WebhookVerifierInterface.php
│   │       └── WebhookDispatcher.php
│   │
│   └── Stripe/                          # 🔶 STRIPE-SPECIFIC IMPLEMENTATION
│       │
│       ├── Handler/                     # Stripe Event Handlers
│       │   ├── PaymentInitiationHandler.php
│       │   ├── PaymentCaptureHandler.php
│       │   └── PaymentRefundHandler.php
│       │
│       ├── Service/                     # Stripe API Integration
│       │   ├── StripePaymentService.php # Implements PaymentServiceInterface
│       │   ├── StripeApiClient.php
│       │   ├── StripeRequestFactory.php
│       │   └── StripeSettings.php       # Implements ModuleSettingsInterface
│       │
│       ├── Webhook/                     # Stripe Webhook Processing
│       │   ├── StripeWebhookHandler.php
│       │   ├── StripeWebhookVerifier.php
│       │   └── Handler/
│       │       ├── PaymentIntentSucceededHandler.php
│       │       ├── PaymentIntentFailedHandler.php
│       │       └── ChargeRefundedHandler.php
│       │
│       ├── Controller/                  # Stripe Controllers
│       │   ├── StripePaymentController.php
│       │   ├── StripeWebhookController.php
│       │   └── Admin/
│       │       └── StripeConfigController.php
│       │
│       └── Model/                       # Stripe-Specific Models
│           ├── StripePaymentIntent.php
│           └── StripeCustomer.php
│
├── migration/                           # Database Migrations
│   ├── 001_create_payment_transaction_table.sql
│   ├── 002_create_payment_order_state_table.sql
│   ├── 003_create_payment_customer_table.sql
│   └── 004_create_payment_basket_snapshot_table.sql
│
├── tests/                               # 🔷 TEST ORGANIZATION (See 10-test-organization.md)
│   ├── bootstrap.php
│   │
│   ├── Component/                       # 🔷 COMPONENT TESTS (Provider-Agnostic)
│   │   │                                # - Mock PaymentAdapterInterface
│   │   │                                # - No provider SDK dependencies
│   │   │                                # - 95%+ coverage, fast execution (<1 min)
│   │   │
│   │   ├── Unit/                        # Component Unit Tests
│   │   │   ├── Event/
│   │   │   │   ├── EventContextTest.php
│   │   │   │   ├── EventDispatcherTest.php
│   │   │   │   └── Domain/
│   │   │   │       ├── PaymentInitiatedEventTest.php
│   │   │   │       └── PaymentCapturedEventTest.php
│   │   │   │
│   │   │   ├── Model/
│   │   │   │   ├── PaymentTransactionTest.php
│   │   │   │   ├── PaymentOrderStateTest.php
│   │   │   │   ├── PaymentCustomerTest.php
│   │   │   │   └── PaymentBasketSnapshotTest.php
│   │   │   │
│   │   │   ├── Service/
│   │   │   │   ├── PaymentServiceTest.php      # Mocks PaymentAdapterInterface
│   │   │   │   ├── OrderManagerTest.php
│   │   │   │   └── BasketSummaryServiceTest.php
│   │   │   │
│   │   │   └── Adapter/                # Adapter Interface Tests
│   │   │       ├── PaymentAdapterInterfaceTest.php
│   │   │       ├── CreatePaymentRequestTest.php
│   │   │       ├── PaymentResponseTest.php
│   │   │       └── AdapterFactoryTest.php
│   │   │
│   │   └── Integration/                # Component Integration Tests
│   │       ├── Repository/
│   │       │   ├── PaymentTransactionRepositoryTest.php
│   │       │   └── OrderRepositoryTest.php
│   │       │
│   │       └── Service/
│   │           └── PaymentServiceIntegrationTest.php  # With mocked adapter
│   │
│   ├── Stripe/                          # 🔶 STRIPE ADAPTER TESTS (Provider-Specific)
│   │   │                                # - Mock or use real Stripe SDK
│   │   │                                # - Test adapter implementations
│   │   │                                # - 90%+ coverage, slower execution
│   │   │
│   │   ├── Unit/                        # Stripe Unit Tests (Mock Stripe SDK)
│   │   │   ├── StripeAdapterTest.php    # Test request/response translation
│   │   │   ├── StripeStatusMapperTest.php
│   │   │   ├── StripeCustomerMapperTest.php
│   │   │   ├── StripeBasketMapperTest.php
│   │   │   └── StripeWebhookParserTest.php
│   │   │
│   │   └── Integration/                # Stripe Integration Tests (Real Stripe API)
│   │       ├── StripeAdapterIntegrationTest.php
│   │       ├── StripeCreatePaymentTest.php
│   │       ├── StripeCapturePaymentTest.php
│   │       └── StripeWebhookProcessingTest.php
│   │
│   └── E2E/                             # 🔷 END-TO-END TESTS (Full Flow)
│       └── FullPaymentFlowTest.php      # Complete checkout → payment → webhook
│   │
│   ├── Acceptance/
│   │   └── Stripe/
│   │       ├── CheckoutFlowTest.php
│   │       └── WebhookProcessingTest.php
│   │
│   └── Support/                         # Test Helpers
│       ├── TestCase.php
│       ├── IntegrationTestCase.php
│       ├── DatabaseTestCase.php
│       ├── Builders/
│       │   ├── PaymentTransactionBuilder.php
│       │   ├── OrderBuilder.php
│       │   └── StripePaymentIntentBuilder.php
│       └── Fixtures/
│           ├── DatabaseFixtures.php
│           └── StripeApiFixtures.php
│
└── views/                               # OXID Templates
    ├── blocks/
    │   └── page/
    │       └── checkout/
    │           └── payment_stripe.tpl
    │
    └── admin/
        └── tpl/
            └── stripe_config.tpl
```

---

## Database Schema

### Architecture Philosophy: Minimal Core Dependency

**Key Principle:** Component models reference OXID core tables via FK, but DO NOT extend them.
- ✅ Component tables with OXORDERID/OXUSERID foreign keys
- ❌ NO ALTER TABLE statements on oxorder/oxuser/oxbasket
- ✅ Isolated component schema that can be dropped without affecting core

### Migration Files Structure

```sql
-- migration/001_create_payment_transaction_table.sql
-- Core transaction tracking (Component layer)

CREATE TABLE IF NOT EXISTS oe_payments_transaction (
    OXID CHAR(32) NOT NULL PRIMARY KEY COMMENT 'Primary key',
    OXSHOPID INT(11) NOT NULL COMMENT 'Shop ID',
    OXORDERID CHAR(32) NOT NULL COMMENT 'FK to oxorder.OXID',
    OXPROVIDERORDERID VARCHAR(128) NOT NULL COMMENT 'Provider order ID (pi_xxx for Stripe)',
    OXTRANSACTIONID VARCHAR(128) NULL COMMENT 'Provider transaction/charge ID',
    OXSTATUS VARCHAR(64) NOT NULL COMMENT 'Transaction status',
    OXPAYMENTMETHODID VARCHAR(64) NOT NULL COMMENT 'Payment method identifier',
    OXTRANSACTIONTYPE VARCHAR(32) NOT NULL COMMENT 'capture|authorization|refund|void',
    OXTRACKINGCODE VARCHAR(255) NULL COMMENT 'Shipment tracking number',
    OXTRACKINGCARRIER VARCHAR(64) NULL COMMENT 'Carrier name',
    OXPROVIDERDATA TEXT NULL COMMENT 'JSON with provider-specific data',
    OXTIMESTAMP TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Created at',
    OXUPDATED TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Updated at',

    UNIQUE KEY UNIQUE_ORDER_PROVIDER (OXORDERID, OXPROVIDERORDERID),
    INDEX IDX_PROVIDER_ORDER (OXPROVIDERORDERID),
    INDEX IDX_TRANSACTION (OXTRANSACTIONID),
    INDEX IDX_ORDER_ID (OXORDERID),
    INDEX IDX_STATUS (OXSTATUS),
    INDEX IDX_SHOP (OXSHOPID),
    INDEX IDX_TRANSACTION_TYPE (OXTRANSACTIONTYPE, OXSTATUS),
    INDEX IDX_CREATED_AT (OXTIMESTAMP),

    FOREIGN KEY FK_PAYMENT_ORDER (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment transactions for all providers';
```

```sql
-- migration/002_create_payment_order_state_table.sql
-- Component-level order payment state tracking (Component layer)
-- Replaces extending oxorder table

CREATE TABLE IF NOT EXISTS oe_payments_order_state (
    OXID CHAR(32) NOT NULL PRIMARY KEY COMMENT 'Primary key',
    OXORDERID CHAR(32) NOT NULL UNIQUE COMMENT 'FK to oxorder.OXID (1:1 relationship)',
    OXPAYMENTSTATE VARCHAR(32) NOT NULL COMMENT 'Payment state (NOT_FINISHED|500-900|OK|ERROR)',
    OXPROVIDERORDERID VARCHAR(128) NULL COMMENT 'Provider order ID for quick lookup',
    OXWEBHOOKWAITSINCE DATETIME NULL COMMENT 'Webhook wait start time',
    OXWEBHOOKTIMEOUT INT NULL COMMENT 'Webhook timeout in seconds',
    OXLASTPAYMENTATTEMPT DATETIME NULL COMMENT 'Last payment attempt timestamp',
    OXPAYMENTATTEMPTCOUNT INT NOT NULL DEFAULT 0 COMMENT 'Number of payment attempts',
    OXTIMESTAMP TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Created at',
    OXUPDATED TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Updated at',

    INDEX IDX_PAYMENT_STATE (OXPAYMENTSTATE),
    INDEX IDX_PROVIDER_ORDER (OXPROVIDERORDERID),
    INDEX IDX_WEBHOOK_WAIT (OXWEBHOOKWAITSINCE),
    INDEX IDX_CREATED_AT (OXTIMESTAMP),

    FOREIGN KEY FK_ORDER_STATE (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment order state tracking (1:1 with oxorder)';
```

```sql
-- migration/003_create_payment_customer_table.sql
-- Component-level payment customer data (Component layer)
-- Replaces extending oxuser table

CREATE TABLE IF NOT EXISTS oe_payments_customer (
    OXID CHAR(32) NOT NULL PRIMARY KEY COMMENT 'Primary key',
    OXUSERID CHAR(32) NOT NULL UNIQUE COMMENT 'FK to oxuser.OXID (1:1 relationship)',
    OXPAYMENTCUSTOMERID VARCHAR(128) NULL COMMENT 'Payment provider customer ID (cus_xxx for Stripe)',
    OXDEFAULTPAYMENTMETHOD VARCHAR(64) NULL COMMENT 'Default payment method ID',
    OXSAVEDPAYMENTMETHODS TEXT NULL COMMENT 'JSON array of saved payment methods',
    OXBILLINGAGREEMENT BOOLEAN DEFAULT FALSE COMMENT 'Has billing agreement',
    OXLASTPAYMENTDATE DATETIME NULL COMMENT 'Last successful payment date',
    OXTIMESTAMP TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Created at',
    OXUPDATED TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Updated at',

    INDEX IDX_PAYMENT_CUSTOMER (OXPAYMENTCUSTOMERID),
    INDEX IDX_USER_ID (OXUSERID),
    INDEX IDX_CREATED_AT (OXTIMESTAMP),

    FOREIGN KEY FK_PAYMENT_USER (OXUSERID) REFERENCES oxuser(OXID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment customer data (1:1 with oxuser)';
```

```sql
-- migration/004_create_payment_basket_snapshot_table.sql
-- Component-level basket snapshot for payment reconciliation (Component layer)
-- Stores basket state at payment initiation time

CREATE TABLE IF NOT EXISTS oe_payments_basket_snapshot (
    OXID CHAR(32) NOT NULL PRIMARY KEY COMMENT 'Primary key',
    OXORDERID CHAR(32) NOT NULL COMMENT 'FK to oxorder.OXID',
    OXUSERID CHAR(32) NULL COMMENT 'FK to oxuser.OXID',
    OXBASKETDATA TEXT NOT NULL COMMENT 'JSON snapshot of basket contents',
    OXTOTAL DECIMAL(10,2) NOT NULL COMMENT 'Total amount',
    OXCURRENCY VARCHAR(3) NOT NULL COMMENT 'Currency code (ISO 4217)',
    OXDISCOUNT DECIMAL(10,2) NULL COMMENT 'Applied discount',
    OXSHIPPING DECIMAL(10,2) NULL COMMENT 'Shipping cost',
    OXTAX DECIMAL(10,2) NULL COMMENT 'Tax amount',
    OXTIMESTAMP TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Snapshot created at',

    INDEX IDX_ORDER_ID (OXORDERID),
    INDEX IDX_USER_ID (OXUSERID),
    INDEX IDX_CREATED_AT (OXTIMESTAMP),

    FOREIGN KEY FK_BASKET_ORDER (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE,
    FOREIGN KEY FK_BASKET_USER (OXUSERID) REFERENCES oxuser(OXID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Basket snapshots at payment initiation';
```

---

## Composer Configuration

```json
{
  "name": "osc/oxid-payment-stripe",
  "description": "OXID Payment Component with Stripe implementation",
  "type": "oxideshop-module",
  "license": "proprietary",
  "require": {
    "php": "^8.0",
    "psr/event-dispatcher": "^1.0",
    "psr/log": "^3.0",
    "stripe/stripe-php": "^10.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.5",
    "phpstan/phpstan": "^1.10",
    "squizlabs/php_codesniffer": "^3.7",
    "mockery/mockery": "^1.5",
    "fakerphp/faker": "^1.23"
  },
  "autoload": {
    "psr-4": {
      "Osc\\Payment\\Component\\": "src/Component/",
      "Osc\\Payment\\Stripe\\": "src/Stripe/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Osc\\Payment\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "test": "phpunit",
    "test:unit": "phpunit --testsuite Unit",
    "test:integration": "phpunit --testsuite Integration",
    "test:coverage": "phpunit --coverage-html coverage/",
    "phpstan": "phpstan analyse -c phpstan.neon",
    "phpcs": "phpcs --standard=phpcs.xml src/ tests/",
    "lint": ["@phpstan", "@phpcs"],
    "check": ["@lint", "@test"]
  }
}
```

---

## OXID Module Metadata

```php
<?php
// metadata.php

$sMetadataVersion = '2.1';

$aModule = [
    'id' => 'osc/payment-stripe',
    'title' => 'OSC Payment Component + Stripe',
    'description' => [
        'de' => 'Event-getriebenes Zahlungskomponent mit Stripe Integration',
        'en' => 'Event-driven payment component with Stripe integration',
    ],
    'version' => '1.0.0',
    'author' => 'OSC',
    'email' => 'info@oxidshop-community.com',
    'thumbnail' => 'logo.png',

    // NO class extensions - component uses its own tables with FK references
    'extend' => [],

    'controllers' => [
        // Stripe controllers
        'osc_stripe_payment' => \Osc\Payment\Stripe\Controller\StripePaymentController::class,
        'osc_stripe_webhook' => \Osc\Payment\Stripe\Controller\StripeWebhookController::class,

        // Admin
        'osc_stripe_config' => \Osc\Payment\Stripe\Controller\Admin\StripeConfigController::class,
    ],

    'templates' => [
        'stripe_payment.tpl' => 'osc/payment/views/blocks/page/checkout/payment_stripe.tpl',
        'stripe_config.tpl' => 'osc/payment/views/admin/tpl/stripe_config.tpl',
    ],

    'blocks' => [
        [
            'template' => 'page/checkout/payment.tpl',
            'block' => 'checkout_payment_main',
            'file' => 'views/blocks/page/checkout/payment_stripe.tpl',
        ],
    ],

    'settings' => [
        [
            'group' => 'osc_stripe_main',
            'name' => 'oscStripeMode',
            'type' => 'select',
            'value' => 'test',
            'constraints' => 'test|live',
        ],
        [
            'group' => 'osc_stripe_main',
            'name' => 'oscStripeTestSecretKey',
            'type' => 'str',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_main',
            'name' => 'oscStripeLiveSecretKey',
            'type' => 'password',
            'value' => '',
        ],
    ],

    'events' => [
        'onActivate' => '\Osc\Payment\Stripe\Setup\Events::onActivate',
        'onDeactivate' => '\Osc\Payment\Stripe\Setup\Events::onDeactivate',
    ],
];
```

---

## TDD Strategy Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     TDD Red-Green-Refactor Cycle                │
│                                                                 │
│  1. RED:    Write failing test first                           │
│  2. GREEN:  Write minimal code to pass test                    │
│  3. REFACTOR: Improve code while keeping tests green           │
│  4. REPEAT: Next feature                                       │
└─────────────────────────────────────────────────────────────────┘

Test Organization:
├── Unit Tests:        Component layer (pure logic, no DB)
├── Integration Tests: Component + Stripe with DB
└── Acceptance Tests:  Full payment flows with Stripe API mocks
```

---

# TICKET-001: Project Setup with Component/Stripe Structure

## Summary
Set up the OXID module with complete directory structure separating Component (reusable) and Stripe (provider-specific) with TDD infrastructure.

## Priority
**P0 - Blocker** (All other work depends on this)

## Story Points
**8 points** (2 days)

## Business Value
Establishes the foundation for clean architecture with clear separation between reusable component and Stripe implementation, enabling future extraction to Composer package.

---

## Description

Create the OXID module structure with:
- Correct PSR-4 namespacing (Component vs Stripe)
- OXID metadata.php configuration
- Complete test infrastructure (PHPUnit, test doubles, fixtures)
- Database migrations structure
- Code quality tools (PHPStan, PHPCS)
- CI/CD pipeline

This follows TDD by setting up tests BEFORE any production code.

---

## Acceptance Criteria

### Must Have
- [ ] OXID module structure created (`extensions/osc/stripe/`)
- [ ] Composer package with dual PSR-4 namespaces
  - `Osc\Payment\Component\` → `src/Component/`
  - `Osc\Payment\Stripe\` → `src/Stripe/`
- [ ] metadata.php configured for OXID
- [ ] PHPUnit 9+ with 3 test suites (Unit, Integration, Acceptance)
- [ ] Database migration files created
- [ ] PHPStan level 6+ configured
- [ ] GitHub Actions workflow
- [ ] Directory structure verification test passes

### Should Have
- [ ] Makefile with common commands
- [ ] Docker setup for testing
- [ ] Pre-commit hooks

### Won't Have (This Sprint)
- OXID shop integration (later)
- Admin UI (later)

---

## Technical Details

### Directory Creation Script

```bash
#!/bin/bash
# scripts/setup-structure.sh

MODULE_DIR="extensions/osc/stripe"

# Create Component directories
mkdir -p "$MODULE_DIR/src/Component/Contract"
mkdir -p "$MODULE_DIR/src/Component/Event/Domain"
mkdir -p "$MODULE_DIR/src/Component/EventHandler"
mkdir -p "$MODULE_DIR/src/Component/Model"
mkdir -p "$MODULE_DIR/src/Component/Repository"
mkdir -p "$MODULE_DIR/src/Component/Service"
mkdir -p "$MODULE_DIR/src/Component/Webhook"

# Create Stripe directories
mkdir -p "$MODULE_DIR/src/Stripe/Handler"
mkdir -p "$MODULE_DIR/src/Stripe/Service"
mkdir -p "$MODULE_DIR/src/Stripe/Webhook/Handler"
mkdir -p "$MODULE_DIR/src/Stripe/Controller/Admin"
mkdir -p "$MODULE_DIR/src/Stripe/Model"

# Create test directories
mkdir -p "$MODULE_DIR/tests/Component/Unit/Component/Event/Domain"
mkdir -p "$MODULE_DIR/tests/Component/Unit/Component/Model"
mkdir -p "$MODULE_DIR/tests/Component/Unit/Stripe/Handler"
mkdir -p "$MODULE_DIR/tests/Component/Integration/Component/Repository"
mkdir -p "$MODULE_DIR/tests/Component/Integration/Stripe"
mkdir -p "$MODULE_DIR/tests/Acceptance/Stripe"
mkdir -p "$MODULE_DIR/tests/Support/Builders"
mkdir -p "$MODULE_DIR/tests/Support/Fixtures"

# Create migration directory
mkdir -p "$MODULE_DIR/migration"

# Create views
mkdir -p "$MODULE_DIR/views/blocks/page/checkout"
mkdir -p "$MODULE_DIR/views/admin/tpl"

echo "✅ Directory structure created"
```

### Database Migration Runner

```php
<?php
// src/Stripe/Setup/MigrationRunner.php

namespace OxidSolutionCatalysts\Stripe\Setup;

use OxidEsales\Eshop\Core\Database\Adapter\DatabaseInterface;

class MigrationRunner
{
    private DatabaseInterface $db;
    private string $migrationPath;

    public function __construct(DatabaseInterface $db, string $migrationPath)
    {
        $this->db = $db;
        $this->migrationPath = $migrationPath;
    }

    public function runAll(): void
    {
        $migrations = glob($this->migrationPath . '/*.sql');
        sort($migrations);

        foreach ($migrations as $migration) {
            $this->runMigration($migration);
        }
    }

    private function runMigration(string $file): void
    {
        $sql = file_get_contents($file);
        $statements = array_filter(explode(';', $sql));

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $this->db->execute($statement);
            }
        }

        echo "✅ Migrated: " . basename($file) . "\n";
    }
}
```

---

## TDD Workflow

### Step 1: RED - Write Structure Verification Tests

```php
<?php
// tests/Component/Unit/Infrastructure/ModuleStructureTest.php

namespace OxidSolutionCatalysts\Stripe\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

class ModuleStructureTest extends TestCase
{
    private string $moduleRoot;

    protected function setUp(): void
    {
        $this->moduleRoot = __DIR__ . '/../../..';
    }

    /** @test */
    public function composer_json_has_correct_namespaces(): void
    {
        $composerJson = $this->moduleRoot . '/composer.json';
        $this->assertFileExists($composerJson);

        $data = json_decode(file_get_contents($composerJson), true);

        $this->assertEquals('osc/oxid-payment-stripe', $data['name']);
        $this->assertArrayHasKey('Osc\\Payment\\Component\\', $data['autoload']['psr-4']);
        $this->assertArrayHasKey('Osc\\Payment\\Stripe\\', $data['autoload']['psr-4']);
        $this->assertEquals('src/Component/', $data['autoload']['psr-4']['Osc\\Payment\\Component\\']);
        $this->assertEquals('src/Stripe/', $data['autoload']['psr-4']['Osc\\Payment\\Stripe\\']);
    }

    /** @test */
    public function metadata_php_exists_and_is_valid(): void
    {
        $metadataFile = $this->moduleRoot . '/metadata.php';
        $this->assertFileExists($metadataFile);

        $aModule = [];
        include $metadataFile;

        $this->assertEquals('osc/payment-stripe', $aModule['id']);
        $this->assertEquals('2.1', $GLOBALS['sMetadataVersion'] ?? $sMetadataVersion);
    }

    /** @test */
    public function component_directories_exist(): void
    {
        $requiredDirs = [
            'src/Component/Contract',
            'src/Component/Event',
            'src/Component/Event/Domain',
            'src/Component/Model',
            'src/Component/Repository',
            'src/Component/Service',
            'src/Component/Webhook',
        ];

        foreach ($requiredDirs as $dir) {
            $this->assertDirectoryExists(
                $this->moduleRoot . '/' . $dir,
                "Missing directory: $dir"
            );
        }
    }

    /** @test */
    public function stripe_directories_exist(): void
    {
        $requiredDirs = [
            'src/Stripe/Handler',
            'src/Stripe/Service',
            'src/Stripe/Webhook',
            'src/Stripe/Controller',
            'src/Stripe/Model',
        ];

        foreach ($requiredDirs as $dir) {
            $this->assertDirectoryExists(
                $this->moduleRoot . '/' . $dir,
                "Missing directory: $dir"
            );
        }
    }

    /** @test */
    public function test_directories_exist(): void
    {
        $requiredDirs = [
            'tests/Component/Unit/Component',
            'tests/Component/Unit/Stripe',
            'tests/Component/Integration/Component',
            'tests/Component/Integration/Stripe',
            'tests/Support',
        ];

        foreach ($requiredDirs as $dir) {
            $this->assertDirectoryExists(
                $this->moduleRoot . '/' . $dir,
                "Missing test directory: $dir"
            );
        }
    }

    /** @test */
    public function migration_files_exist(): void
    {
        $migrationDir = $this->moduleRoot . '/migration';
        $this->assertDirectoryExists($migrationDir);

        $expectedMigrations = [
            '001_create_payment_transaction_table.sql',
            '002_create_payment_order_state_table.sql',
            '003_create_payment_customer_table.sql',
            '004_create_payment_basket_snapshot_table.sql',
        ];

        foreach ($expectedMigrations as $migration) {
            $this->assertFileExists(
                $migrationDir . '/' . $migration,
                "Missing migration: $migration"
            );
        }
    }

    /** @test */
    public function phpunit_xml_is_configured_correctly(): void
    {
        $phpunitXml = $this->moduleRoot . '/phpunit.xml.dist';
        $this->assertFileExists($phpunitXml);

        $xml = simplexml_load_file($phpunitXml);

        // Check test suites exist
        $testsuites = $xml->xpath('//testsuite[@name="Unit"]');
        $this->assertCount(1, $testsuites, 'Unit test suite not found');

        $testsuites = $xml->xpath('//testsuite[@name="Integration"]');
        $this->assertCount(1, $testsuites, 'Integration test suite not found');
    }
}
```

### Step 2: GREEN - Create Structure

Run tests (they fail), then create all files/directories.

### Step 3: REFACTOR - Document

Add README, improve scripts.

---

## Tasks Breakdown

1. **Create Directory Structure** (2 hours)
   - [ ] Run setup script
   - [ ] Create .gitkeep files
   - [ ] Verify structure

2. **Configure Composer** (2 hours)
   - [ ] Create composer.json with dual namespaces
   - [ ] Run `composer install`
   - [ ] Test autoloading

3. **Create OXID metadata.php** (2 hours)
   - [ ] Configure module metadata
   - [ ] Define extended classes
   - [ ] Configure controllers
   - [ ] Test metadata loads in OXID

4. **Database Migrations** (3 hours)
   - [ ] Create migration files (001-004)
   - [ ] Create MigrationRunner
   - [ ] Test migrations run successfully
   - [ ] Verify table structure

5. **Test Infrastructure** (3 hours)
   - [ ] Configure PHPUnit with 3 suites
   - [ ] Create base test classes
   - [ ] Configure PHPStan
   - [ ] Write structure verification tests
   - [ ] Run all tests (GREEN)

6. **CI/CD Pipeline** (2 hours)
   - [ ] Create GitHub Actions workflow
   - [ ] Test workflow runs

---

## Definition of Done

- [ ] All acceptance criteria met
- [ ] Structure verification tests pass
- [ ] Composer autoload works for both namespaces
- [ ] Database migrations run successfully
- [ ] PHPUnit runs with 3 test suites
- [ ] PHPStan level 6 passes (with no code yet)
- [ ] README documents structure
- [ ] PR reviewed and approved

---

## Dependencies
None (foundation ticket)

---

## Related Tickets
Blocks all other tickets

---

# TICKET-002: Component Event Layer (Domain Events + Context)

## Summary
Implement the reusable event layer in `src/Component/Event/` with domain events, EventContext, and event dispatcher.

## Priority
**P0 - Critical**

## Story Points
**8 points** (2 days)

## Business Value
Establishes the event-driven foundation that enables loose coupling between Component and Stripe layers.

---

## Description

Create the Component event layer:
- EventContext for request data caching
- 8 domain events for payment lifecycle
- Event contracts
- PSR-14 event dispatcher wrapper

All code goes in `src/Component/Event/` as it's provider-agnostic.

---

## Acceptance Criteria

### Must Have
- [ ] EventContext class in `src/Component/Event/`
- [ ] 8 domain events in `src/Component/Event/Domain/`
- [ ] EventDispatcher in `src/Component/Event/`
- [ ] EventDispatcherInterface in `src/Component/Contract/`
- [ ] All events immutable with validation
- [ ] 100% test coverage
- [ ] All events properly namespaced under Component

### Should Have
- [ ] Event factory helpers
- [ ] Event serialization support

---

## Technical Details

### EventContext Implementation

```php
<?php
// src/Component/Event/EventContext.php

namespace Osc\Payment\Component\Event;

/**
 * Event Context - Request-scoped data cache
 *
 * Prevents multiple DB queries during event processing
 */
final class EventContext
{
    private array $data = [];

    public function __construct(array $initialData = [])
    {
        $this->data = $initialData;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function all(): array
    {
        return $this->data;
    }

    // Typed convenience methods
    public function getBasket(): ?object
    {
        return $this->get('basket');
    }

    public function getUser(): ?object
    {
        return $this->get('user');
    }

    public function getOrderId(): ?string
    {
        return $this->get('orderId');
    }
}
```

### Example Domain Event

```php
<?php
// src/Component/Event/Domain/PaymentInitiatedEvent.php

namespace Osc\Payment\Component\Event\Domain;

use Osc\Payment\Component\Event\EventContext;

/**
 * Payment Initiated Event
 *
 * Emitted when customer initiates payment at checkout.
 * Handler should create provider order and return redirect URL.
 */
final class PaymentInitiatedEvent
{
    private EventContext $context;
    private string $paymentMethodId;
    private float $amount;
    private string $currency;
    private string $returnUrl;
    private string $cancelUrl;

    // Result data (set by handlers)
    private ?string $providerRedirectUrl = null;
    private ?string $providerOrderId = null;

    public function __construct(
        EventContext $context,
        string $paymentMethodId,
        float $amount,
        string $currency,
        string $returnUrl,
        string $cancelUrl
    ) {
        $this->validateAmount($amount);
        $this->validateCurrency($currency);

        $this->context = $context;
        $this->paymentMethodId = $paymentMethodId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->returnUrl = $returnUrl;
        $this->cancelUrl = $cancelUrl;
    }

    // Getters
    public function getContext(): EventContext { return $this->context; }
    public function getPaymentMethodId(): string { return $this->paymentMethodId; }
    public function getAmount(): float { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getReturnUrl(): string { return $this->returnUrl; }
    public function getCancelUrl(): string { return $this->cancelUrl; }

    // Result setters (for handlers)
    public function setProviderRedirectUrl(string $url): void
    {
        $this->providerRedirectUrl = $url;
    }

    public function getProviderRedirectUrl(): ?string
    {
        return $this->providerRedirectUrl;
    }

    public function setProviderOrderId(string $orderId): void
    {
        $this->providerOrderId = $orderId;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
    }

    private function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }
    }

    private function validateCurrency(string $currency): void
    {
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('Currency must be 3-letter ISO code');
        }
    }
}
```

### Event Dispatcher

```php
<?php
// src/Component/Event/EventDispatcher.php

namespace Osc\Payment\Component\Event;

use Osc\Payment\Component\Contract\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class EventDispatcher implements EventDispatcherInterface
{
    private array $listeners = [];
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function dispatch(object $event): object
    {
        $eventClass = get_class($event);
        $this->logger->debug('Dispatching event', ['event' => $eventClass]);

        if (!$this->hasListeners($eventClass)) {
            return $event;
        }

        foreach ($this->getListeners($eventClass) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$eventClass])) {
            $this->listeners[$eventClass] = [];
        }

        $this->listeners[$eventClass][] = [$listener, $priority];

        usort($this->listeners[$eventClass], fn($a, $b) => $b[1] <=> $a[1]);
    }

    public function removeListener(string $eventClass, callable $listener): void
    {
        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        $this->listeners[$eventClass] = array_filter(
            $this->listeners[$eventClass],
            fn($item) => $item[0] !== $listener
        );
    }

    public function getListeners(string $eventClass): array
    {
        if (!isset($this->listeners[$eventClass])) {
            return [];
        }

        return array_map(fn($item) => $item[0], $this->listeners[$eventClass]);
    }

    public function hasListeners(string $eventClass): bool
    {
        return isset($this->listeners[$eventClass]) && !empty($this->listeners[$eventClass]);
    }
}
```

---

## TDD Workflow

### Tests to Write

```php
<?php
// tests/Component/Unit/Component/Event/EventContextTest.php
// tests/Component/Unit/Component/Event/EventDispatcherTest.php
// tests/Component/Unit/Component/Event/Domain/PaymentInitiatedEventTest.php
// tests/Component/Unit/Component/Event/Domain/PaymentCapturedEventTest.php
// ... (tests for all 8 events)
```

(Same test structure as before, but with correct namespaces)

---

## Tasks Breakdown

1. **EventContext** (2 hours)
   - Write tests
   - Implement EventContext
   - Test request-scoped caching

2. **Domain Events** (4 hours)
   - Implement 8 domain events with tests:
     - PaymentInitiatedEvent
     - PaymentAuthorizedEvent
     - PaymentCapturedEvent
     - PaymentFailedEvent
     - PaymentRefundedEvent
     - OrderCreatedEvent
     - OrderCompletedEvent
     - WebhookReceivedEvent

3. **EventDispatcher** (3 hours)
   - Write dispatcher tests
   - Implement dispatcher
   - Test priority ordering
   - Test stoppable events

4. **Integration** (1 hour)
   - Test full event flow
   - Document event catalog

---

## Definition of Done

- [ ] All acceptance criteria met
- [ ] EventContext + 8 events + dispatcher implemented
- [ ] All in `src/Component/Event/` namespace
- [ ] 100% test coverage
- [ ] All tests passing
- [ ] PHPStan passes
- [ ] Documentation complete

---

# TICKET-003: Component Models (PaymentTransaction + Component-Level Models)

## Summary
Implement domain models in `src/Component/Model/` that reference OXID core tables via foreign keys without extending them.

## Priority
**P1 - High**

## Story Points
**8 points** (2 days)

## Business Value
Provides core data models for transaction tracking and order lifecycle management with minimal coupling to OXID core.

---

## Description

Create Component models with FK references to OXID core:
- PaymentTransaction (core transaction model)
- PaymentOrderState (order payment state, 1:1 with oxorder)
- PaymentCustomer (customer payment data, 1:1 with oxuser)
- PaymentBasketSnapshot (basket state at payment time)
- PaymentOrderStates (state constants interface)

**Architecture Principle:** Models reference oxorder/oxuser via OXID field, NOT via class extension.

---

## Acceptance Criteria

### Must Have
- [ ] PaymentTransaction model in `src/Component/Model/`
- [ ] PaymentOrderState model in `src/Component/Model/`
- [ ] PaymentCustomer model in `src/Component/Model/`
- [ ] PaymentBasketSnapshot model in `src/Component/Model/`
- [ ] PaymentOrderStates interface in `src/Component/Model/`
- [ ] State machine logic with validation
- [ ] 100% test coverage
- [ ] Database migrations tested
- [ ] NO OXID class extensions in metadata.php

### Should Have
- [ ] State transition diagram
- [ ] Model builders for tests

---

## Technical Details

### PaymentTransaction Model (unchanged)

```php
<?php
// src/Component/Model/PaymentTransaction.php

namespace Osc\Payment\Component\Model;

use DateTimeImmutable;

/**
 * Payment Transaction
 *
 * Represents a single payment transaction.
 * Provider-agnostic - works with Stripe, Paymenter, etc.
 */
final class PaymentTransaction
{
    private ?string $id = null;
    private string $shopId;
    private string $orderId;        // FK to oxorder.OXID
    private string $providerOrderId;
    private ?string $transactionId = null;
    private string $status;
    private string $paymentMethodId;
    private string $transactionType;
    private ?array $providerData = null;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $shopId,
        string $orderId,
        string $providerOrderId,
        string $status,
        string $paymentMethodId,
        string $transactionType
    ) {
        $this->validateTransactionType($transactionType);

        $this->shopId = $shopId;
        $this->orderId = $orderId;
        $this->providerOrderId = $providerOrderId;
        $this->status = $status;
        $this->paymentMethodId = $paymentMethodId;
        $this->transactionType = $transactionType;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    // Getters
    public function getId(): ?string { return $this->id; }
    public function getOrderId(): string { return $this->orderId; }
    public function getProviderOrderId(): string { return $this->providerOrderId; }
    public function getStatus(): string { return $this->status; }

    // Setters
    public function setId(string $id): void { $this->id = $id; }
    public function setStatus(string $status): void { $this->status = $status; }

    // State checks
    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isRefunded(): bool { return $this->status === 'refunded'; }
    public function isPending(): bool { return $this->status === 'pending'; }

    private function validateTransactionType(string $type): void
    {
        $valid = ['capture', 'authorization', 'refund', 'void'];
        if (!in_array($type, $valid, true)) {
            throw new \InvalidArgumentException("Invalid type: $type");
        }
    }
}
```

### PaymentOrderState Model (NEW - replaces extending oxorder)

```php
<?php
// src/Component/Model/PaymentOrderState.php

namespace Osc\Payment\Component\Model;

use DateTimeImmutable;

/**
 * Payment Order State
 *
 * Component-level order payment state tracking (1:1 with oxorder).
 * Replaces extending oxorder table.
 */
final class PaymentOrderState implements PaymentOrderStates
{
    private ?string $id = null;
    private string $orderId;                // FK to oxorder.OXID (1:1)
    private string $paymentState;
    private ?string $providerOrderId = null;
    private ?\DateTime $webhookWaitSince = null;
    private ?int $webhookTimeout = null;
    private ?\DateTime $lastPaymentAttempt = null;
    private int $paymentAttemptCount = 0;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(string $orderId, string $paymentState = self::STATE_NOT_FINISHED)
    {
        $this->validateState($paymentState);
        $this->orderId = $orderId;
        $this->paymentState = $paymentState;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    // State machine methods
    public function markAsPaymentInProgress(): void
    {
        $this->validateStateTransition(self::STATE_PAYMENT_IN_PROGRESS);
        $this->paymentState = self::STATE_PAYMENT_IN_PROGRESS;
        $this->lastPaymentAttempt = new \DateTime();
        $this->paymentAttemptCount++;
    }

    public function markAsWaitingForWebhook(): void
    {
        $this->validateStateTransition(self::STATE_WAITING_FOR_WEBHOOK);
        $this->paymentState = self::STATE_WAITING_FOR_WEBHOOK;
        $this->webhookWaitSince = new \DateTime();
        $this->webhookTimeout = 300; // 5 minutes default
    }

    public function markAsCompleted(): void
    {
        $this->validateStateTransition(self::STATE_OK);
        $this->paymentState = self::STATE_OK;
        $this->webhookWaitSince = null;
    }

    public function markAsFailed(string $reason): void
    {
        $this->paymentState = self::STATE_ERROR;
    }

    // Getters
    public function getOrderId(): string { return $this->orderId; }
    public function getPaymentState(): string { return $this->paymentState; }
    public function isWaitingForWebhook(): bool {
        return $this->paymentState === self::STATE_WAITING_FOR_WEBHOOK;
    }

    private function validateState(string $state): void
    {
        if (!in_array($state, self::VALID_STATES, true)) {
            throw new \InvalidArgumentException("Invalid payment state: $state");
        }
    }

    private function validateStateTransition(string $newState): void
    {
        $validTransitions = $this->getValidTransitions();
        if (!in_array($newState, $validTransitions, true)) {
            throw new \InvalidArgumentException(
                "Invalid transition from {$this->paymentState} to $newState"
            );
        }
    }

    private function getValidTransitions(): array
    {
        return match($this->paymentState) {
            self::STATE_NOT_FINISHED => [self::STATE_PAYMENT_IN_PROGRESS],
            self::STATE_PAYMENT_IN_PROGRESS => [
                self::STATE_WAITING_FOR_WEBHOOK,
                self::STATE_OK,
                self::STATE_ERROR
            ],
            self::STATE_WAITING_FOR_WEBHOOK => [self::STATE_OK, self::STATE_ERROR],
            default => [],
        };
    }
}
```

### PaymentCustomer Model (NEW - replaces extending oxuser)

```php
<?php
// src/Component/Model/PaymentCustomer.php

namespace Osc\Payment\Component\Model;

use DateTimeImmutable;

/**
 * Payment Customer
 *
 * Component-level payment customer data (1:1 with oxuser).
 * Replaces extending oxuser table.
 */
final class PaymentCustomer
{
    private ?string $id = null;
    private string $userId;                  // FK to oxuser.OXID (1:1)
    private ?string $paymentCustomerId = null; // Provider customer ID (e.g., cus_xxx for Stripe)
    private ?string $defaultPaymentMethod = null;
    private array $savedPaymentMethods = [];
    private bool $billingAgreement = false;
    private ?\DateTime $lastPaymentDate = null;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(string $userId)
    {
        $this->userId = $userId;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    // Getters
    public function getUserId(): string { return $this->userId; }
    public function getPaymentCustomerId(): ?string { return $this->paymentCustomerId; }

    // Setters
    public function setPaymentCustomerId(string $customerId): void {
        $this->paymentCustomerId = $customerId;
    }

    public function addSavedPaymentMethod(string $methodId): void {
        if (!in_array($methodId, $this->savedPaymentMethods, true)) {
            $this->savedPaymentMethods[] = $methodId;
        }
    }
}
```

### PaymentBasketSnapshot Model (NEW)

```php
<?php
// src/Component/Model/PaymentBasketSnapshot.php

namespace Osc\Payment\Component\Model;

use DateTimeImmutable;

/**
 * Payment Basket Snapshot
 *
 * Stores basket state at payment initiation time for reconciliation.
 */
final class PaymentBasketSnapshot
{
    private ?string $id = null;
    private string $orderId;         // FK to oxorder.OXID
    private ?string $userId = null;  // FK to oxuser.OXID
    private array $basketData;       // JSON snapshot
    private float $total;
    private string $currency;
    private ?float $discount = null;
    private ?float $shipping = null;
    private ?float $tax = null;
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $orderId,
        array $basketData,
        float $total,
        string $currency,
        ?string $userId = null
    ) {
        $this->orderId = $orderId;
        $this->basketData = $basketData;
        $this->total = $total;
        $this->currency = $currency;
        $this->userId = $userId;
        $this->createdAt = new DateTimeImmutable();
    }

    // Getters
    public function getOrderId(): string { return $this->orderId; }
    public function getBasketData(): array { return $this->basketData; }
    public function getTotal(): float { return $this->total; }

    // Validation
    public function matchesTotal(float $providedTotal, float $tolerance = 0.01): bool {
        return abs($this->total - $providedTotal) <= $tolerance;
    }
}
```

### PaymentOrderStates Interface

```php
<?php
// src/Component/Model/PaymentOrderStates.php

namespace Osc\Payment\Component\Model;

/**
 * Payment Order States Interface
 *
 * Defines payment lifecycle states for orders.
 */
interface PaymentOrderStates
{
    const STATE_NOT_FINISHED = 'NOT_FINISHED';
    const STATE_PAYMENT_IN_PROGRESS = '500';
    const STATE_WAITING_FOR_WEBHOOK = '600';
    const STATE_OK = 'OK';
    const STATE_ERROR = 'ERROR';

    const VALID_STATES = [
        self::STATE_NOT_FINISHED,
        self::STATE_PAYMENT_IN_PROGRESS,
        self::STATE_WAITING_FOR_WEBHOOK,
        self::STATE_OK,
        self::STATE_ERROR,
    ];
}
```

---

## TDD Workflow

Write tests in `tests/Component/Unit/Component/Model/` for:
- PaymentTransaction creation, validation, state changes
- PaymentOrderState state machine transitions
- PaymentCustomer payment method management
- PaymentBasketSnapshot total matching

---

## Tasks Breakdown

1. **PaymentTransaction Model** (1 hour)
   - Write model tests
   - Implement model
   - Test validation

2. **PaymentOrderState Model** (3 hours)
   - Define PaymentOrderStates interface
   - Write state transition tests
   - Implement PaymentOrderState
   - Test all transitions

3. **PaymentCustomer & BasketSnapshot** (2 hours)
   - Implement PaymentCustomer
   - Implement PaymentBasketSnapshot
   - Write tests

4. **Integration with DB** (2 hours)
   - Test models persist correctly to new tables
   - Test state machine with real DB
   - Verify FK constraints work

---

## Definition of Done

- [ ] All models in `src/Component/Model/`
- [ ] 100% test coverage
- [ ] State machine fully tested
- [ ] Integration tests pass
- [ ] PHPStan passes
- [ ] NO class extensions in metadata.php

---

# TICKET-004: Component Repositories (Data Access Layer)

## Summary
Implement repository pattern in `src/Component/Repository/` for PaymentTransaction and Order data access.

## Priority
**P1 - High**

## Story Points
**5 points** (1.5 days)

## Business Value
Provides clean data access abstraction for transaction and order management.

---

## Description

Create Component repositories:
- PaymentTransactionRepository
- OrderRepository
- Repository interfaces

All in `src/Component/Repository/` as they're provider-agnostic.

---

## Acceptance Criteria

### Must Have
- [ ] PaymentTransactionRepositoryInterface in `src/Component/Contract/`
- [ ] PaymentTransactionRepository in `src/Component/Repository/`
- [ ] OrderRepositoryInterface in `src/Component/Contract/`
- [ ] OrderRepository in `src/Component/Repository/`
- [ ] CRUD operations for PaymentTransaction
- [ ] Query methods (by order ID, provider ID, transaction ID)
- [ ] 100% test coverage with real database

---

## Technical Details

### Repository Interface

```php
<?php
// src/Component/Contract/PaymentTransactionRepositoryInterface.php

namespace Osc\Payment\Component\Contract;

use Osc\Payment\Component\Model\PaymentTransaction;

interface PaymentTransactionRepositoryInterface
{
    public function save(PaymentTransaction $transaction): void;
    public function findById(string $id): ?PaymentTransaction;
    public function findByOrderAndProvider(string $orderId, string $providerOrderId): ?PaymentTransaction;
    public function findAllByOrderId(string $orderId): array;
    public function findByTransactionId(string $transactionId): ?PaymentTransaction;
    public function delete(PaymentTransaction $transaction): void;
}
```

### Repository Implementation

```php
<?php
// src/Component/Repository/PaymentTransactionRepository.php

namespace Osc\Payment\Component\Repository;

use Osc\Payment\Component\Contract\PaymentTransactionRepositoryInterface;
use Osc\Payment\Component\Model\PaymentTransaction;
use OxidEsales\Eshop\Core\Database\Adapter\DatabaseInterface;

class PaymentTransactionRepository implements PaymentTransactionRepositoryInterface
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function save(PaymentTransaction $transaction): void
    {
        if ($transaction->getId() === null) {
            $this->insert($transaction);
        } else {
            $this->update($transaction);
        }
    }

    private function insert(PaymentTransaction $transaction): void
    {
        $id = $this->generateId();
        $transaction->setId($id);

        $sql = "INSERT INTO oe_payments_transaction
                (OXID, OXSHOPID, OXORDERID, OXPROVIDERORDERID, OXSTATUS,
                 OXPAYMENTMETHODID, OXTRANSACTIONTYPE)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        $this->db->execute($sql, [
            $id,
            $transaction->getShopId(),
            $transaction->getOrderId(),
            $transaction->getProviderOrderId(),
            $transaction->getStatus(),
            $transaction->getPaymentMethodId(),
            $transaction->getTransactionType(),
        ]);
    }

    private function update(PaymentTransaction $transaction): void
    {
        $sql = "UPDATE oe_payments_transaction
                SET OXSTATUS = ?,
                    OXTRANSACTIONID = ?,
                    OXPROVIDERDATA = ?
                WHERE OXID = ?";

        $this->db->execute($sql, [
            $transaction->getStatus(),
            $transaction->getTransactionId(),
            json_encode($transaction->getProviderData()),
            $transaction->getId(),
        ]);
    }

    public function findByOrderAndProvider(string $orderId, string $providerOrderId): ?PaymentTransaction
    {
        $sql = "SELECT * FROM oe_payments_transaction
                WHERE OXORDERID = ? AND OXPROVIDERORDERID = ?
                LIMIT 1";

        $row = $this->db->getRow($sql, [$orderId, $providerOrderId]);

        return $row ? $this->hydrate($row) : null;
    }

    public function findAllByOrderId(string $orderId): array
    {
        $sql = "SELECT * FROM oe_payments_transaction
                WHERE OXORDERID = ?
                ORDER BY OXTIMESTAMP DESC";

        $rows = $this->db->getAll($sql, [$orderId]);

        return array_map([$this, 'hydrate'], $rows);
    }

    private function hydrate(array $row): PaymentTransaction
    {
        $transaction = new PaymentTransaction(
            $row['OXSHOPID'],
            $row['OXORDERID'],
            $row['OXPROVIDERORDERID'],
            $row['OXSTATUS'],
            $row['OXPAYMENTMETHODID'],
            $row['OXTRANSACTIONTYPE']
        );

        $transaction->setId($row['OXID']);
        if ($row['OXTRANSACTIONID']) {
            $transaction->setTransactionId($row['OXTRANSACTIONID']);
        }
        if ($row['OXPROVIDERDATA']) {
            $transaction->setProviderData(json_decode($row['OXPROVIDERDATA'], true));
        }

        return $transaction;
    }

    private function generateId(): string
    {
        return md5(uniqid((string)mt_rand(), true));
    }
}
```

---

## TDD Workflow

Write integration tests in `tests/Component/Integration/Component/Repository/`:
- PaymentTransactionRepositoryTest (CRUD operations)
- OrderRepositoryTest (order queries)

Use real database (SQLite for tests).

---

## Tasks Breakdown

1. **Repository Interfaces** (1 hour)
   - Define interfaces
   - Document methods

2. **PaymentTransactionRepository** (3 hours)
   - Write integration tests
   - Implement repository
   - Test CRUD operations
   - Test query methods

3. **OrderRepository** (2 hours)
   - Write integration tests
   - Implement repository
   - Test order queries

4. **Performance** (1 hour)
   - Add database indexes
   - Test query performance

---

## Definition of Done

- [ ] Repositories in `src/Component/Repository/`
- [ ] Interfaces in `src/Component/Contract/`
- [ ] 100% integration test coverage
- [ ] All CRUD operations tested
- [ ] Performance tests pass

---

# TICKET-005: SDK-Adapter Layer (Provider Abstraction)

## Summary
Implement SDK-Adapter layer in `src/Component/Adapter/` that provides a unified, provider-agnostic interface for payment provider SDK integration.

## Priority
**P1 - High** (Blocks TICKET-006)

## Story Points
**8 points** (2 days)

## Business Value
Provides a clean abstraction layer between the payment component and provider-specific SDKs (Stripe, Unzer, PayPal), making it easy to add new providers and switch between them.

---

## Description

Create SDK-Adapter layer:
- PaymentAdapterInterface (provider-agnostic contract)
- Request/Response DTOs (normalized data structures)
- StripeAdapter (Stripe SDK implementation)
- AdapterFactory (configuration-driven adapter creation)
- Unified exception handling

All in `src/Component/Adapter/` namespace (100% reusable), with provider-specific adapters in respective provider namespaces.

---

## Acceptance Criteria

### Must Have
- [ ] PaymentAdapterInterface in `src/Component/Contract/`
- [ ] Request objects in `src/Component/Adapter/Request/`
  - [ ] CreatePaymentRequest
  - [ ] CapturePaymentRequest
  - [ ] RefundPaymentRequest
  - [ ] VoidPaymentRequest
- [ ] Response objects in `src/Component/Adapter/Response/`
  - [ ] PaymentResponse
  - [ ] CaptureResponse
  - [ ] RefundResponse
  - [ ] PaymentDetailsResponse
- [ ] WebhookEvent interface in `src/Component/Adapter/`
- [ ] PaymentAdapterException hierarchy in `src/Component/Adapter/Exception/`
- [ ] StripeAdapter in `src/Stripe/Adapter/`
- [ ] AdapterFactory in `src/Component/Adapter/`
- [ ] 100% unit test coverage for interface & DTOs
- [ ] 90% unit test coverage for StripeAdapter (with mocked Stripe SDK)
- [ ] Integration tests with real Stripe API in sandbox mode

### Should Have
- [ ] UnzerAdapter stub in `src/Unzer/Adapter/`
- [ ] PayPalAdapter stub in `src/PayPal/Adapter/`
- [ ] Adapter feature detection (`supportsFeature()`)

---

## Technical Details

### PaymentAdapterInterface

```php
<?php
// src/Component/Contract/PaymentAdapterInterface.php

namespace Osc\Payment\Component\Contract;

use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use Osc\Payment\Component\Adapter\Request\CapturePaymentRequest;
use Osc\Payment\Component\Adapter\Request\RefundPaymentRequest;
use Osc\Payment\Component\Adapter\Request\VoidPaymentRequest;
use Osc\Payment\Component\Adapter\Response\PaymentResponse;
use Osc\Payment\Component\Adapter\Response\CaptureResponse;
use Osc\Payment\Component\Adapter\Response\RefundResponse;
use Osc\Payment\Component\Adapter\Response\VoidResponse;
use Osc\Payment\Component\Adapter\Response\PaymentDetailsResponse;
use Osc\Payment\Component\Adapter\WebhookEvent;

/**
 * Payment Adapter Interface
 *
 * Provider-agnostic contract for payment provider integration.
 * All providers (Stripe, Unzer, PayPal, etc.) implement this interface.
 */
interface PaymentAdapterInterface
{
    /**
     * Create a payment (authorization or direct capture)
     */
    public function createPayment(CreatePaymentRequest $request): PaymentResponse;

    /**
     * Capture an authorized payment
     */
    public function capturePayment(CapturePaymentRequest $request): CaptureResponse;

    /**
     * Refund a captured payment
     */
    public function refundPayment(RefundPaymentRequest $request): RefundResponse;

    /**
     * Void/cancel an authorized payment
     */
    public function voidPayment(VoidPaymentRequest $request): VoidResponse;

    /**
     * Get payment details by provider payment ID
     */
    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse;

    /**
     * Get supported payment methods
     * @return array<string, array> [method_id => ['name' => '...', 'type' => '...']]
     */
    public function getSupportedPaymentMethods(): array;

    /**
     * Parse and verify webhook payload
     * @throws PaymentAdapterException on invalid signature
     */
    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent;

    /**
     * Get provider name (stripe, unzer, paypal, etc.)
     */
    public function getProviderName(): string;

    /**
     * Check if provider supports a specific feature
     * @param string $feature (e.g., 'separate_authorization', 'refunds', 'recurring')
     */
    public function supportsFeature(string $feature): bool;
}
```

### Request/Response DTOs

```php
<?php
// src/Component/Adapter/Request/CreatePaymentRequest.php

namespace Osc\Payment\Component\Adapter\Request;

/**
 * Create Payment Request
 *
 * Provider-agnostic request for creating a payment.
 * Adapters translate this to provider-specific formats.
 */
final readonly class CreatePaymentRequest
{
    public function __construct(
        public float $amount,
        public string $currency,
        public string $orderId,
        public string $shopId,
        public string $paymentMethod,
        public bool $directCapture = true,
        public ?string $paymentMethodId = null,
        public ?string $customerId = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public array $metadata = []
    ) {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('Currency must be 3-letter ISO code');
        }
    }

    public function getAmount(): float { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getOrderId(): string { return $this->orderId; }
    public function getShopId(): string { return $this->shopId; }
    public function getPaymentMethod(): string { return $this->paymentMethod; }
    public function isDirectCapture(): bool { return $this->directCapture; }
    public function getPaymentMethodId(): ?string { return $this->paymentMethodId; }
    public function getCustomerId(): ?string { return $this->customerId; }
    public function getReturnUrl(): ?string { return $this->returnUrl; }
    public function getCancelUrl(): ?string { return $this->cancelUrl; }
    public function getMetadata(): array { return $this->metadata; }
}
```

```php
<?php
// src/Component/Adapter/Response/PaymentResponse.php

namespace Osc\Payment\Component\Adapter\Response;

/**
 * Payment Response
 *
 * Provider-agnostic response after creating a payment.
 * Adapters translate from provider-specific formats to this.
 */
final readonly class PaymentResponse
{
    public function __construct(
        public string $providerPaymentId,
        public string $status,
        public float $amount,
        public string $currency,
        public ?string $clientSecret = null,
        public bool $requiresAction = false,
        public ?string $nextActionUrl = null,
        public array $metadata = []
    ) {}

    public function getProviderPaymentId(): string { return $this->providerPaymentId; }
    public function getStatus(): string { return $this->status; }
    public function getAmount(): float { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getClientSecret(): ?string { return $this->clientSecret; }
    public function requiresAction(): bool { return $this->requiresAction; }
    public function getNextActionUrl(): ?string { return $this->nextActionUrl; }
    public function getMetadata(): array { return $this->metadata; }

    // Status helpers
    public function isPending(): bool { return $this->status === 'pending'; }
    public function isAuthorized(): bool { return $this->status === 'authorized'; }
    public function isCaptured(): bool { return $this->status === 'captured'; }
    public function isFailed(): bool { return $this->status === 'failed'; }
}
```

### StripeAdapter Implementation

```php
<?php
// src/Stripe/Adapter/StripeAdapter.php

namespace OxidSolutionCatalysts\Stripe\Adapter;

use Osc\Payment\Component\Contract\PaymentAdapterInterface;
use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use Osc\Payment\Component\Adapter\Response\PaymentResponse;
use Osc\Payment\Component\Adapter\Exception\PaymentAdapterException;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe Adapter
 *
 * Translates between component requests/responses and Stripe SDK.
 */
final class StripeAdapter implements PaymentAdapterInterface
{
    private StripeClient $client;
    private bool $sandbox;

    public function __construct(string $apiKey, bool $sandbox = false)
    {
        $this->client = new StripeClient($apiKey);
        $this->sandbox = $sandbox;
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        try {
            // Translate component request → Stripe format
            $intent = $this->client->paymentIntents->create([
                'amount' => $this->convertAmountToCents($request->getAmount()),
                'currency' => strtolower($request->getCurrency()),
                'capture_method' => $request->isDirectCapture() ? 'automatic' : 'manual',
                'payment_method' => $request->getPaymentMethodId(),
                'customer' => $request->getCustomerId(),
                'metadata' => array_merge(
                    $request->getMetadata(),
                    [
                        'order_id' => $request->getOrderId(),
                        'shop_id' => $request->getShopId(),
                    ]
                ),
            ]);

            // Translate Stripe response → component format
            return new PaymentResponse(
                providerPaymentId: $intent->id,
                status: $this->mapStripeStatus($intent->status),
                amount: $this->convertCentsToAmount($intent->amount),
                currency: strtoupper($intent->currency),
                clientSecret: $intent->client_secret,
                requiresAction: $intent->status === 'requires_action',
                nextActionUrl: $intent->next_action->redirect_to_url->url ?? null
            );

        } catch (ApiErrorException $e) {
            throw PaymentAdapterException::fromProviderError(
                provider: 'stripe',
                message: $e->getMessage(),
                code: $e->getStripeCode() ?? 'unknown',
                previous: $e
            );
        }
    }

    public function capturePayment(CapturePaymentRequest $request): CaptureResponse
    {
        try {
            $intent = $this->client->paymentIntents->capture(
                $request->getProviderPaymentId(),
                ['amount_to_capture' => $this->convertAmountToCents($request->getAmount())]
            );

            return new CaptureResponse(
                providerPaymentId: $intent->id,
                captureId: $intent->charges->data[0]->id ?? $intent->id,
                status: $this->mapStripeStatus($intent->status),
                amount: $this->convertCentsToAmount($intent->amount_received),
                currency: strtoupper($intent->currency)
            );

        } catch (ApiErrorException $e) {
            throw PaymentAdapterException::fromProviderError('stripe', $e->getMessage(), $e->getStripeCode() ?? 'unknown', $e);
        }
    }

    public function getProviderName(): string
    {
        return 'stripe';
    }

    public function supportsFeature(string $feature): bool
    {
        return match($feature) {
            'separate_authorization', 'refunds', 'recurring', 'webhooks' => true,
            default => false,
        };
    }

    // Private translation methods

    private function convertAmountToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function convertCentsToAmount(int $cents): float
    {
        return $cents / 100.0;
    }

    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'requires_payment_method', 'requires_confirmation' => 'pending',
            'requires_action' => 'requires_action',
            'requires_capture' => 'authorized',
            'succeeded' => 'captured',
            'canceled' => 'canceled',
            default => 'unknown',
        };
    }
}
```

---

## TDD Workflow

### Step 1: RED - Write Interface Tests

```php
<?php
// tests/Component/Unit/Component/Adapter/Request/CreatePaymentRequestTest.php

namespace OxidSolutionCatalysts\Stripe\Tests\Unit\Component\Adapter\Request;

use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use PHPUnit\Framework\TestCase;

class CreatePaymentRequestTest extends TestCase
{
    /** @test */
    public function it_creates_valid_request(): void
    {
        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order123',
            shopId: '1',
            paymentMethod: 'card'
        );

        $this->assertEquals(99.99, $request->getAmount());
        $this->assertEquals('EUR', $request->getCurrency());
        $this->assertTrue($request->isDirectCapture());
    }

    /** @test */
    public function it_validates_amount_is_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        new CreatePaymentRequest(
            amount: -10.00,
            currency: 'EUR',
            orderId: 'order123',
            shopId: '1',
            paymentMethod: 'card'
        );
    }

    /** @test */
    public function it_validates_currency_is_three_letters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EURO',
            orderId: 'order123',
            shopId: '1',
            paymentMethod: 'card'
        );
    }

    /** @test */
    public function it_is_immutable_after_creation(): void
    {
        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order123',
            shopId: '1',
            paymentMethod: 'card'
        );

        // Should not have setters
        $this->assertFalse(method_exists($request, 'setAmount'));
        $this->assertFalse(method_exists($request, 'setCurrency'));
    }
}
```

### Step 2: GREEN - Implement DTOs

Implement all request/response objects with validation.

### Step 3: RED - Write Adapter Tests

```php
<?php
// tests/Component/Unit/Stripe/Adapter/StripeAdapterTest.php

namespace OxidSolutionCatalysts\Stripe\Tests\Unit\Stripe\Adapter;

use Mockery;
use OxidSolutionCatalysts\Stripe\Adapter\StripeAdapter;
use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;

class StripeAdapterTest extends TestCase
{
    /** @test */
    public function it_creates_payment_and_translates_to_component_format(): void
    {
        // Mock Stripe SDK
        $stripeMock = Mockery::mock(StripeClient::class);
        $stripeMock->paymentIntents = Mockery::mock();

        $stripeMock->paymentIntents
            ->shouldReceive('create')
            ->once()
            ->with([
                'amount' => 9999, // cents
                'currency' => 'eur', // lowercase
                'capture_method' => 'automatic',
                'payment_method' => null,
                'customer' => null,
                'metadata' => ['order_id' => 'order123', 'shop_id' => '1'],
            ])
            ->andReturn((object)[
                'id' => 'pi_123',
                'status' => 'requires_capture',
                'amount' => 9999,
                'currency' => 'eur',
                'client_secret' => 'pi_123_secret',
            ]);

        $adapter = new StripeAdapter('sk_test_123');
        // Inject mock (need to add setter for testing)

        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order123',
            shopId: '1',
            paymentMethod: 'card',
            directCapture: true
        );

        $response = $adapter->createPayment($request);

        $this->assertEquals('pi_123', $response->getProviderPaymentId());
        $this->assertEquals('authorized', $response->getStatus());
        $this->assertEquals(99.99, $response->getAmount());
        $this->assertEquals('EUR', $response->getCurrency());
    }
}
```

### Step 4: GREEN - Implement StripeAdapter

### Step 5: Integration Tests with Real Stripe API

```php
<?php
// tests/Component/Integration/Stripe/Adapter/StripeAdapterIntegrationTest.php

namespace OxidSolutionCatalysts\Stripe\Tests\Integration\Stripe\Adapter;

use OxidSolutionCatalysts\Stripe\Adapter\StripeAdapter;
use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use PHPUnit\Framework\TestCase;

class StripeAdapterIntegrationTest extends TestCase
{
    private StripeAdapter $adapter;

    protected function setUp(): void
    {
        $apiKey = $_ENV['STRIPE_TEST_KEY'] ?? $this->markTestSkipped('Stripe test key not configured');
        $this->adapter = new StripeAdapter($apiKey, sandbox: true);
    }

    /** @test */
    public function it_creates_payment_with_real_stripe_api(): void
    {
        $request = new CreatePaymentRequest(
            amount: 10.00,
            currency: 'EUR',
            orderId: 'test_order_' . time(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $response = $this->adapter->createPayment($request);

        $this->assertStringStartsWith('pi_', $response->getProviderPaymentId());
        $this->assertEquals('authorized', $response->getStatus());
        $this->assertEquals(10.00, $response->getAmount());
    }
}
```

---

## Tasks Breakdown

1. **Adapter Interface & Request/Response DTOs** (3 hours)
   - [ ] Define PaymentAdapterInterface
   - [ ] Create all request objects (CreatePayment, Capture, Refund, Void)
   - [ ] Create all response objects (Payment, Capture, Refund, PaymentDetails)
   - [ ] Write comprehensive unit tests
   - [ ] Test immutability and validation

2. **Exception Handling** (1 hour)
   - [ ] Create PaymentAdapterException base class
   - [ ] Create specific exceptions (CardDeclined, AuthenticationRequired, NetworkException)
   - [ ] Write exception tests

3. **StripeAdapter Implementation** (4 hours)
   - [ ] Implement all PaymentAdapterInterface methods
   - [ ] Add private translation methods (amount, currency, status mapping)
   - [ ] Write unit tests with mocked Stripe SDK
   - [ ] Test error handling and exception mapping

4. **AdapterFactory** (2 hours)
   - [ ] Create AdapterFactory with configuration-driven creation
   - [ ] Support multiple providers (Stripe, Unzer, PayPal)
   - [ ] Write factory tests
   - [ ] Test default adapter selection

5. **Integration Tests** (2 hours)
   - [ ] Test StripeAdapter with real Stripe API (sandbox)
   - [ ] Test full payment flow: create → capture → refund
   - [ ] Test webhook parsing with real Stripe events
   - [ ] Document test API keys setup

6. **Documentation** (1 hour)
   - [ ] Document adapter pattern benefits
   - [ ] Create examples of adding new providers
   - [ ] Document testing strategy

---

## Definition of Done

- [ ] All acceptance criteria met
- [ ] PaymentAdapterInterface and DTOs implemented
- [ ] StripeAdapter fully implemented
- [ ] AdapterFactory with configuration support
- [ ] 100% unit test coverage for interface & DTOs
- [ ] 90% unit test coverage for StripeAdapter
- [ ] Integration tests pass with real Stripe API (sandbox)
- [ ] PHPStan level 6 passes
- [ ] Documentation complete
- [ ] PR reviewed and approved

---

## Dependencies

- TICKET-004 (needs repositories for transaction persistence)

---

## Related Tickets

- Blocks TICKET-006 (Stripe Payment Service will use adapter)

---

# TICKET-006: Stripe Payment Service (Using SDK-Adapter)

## Summary
Implement Stripe-specific payment service in `src/Stripe/Service/` that uses SDK-Adapter layer.

## Priority
**P1 - High**

## Story Points
**5 points** (1.5 days)

## Business Value
Completes the first provider implementation (Stripe), demonstrating how Component + SDK-Adapter + Provider layers work together.

---

## Description

Create Stripe implementation that uses SDK-Adapter:
- StripePaymentService (uses StripeAdapter)
- StripeSettings (module configuration)
- Stripe event handler (payment initiation)

All in `src/Stripe/` namespace.

---

## Acceptance Criteria

### Must Have
- [ ] PaymentService in `src/Component/Service/` (uses adapter interface)
- [ ] StripePaymentService in `src/Stripe/Service/` (facade over StripeAdapter)
- [ ] StripeSettings in `src/Stripe/Service/`
- [ ] PaymentInitiationHandler in `src/Stripe/Handler/` (uses adapter)
- [ ] Uses Component events, models, and SDK-Adapter
- [ ] 95% test coverage with mocked adapter

### Should Have
- [ ] Payment state machine integration
- [ ] Transaction persistence via repositories
- [ ] Error handling and logging

---

## Technical Details

### Component PaymentService (Uses Adapter)

```php
<?php
// src/Component/Service/PaymentService.php

namespace Osc\Payment\Component\Service;

use Osc\Payment\Component\Contract\PaymentAdapterInterface;
use Osc\Payment\Component\Contract\PaymentTransactionRepositoryInterface;
use Osc\Payment\Component\Contract\EventDispatcherInterface;
use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use Osc\Payment\Component\Adapter\Request\CapturePaymentRequest;
use Osc\Payment\Component\Adapter\Request\RefundPaymentRequest;
use Osc\Payment\Component\Adapter\Response\PaymentResponse;
use Osc\Payment\Component\Adapter\Exception\PaymentAdapterException;
use Osc\Payment\Component\Model\PaymentTransaction;

/**
 * Payment Service (Component Layer)
 *
 * Provider-agnostic payment service that uses SDK-Adapter.
 * 100% reusable across all payment providers.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentAdapterInterface $adapter,
        private readonly PaymentTransactionRepositoryInterface $transactionRepo,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * Initiate a payment (authorization or direct capture)
     */
    public function initiatePayment(
        string $orderId,
        string $shopId,
        float $amount,
        string $currency,
        string $paymentMethod,
        bool $directCapture = true
    ): PaymentResponse {
        try {
            // Create adapter request (provider-agnostic)
            $request = new CreatePaymentRequest(
                amount: $amount,
                currency: $currency,
                orderId: $orderId,
                shopId: $shopId,
                paymentMethod: $paymentMethod,
                directCapture: $directCapture
            );

            // Call adapter (works with any provider)
            $response = $this->adapter->createPayment($request);

            // Track transaction in component DB
            $transaction = new PaymentTransaction(
                shopId: $shopId,
                orderId: $orderId,
                providerOrderId: $response->getProviderPaymentId(),
                status: $response->getStatus(),
                paymentMethodId: $paymentMethod,
                transactionType: $directCapture ? 'capture' : 'authorization'
            );
            $this->transactionRepo->save($transaction);

            // Dispatch event
            $this->eventDispatcher->dispatch(
                new PaymentInitiatedEvent($orderId, $response->getProviderPaymentId(), $response->getStatus())
            );

            return $response;

        } catch (PaymentAdapterException $e) {
            // Unified error handling for all providers
            throw new PaymentException(
                "Payment initiation failed: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Capture an authorized payment
     */
    public function capturePayment(string $providerPaymentId, float $amount): CaptureResponse
    {
        try {
            $request = new CapturePaymentRequest(
                providerPaymentId: $providerPaymentId,
                amount: $amount
            );

            $response = $this->adapter->capturePayment($request);

            // Update transaction status
            $transaction = $this->transactionRepo->findByProviderPaymentId($providerPaymentId);
            if ($transaction) {
                $transaction->setStatus('captured');
                $this->transactionRepo->save($transaction);
            }

            return $response;

        } catch (PaymentAdapterException $e) {
            throw new PaymentException("Payment capture failed: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Refund a captured payment
     */
    public function refundPayment(
        string $providerPaymentId,
        float $amount,
        ?string $reason = null
    ): RefundResponse {
        try {
            $request = new RefundPaymentRequest(
                providerPaymentId: $providerPaymentId,
                amount: $amount,
                reason: $reason
            );

            $response = $this->adapter->refundPayment($request);

            // Create refund transaction record
            $transaction = new PaymentTransaction(
                shopId: '1', // Get from original transaction
                orderId: 'order123', // Get from original transaction
                providerOrderId: $providerPaymentId,
                status: 'refunded',
                paymentMethodId: 'refund',
                transactionType: 'refund'
            );
            $this->transactionRepo->save($transaction);

            return $response;

        } catch (PaymentAdapterException $e) {
            throw new PaymentException("Refund failed: {$e->getMessage()}", previous: $e);
        }
    }
}
```

### Stripe Event Handler (Uses Adapter)

```php
<?php
// src/Stripe/Handler/PaymentInitiationHandler.php

namespace OxidSolutionCatalysts\Stripe\Handler;

use Osc\Payment\Component\Event\Domain\PaymentInitiatedEvent;
use Osc\Payment\Component\Service\PaymentService;

/**
 * Stripe Payment Initiation Handler
 *
 * Listens to PaymentInitiatedEvent (Component)
 * Delegates to PaymentService (which uses StripeAdapter)
 */
class PaymentInitiationHandler
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {}

    public function handle(PaymentInitiatedEvent $event): void
    {
        // PaymentService already used StripeAdapter via dependency injection
        // This handler can perform Stripe-specific post-processing if needed

        // Example: Set Stripe-specific redirect URL in event
        $event->setProviderRedirectUrl($event->getProviderOrderId());
    }
}
```

---

## TDD Workflow

### Step 1: RED - Write PaymentService Tests

```php
<?php
// tests/Component/Unit/Component/Service/PaymentServiceTest.php

namespace OxidSolutionCatalysts\Stripe\Tests\Unit\Component\Service;

use Mockery;
use Osc\Payment\Component\Service\PaymentService;
use Osc\Payment\Component\Contract\PaymentAdapterInterface;
use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use Osc\Payment\Component\Adapter\Response\PaymentResponse;
use PHPUnit\Framework\TestCase;

class PaymentServiceTest extends TestCase
{
    /** @test */
    public function it_initiates_payment_using_adapter(): void
    {
        // Arrange
        $adapterMock = Mockery::mock(PaymentAdapterInterface::class);
        $transactionRepoMock = Mockery::mock(PaymentTransactionRepositoryInterface::class);
        $eventDispatcherMock = Mockery::mock(EventDispatcherInterface::class);

        $adapterMock
            ->shouldReceive('createPayment')
            ->once()
            ->with(Mockery::on(function (CreatePaymentRequest $request) {
                return $request->getAmount() === 99.99
                    && $request->getCurrency() === 'EUR'
                    && $request->getOrderId() === 'order123';
            }))
            ->andReturn(new PaymentResponse(
                providerPaymentId: 'pi_123',
                status: 'authorized',
                amount: 99.99,
                currency: 'EUR'
            ));

        $transactionRepoMock
            ->shouldReceive('save')
            ->once();

        $eventDispatcherMock
            ->shouldReceive('dispatch')
            ->once();

        $service = new PaymentService($adapterMock, $transactionRepoMock, $eventDispatcherMock);

        // Act
        $response = $service->initiatePayment(
            orderId: 'order123',
            shopId: '1',
            amount: 99.99,
            currency: 'EUR',
            paymentMethod: 'card',
            directCapture: false
        );

        // Assert
        $this->assertEquals('pi_123', $response->getProviderPaymentId());
        $this->assertEquals('authorized', $response->getStatus());
    }

    /** @test */
    public function it_handles_adapter_exceptions(): void
    {
        $adapterMock = Mockery::mock(PaymentAdapterInterface::class);
        $transactionRepoMock = Mockery::mock(PaymentTransactionRepositoryInterface::class);
        $eventDispatcherMock = Mockery::mock(EventDispatcherInterface::class);

        $adapterMock
            ->shouldReceive('createPayment')
            ->andThrow(new PaymentAdapterException('Card declined', 'card_declined', 'stripe'));

        $service = new PaymentService($adapterMock, $transactionRepoMock, $eventDispatcherMock);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Payment initiation failed: Card declined');

        $service->initiatePayment('order123', '1', 99.99, 'EUR', 'card');
    }
}
```

### Step 2: GREEN - Implement PaymentService

### Step 3: Integration Test

```php
<?php
// tests/Component/Integration/Stripe/FullPaymentFlowWithAdapterTest.php

namespace OxidSolutionCatalysts\Stripe\Tests\Integration\Stripe;

use Osc\Payment\Component\Service\PaymentService;
use OxidSolutionCatalysts\Stripe\Adapter\StripeAdapter;
use PHPUnit\Framework\TestCase;

class FullPaymentFlowWithAdapterTest extends TestCase
{
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        $apiKey = $_ENV['STRIPE_TEST_KEY'] ?? $this->markTestSkipped('Stripe test key not configured');

        $adapter = new StripeAdapter($apiKey, sandbox: true);
        $transactionRepo = new PaymentTransactionRepository($db);
        $eventDispatcher = new EventDispatcher();

        $this->paymentService = new PaymentService($adapter, $transactionRepo, $eventDispatcher);
    }

    /** @test */
    public function it_completes_full_payment_flow(): void
    {
        // Initiate payment
        $response = $this->paymentService->initiatePayment(
            orderId: 'test_order_' . time(),
            shopId: '1',
            amount: 10.00,
            currency: 'EUR',
            paymentMethod: 'card',
            directCapture: false
        );

        $this->assertEquals('authorized', $response->getStatus());
        $providerPaymentId = $response->getProviderPaymentId();

        // Capture payment
        $captureResponse = $this->paymentService->capturePayment($providerPaymentId, 10.00);
        $this->assertEquals('captured', $captureResponse->getStatus());

        // Verify transaction was saved
        $transaction = $this->transactionRepo->findByProviderPaymentId($providerPaymentId);
        $this->assertNotNull($transaction);
        $this->assertEquals('captured', $transaction->getStatus());
    }
}
```

---

## Tasks Breakdown

1. **Component PaymentService** (3 hours)
   - [ ] Implement PaymentService using adapter interface
   - [ ] Write unit tests with mocked adapter
   - [ ] Test error handling
   - [ ] Test transaction persistence

2. **Stripe Event Handler** (1 hour)
   - [ ] Update PaymentInitiationHandler to use PaymentService
   - [ ] Write handler tests
   - [ ] Test event flow

3. **Integration** (2 hours)
   - [ ] Wire up StripeAdapter → PaymentService
   - [ ] Configure dependency injection
   - [ ] Test full flow: Event → Handler → Service → Adapter → Stripe API

4. **Documentation** (1 hour)
   - [ ] Document how PaymentService uses adapter
   - [ ] Create examples of provider switching
   - [ ] Document configuration

---

## Definition of Done

- [ ] All acceptance criteria met
- [ ] PaymentService implemented in Component layer
- [ ] Uses PaymentAdapterInterface (not Stripe-specific code)
- [ ] Stripe handler uses PaymentService
- [ ] 95% test coverage
- [ ] Integration test passes with real Stripe API
- [ ] PHPStan level 6 passes
- [ ] Documentation complete
- [ ] PR reviewed and approved

---

## Dependencies

- TICKET-005 (requires SDK-Adapter layer)

---

# TICKET-007: Authorization Service (Two-Step Auth/Capture Flow) 🔴 CRITICAL (P0)

**Type:** Feature
**Priority:** P0 (Critical - Sprint 2)
**Story Points:** 8
**Dependencies:** TICKET-005 (SDK-Adapter), TICKET-006 (PaymentService)
**Related TDD Block:** Block 5.6
**Comprehensive Analysis:** See [11-comprehensive-provider-analysis.md](11-comprehensive-provider-analysis.md) - Feature #1

---

## Description

Implement two-step authorization flow where payment is authorized first, then captured later (required by PayPal, Unzer, Stripe for certain payment methods). Supports partial capture, void, and reauthorization for expiring authorizations.

**Business Value:** Enables delayed capture workflows (capture on shipment), reduces fraud risk, supports PayPal/Unzer/TeleCash requirements.

---

## Acceptance Criteria

### 1. Authorization Service
- [ ] `AuthorizationService` class implements authorization/capture/void/reauthorization
- [ ] Authorization expiration tracking (provider-specific: PayPal 29 days, Stripe 7 days, Unzer 7 days)
- [ ] Reauthorization count tracking (PayPal: max 1 reauth, Stripe: N/A)
- [ ] Partial capture support (capture less than authorized amount)
- [ ] Multiple partial captures (sum ≤ authorized amount)
- [ ] Void authorization (cancel before capture)

### 2. Enhanced PaymentAdapterInterface
- [ ] `authorizePayment(AuthorizePaymentRequest): AuthorizationResponse`
- [ ] `captureAuthorization(CaptureAuthorizationRequest): CaptureResponse`
- [ ] `voidAuthorization(VoidAuthorizationRequest): VoidResponse`
- [ ] `reauthorizePayment(ReauthorizePaymentRequest): AuthorizationResponse`

### 3. Enhanced Transaction Table
- [ ] Add `OXAUTHORIZATION_ID` VARCHAR(128)
- [ ] Add `OXAUTHORIZATION_STATUS` VARCHAR(32) (authorized, captured, voided, expired)
- [ ] Add `OXAUTHORIZATION_EXPIRES` DATETIME
- [ ] Add `OXCAPTURED_AMOUNT` DECIMAL(10,2) DEFAULT 0.00
- [ ] Add `OXREAUTH_COUNT` INT DEFAULT 0
- [ ] Add `OXMAX_REAUTH_COUNT` INT DEFAULT 1

### 4. Enhanced Payment States
- [ ] `STATE_REQUIRES_CAPTURE` - Authorization successful, awaiting capture
- [ ] `STATE_PARTIALLY_CAPTURED` - Partial capture completed
- [ ] `STATE_AUTHORIZATION_EXPIRED` - Authorization expired
- [ ] `STATE_AWAITING_REAUTHORIZATION` - Needs reauthorization

### 5. Business Logic
- [ ] Capture amount validation (≤ authorized amount)
- [ ] Prevent capture after authorization expiration
- [ ] Prevent void after capture
- [ ] Detect expiring authorizations (7 days before expiry)
- [ ] Automatic reauthorization for expiring auths (optional)

### 6. Integration with PaymentService
- [ ] `PaymentService::authorizePayment()` - Create authorization
- [ ] `PaymentService::captureAuthorization()` - Capture full/partial
- [ ] `PaymentService::voidAuthorization()` - Cancel authorization
- [ ] `PaymentService::reauthorizePayment()` - Renew expiring auth

---

## Test Requirements (TDD Block 5.6)

### Component Tests (95%+ Coverage)
```php
// tests/Component/Unit/Service/AuthorizationServiceTest.php
✅ testAuthorizePayment_CreatesAuthorizationNotCapture()
✅ testAuthorizePayment_TracksAuthorizationExpiry()
✅ testCaptureAuthorization_FullAmount()
✅ testCaptureAuthorization_PartialAmount()
✅ testCaptureAuthorization_AmountExceedsAuthorized_ThrowsException()
✅ testCaptureAuthorization_ExpiredAuthorization_ThrowsException()
✅ testVoidAuthorization_CancelsAuthorization()
✅ testVoidAuthorization_AlreadyCaptured_ThrowsException()
✅ testMultipleCaptures_SumDoesNotExceedAuthorized()
✅ testReauthorizePayment_RenewsExpiringAuthorization()
✅ testReauthorizePayment_UpdatesExpirationDate()
✅ testReauthorizePayment_ExceedsReauthLimit_ThrowsException()
```

### Provider Tests (90%+ Coverage)
```php
// tests/Stripe/Integration/StripeAuthorizationTest.php
✅ testAuthorizePayment_WithRealStripeAPI()
✅ testCaptureAuthorization_WithRealStripeAPI()
✅ testPartialCapture_WithRealStripeAPI()
✅ testVoidAuthorization_WithRealStripeAPI()
```

---

## Implementation Plan

### Step 1: Database Migration
```sql
-- migration/20250116_authorization_tracking.sql
ALTER TABLE oe_payments_transaction ADD COLUMN (
    OXAUTHORIZATION_ID VARCHAR(128),
    OXAUTHORIZATION_STATUS VARCHAR(32),
    OXAUTHORIZATION_EXPIRES DATETIME,
    OXCAPTURED_AMOUNT DECIMAL(10,2) DEFAULT 0.00,
    OXREAUTH_COUNT INT DEFAULT 0,
    OXMAX_REAUTH_COUNT INT DEFAULT 1,
    INDEX IDX_AUTHORIZATION (OXAUTHORIZATION_ID),
    INDEX IDX_AUTH_STATUS (OXAUTHORIZATION_STATUS)
);
```

### Step 2: Request/Response DTOs
```php
// src/Adapter/Request/AuthorizePaymentRequest.php
class AuthorizePaymentRequest
{
    public function __construct(
        private readonly float $amount,
        private readonly string $currency,
        private readonly string $orderId,
        private readonly string $paymentMethod
    ) {}
}

// src/Adapter/Response/AuthorizationResponse.php
class AuthorizationResponse
{
    public function __construct(
        private readonly string $authorizationId,
        private readonly string $status,  // authorized, pending, failed
        private readonly float $amount,
        private readonly \DateTime $expiresAt
    ) {}
}
```

### Step 3: Enhanced PaymentAdapterInterface
```php
// src/Adapter/PaymentAdapterInterface.php
interface PaymentAdapterInterface
{
    // ... existing methods ...

    // NEW: Two-step authorization
    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse;
    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse;
    public function voidAuthorization(VoidAuthorizationRequest $request): VoidResponse;
    public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse;
}
```

### Step 4: AuthorizationService
```php
// src/Component/Service/AuthorizationService.php
class AuthorizationService
{
    public function __construct(
        private readonly PaymentAdapterInterface $adapter,
        private readonly PaymentTransactionRepository $transactionRepo,
        private readonly ModuleSettings $settings,
        private readonly LoggerInterface $logger
    ) {}

    public function authorizePayment(
        Order $order,
        string $paymentMethod
    ): AuthorizationResponse {
        // Create authorization request
        $request = new AuthorizePaymentRequest(
            amount: $order->getTotalAmount(),
            currency: $order->getCurrency(),
            orderId: $order->getId(),
            paymentMethod: $paymentMethod
        );

        // Call provider adapter
        $response = $this->adapter->authorizePayment($request);

        // Track authorization
        $transaction = new PaymentTransaction(
            shopId: $order->getShopId(),
            orderId: $order->getId(),
            providerOrderId: $response->getAuthorizationId(),
            status: $response->getStatus(),
            paymentMethodId: $paymentMethod,
            transactionType: 'authorization'
        );
        $transaction->setAuthorizationId($response->getAuthorizationId());
        $transaction->setAuthorizationExpires($response->getExpiresAt());
        $transaction->setAuthorizationStatus('authorized');

        $this->transactionRepo->save($transaction);

        return $response;
    }

    public function captureAuthorization(
        string $authorizationId,
        float $amount
    ): CaptureResponse {
        // Get authorization transaction
        $transaction = $this->transactionRepo->getByAuthorizationId($authorizationId);

        // Validate authorization status
        if ($transaction->getAuthorizationStatus() === 'captured') {
            throw new PaymentException('Authorization already captured');
        }

        if ($transaction->getAuthorizationStatus() === 'voided') {
            throw new PaymentException('Authorization was voided');
        }

        // Check expiration
        if ($transaction->getAuthorizationExpires() < new \DateTime()) {
            throw new PaymentException('Authorization expired');
        }

        // Validate capture amount
        $authorizedAmount = $transaction->getAmount();
        $capturedAmount = $transaction->getCapturedAmount();
        $remainingAmount = $authorizedAmount - $capturedAmount;

        if ($amount > $remainingAmount) {
            throw new PaymentException(
                "Capture amount ({$amount}) exceeds remaining authorized amount ({$remainingAmount})"
            );
        }

        // Call provider adapter
        $request = new CaptureAuthorizationRequest(
            authorizationId: $authorizationId,
            amount: $amount
        );
        $response = $this->adapter->captureAuthorization($request);

        // Update transaction
        $newCapturedAmount = $capturedAmount + $amount;
        $transaction->setCapturedAmount($newCapturedAmount);

        if ($newCapturedAmount >= $authorizedAmount) {
            $transaction->setAuthorizationStatus('captured');
        } else {
            $transaction->setAuthorizationStatus('partially_captured');
        }

        $this->transactionRepo->save($transaction);

        return $response;
    }

    public function voidAuthorization(string $authorizationId): VoidResponse
    {
        // Get authorization transaction
        $transaction = $this->transactionRepo->getByAuthorizationId($authorizationId);

        // Validate can void
        if ($transaction->getCapturedAmount() > 0) {
            throw new PaymentException('Cannot void authorization after capture');
        }

        // Call provider adapter
        $request = new VoidAuthorizationRequest($authorizationId);
        $response = $this->adapter->voidAuthorization($request);

        // Update transaction
        $transaction->setAuthorizationStatus('voided');
        $this->transactionRepo->save($transaction);

        return $response;
    }

    public function reauthorizePayment(string $authorizationId): AuthorizationResponse
    {
        // Get authorization transaction
        $transaction = $this->transactionRepo->getByAuthorizationId($authorizationId);

        // Validate can reauthorize
        if ($transaction->getCapturedAmount() > 0) {
            throw new PaymentException('Cannot reauthorize after capture');
        }

        // Check reauth limit
        $maxReauths = $transaction->getMaxReauthCount();
        $reauths = $transaction->getReauthCount();

        if ($reauths >= $maxReauths) {
            throw new PaymentException("Reauthorization limit reached ({$maxReauths})");
        }

        // Call provider adapter
        $request = new ReauthorizePaymentRequest($authorizationId);
        $response = $this->adapter->reauthorizePayment($request);

        // Update transaction
        $transaction->setAuthorizationExpires($response->getExpiresAt());
        $transaction->setReauthCount($reauths + 1);
        $this->transactionRepo->save($transaction);

        return $response;
    }

    public function isAuthorizationExpiring(string $authorizationId, int $daysBeforeExpiry = 7): bool
    {
        $transaction = $this->transactionRepo->getByAuthorizationId($authorizationId);
        $expiresAt = $transaction->getAuthorizationExpires();
        $warningDate = (new \DateTime())->add(new \DateInterval("P{$daysBeforeExpiry}D"));

        return $expiresAt <= $warningDate;
    }
}
```

### Step 5: StripeAdapter Implementation
```php
// src/Stripe/StripeAdapter.php
public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse
{
    $intent = $this->client->paymentIntents->create([
        'amount' => $this->convertAmountToCents($request->getAmount()),
        'currency' => strtolower($request->getCurrency()),
        'capture_method' => 'manual',  // Manual capture for authorization
        'metadata' => ['order_id' => $request->getOrderId()],
    ]);

    return new AuthorizationResponse(
        authorizationId: $intent->id,
        status: $this->mapStripeStatus($intent->status),
        amount: $this->convertCentsToAmount($intent->amount),
        expiresAt: (new \DateTime())->add(new \DateInterval('P7D'))  // Stripe: 7 days
    );
}

public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse
{
    $intent = $this->client->paymentIntents->capture($request->getAuthorizationId(), [
        'amount_to_capture' => $this->convertAmountToCents($request->getAmount()),
    ]);

    return new CaptureResponse(
        captureId: $intent->id,
        status: $this->mapStripeStatus($intent->status),
        amount: $this->convertCentsToAmount($intent->amount_received)
    );
}

public function voidAuthorization(VoidAuthorizationRequest $request): VoidResponse
{
    $intent = $this->client->paymentIntents->cancel($request->getAuthorizationId());

    return new VoidResponse(
        authorizationId: $intent->id,
        status: 'voided'
    );
}

public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse
{
    // Stripe doesn't support reauthorization - create new authorization
    throw new PaymentAdapterException(
        'Stripe does not support reauthorization',
        'reauth_not_supported',
        'stripe'
    );
}
```

---

## Deliverables

- [ ] `AuthorizationService` class
- [ ] Enhanced `PaymentAdapterInterface` with authorization methods
- [ ] Request/Response DTOs (AuthorizePaymentRequest, AuthorizationResponse, etc.)
- [ ] Database migration for authorization tracking fields
- [ ] Enhanced payment states
- [ ] StripeAdapter authorization implementation
- [ ] Component tests (15+ tests)
- [ ] Provider integration tests (4+ tests)
- [ ] Documentation updates

---

## Testing Strategy

1. **TDD Order**: Write failing tests → Implement → Refactor
2. **Component Tests**: Mock `PaymentAdapterInterface`, test business logic
3. **Provider Tests**: Real Stripe API (sandbox), test adapter translation
4. **Coverage Goal**: 95%+ component, 90%+ provider

---

# TICKET-008: Idempotency Service 🔴 CRITICAL (P0)

**Type:** Feature
**Priority:** P0 (Critical - Sprint 2)
**Story Points:** 5
**Dependencies:** TICKET-006 (PaymentService)
**Related TDD Block:** Block 5.7
**Comprehensive Analysis:** See [11-comprehensive-provider-analysis.md](11-comprehensive-provider-analysis.md) - Feature #3

---

## Description

Implement idempotency key management to prevent duplicate charges on network retries, webhook redelivery, or user double-clicks. Critical for financial transaction integrity.

**Business Value:** Prevents duplicate charges (critical financial risk), enables safe retries, required for PCI compliance.

---

## Acceptance Criteria

### 1. Idempotency Service
- [ ] `IdempotencyService` class with key generation
- [ ] Duplicate request detection
- [ ] Result caching (24-48 hours)
- [ ] Concurrent request handling (lock mechanism)
- [ ] Automatic key expiration

### 2. Database Table
- [ ] `oe_payments_idempotency` table
- [ ] Unique constraint on `OXKEY`
- [ ] TTL-based cleanup

### 3. Integration with PaymentService
- [ ] Automatic idempotency key generation for all payment operations
- [ ] Return cached result for duplicate requests
- [ ] Idempotency for: createPayment, capturePayment, refundPayment, voidPayment

### 4. Webhook Idempotency
- [ ] Idempotency for webhook processing (prevent duplicate processing)

---

## Test Requirements (TDD Block 5.7)

```php
// tests/Component/Unit/Service/IdempotencyServiceTest.php
✅ testGenerateKey_CreatesUniqueKey()
✅ testHasBeenProcessed_DetectsDuplicate()
✅ testMarkAsProcessed_StoresResult()
✅ testGetResult_ReturnsCachedResult()
✅ testSameKey_SameResult_NoDuplicateCharge()
✅ testIdempotencyKeyExpiration_After24Hours()
✅ testConcurrentRequests_SameKey_OnlyOneProcessed()
✅ testNetworkRetry_UsesSameKey_NoDuplicateCharge()
✅ testWebhookRedelivery_UsesSameKey_ProcessedOnce()
```

---

## Implementation Plan

### Step 1: Database Migration
```sql
CREATE TABLE IF NOT EXISTS oe_payments_idempotency (
    OXID CHAR(32) NOT NULL PRIMARY KEY,
    OXKEY VARCHAR(128) NOT NULL UNIQUE,
    OXORDERID CHAR(32) NOT NULL,
    OXOPERATION VARCHAR(32) NOT NULL,
    OXRESULT TEXT,
    OXSTATUS VARCHAR(32),
    OXCREATED DATETIME NOT NULL,
    OXEXPIRES DATETIME NOT NULL,

    INDEX IDX_KEY (OXKEY),
    INDEX IDX_EXPIRES (OXEXPIRES),
    INDEX IDX_ORDER_OPERATION (OXORDERID, OXOPERATION)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Step 2: IdempotencyService
```php
// src/Component/Service/IdempotencyService.php
class IdempotencyService
{
    public function generateKey(string $orderId, string $operation): string
    {
        return hash('sha256', $orderId . $operation . microtime(true));
    }

    public function hasBeenProcessed(string $key): bool
    {
        $record = $this->repository->findByKey($key);
        return $record !== null && $record->getStatus() === 'completed';
    }

    public function markAsProcessed(string $key, mixed $result, int $ttl = 86400): void
    {
        $record = new IdempotencyRecord(
            key: $key,
            result: json_encode($result),
            status: 'completed',
            expiresAt: (new \DateTime())->add(new \DateInterval("PT{$ttl}S"))
        );
        $this->repository->save($record);
    }

    public function getResult(string $key): mixed
    {
        $record = $this->repository->findByKey($key);
        return $record ? json_decode($record->getResult(), true) : null;
    }
}
```

### Step 3: Integration with PaymentService
```php
// src/Component/Service/PaymentService.php
public function initiatePayment(Order $order, string $paymentMethod): PaymentResponse
{
    // Generate idempotency key
    $idempotencyKey = $this->idempotencyService->generateKey(
        $order->getId(),
        'createPayment'
    );

    // Check if already processed
    if ($this->idempotencyService->hasBeenProcessed($idempotencyKey)) {
        $cachedResult = $this->idempotencyService->getResult($idempotencyKey);
        return PaymentResponse::fromArray($cachedResult);
    }

    // Create payment
    $request = new CreatePaymentRequest(
        amount: $order->getTotalAmount(),
        currency: $order->getCurrency(),
        orderId: $order->getId(),
        shopId: $order->getShopId(),
        paymentMethod: $paymentMethod
    );

    $response = $this->adapter->createPayment($request);

    // Cache result
    $this->idempotencyService->markAsProcessed($idempotencyKey, $response->toArray());

    return $response;
}
```

---

## Deliverables

- [ ] `IdempotencyService` class
- [ ] `oe_payments_idempotency` table migration
- [ ] Integration with `PaymentService`
- [ ] Component tests (9+ tests)
- [ ] Documentation

---

# Sprint 1 Summary

## What We'll Have After Sprint 1

```
✅ OXID module with Component/Stripe separation (TICKET-001)
✅ Component event layer (reusable) (TICKET-002)
✅ Component models + state machine (reusable) (TICKET-003)
✅ Component repositories (reusable) (TICKET-004)
✅ SDK-Adapter layer (provider abstraction) (TICKET-005)
✅ Stripe implementation using SDK-Adapter (TICKET-006)
```

## Architecture Verification

After Sprint 1, the architecture will be:

```
Component Layer (Reusable)          SDK-Adapter Layer (Reusable)      Stripe Layer (Provider-Specific)
========================            ===========================        ==============================
✅ Event System                     ✅ PaymentAdapterInterface         ✅ StripeAdapter
✅ Domain Models                    ✅ Request/Response DTOs           ✅ StripePaymentService
✅ State Machine                    ✅ AdapterFactory                  ✅ PaymentInitiationHandler
✅ Repositories                     ✅ Exception Handling              ✅ Uses StripeAdapter
✅ PaymentService                   ✅ WebhookEvent                    ✅ Uses Component events
✅ Interfaces                       ✅ 100% provider-agnostic          ✅ Implements adapter interface
```

## Architecture Benefits Achieved

**Provider Agnostic:**
- Business logic (PaymentService) only depends on PaymentAdapterInterface
- Switching providers = change configuration (not code)
- Adding new provider = implement interface (30% code, 100% pattern reuse)

**Easy Testing:**
- Unit tests mock adapter interface (not provider SDKs)
- Adapter tests mock provider SDKs (test translation logic)
- Integration tests use real provider APIs (sandbox mode)

**SDK Independence:**
- Provider SDK updates don't affect component code
- Component layer has zero knowledge of Stripe/Unzer/PayPal
- Adapters isolate all provider-specific translations

## Deliverables

- **50+ classes** implemented (including SDK-Adapter layer)
- **600+ tests** written (including adapter tests)
- **90%+ code coverage**
- **Clean separation** between Component, SDK-Adapter, and Stripe
- **First payment flow** working (initiation with provider abstraction)
- **Provider switching** via configuration

## What's Next (Sprint 2)

- Stripe webhook processing via adapter
- Payment capture and refund via adapter
- OXID controller integration
- Admin configuration UI
- Additional provider adapters (Unzer, PayPal)

---

## Team Capacity

- **1 Senior Developer**: 40 hours/week = 80 hours/sprint
- **Story Points**: 42 points total (added SDK-Adapter layer)
- **Velocity**: ~21 points/week (achievable with TDD)

---

**Status:** Ready for Sprint Planning
**Estimated Completion:** 2 weeks
**Risk Level:** Low (clear architecture, minimal dependencies)
