# Sprint 1 Overview: Foundation with Component/Provider Separation + TDD

[Back to Index](SPRINT-1-index.md)

---

## Sprint Information

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
│   │   │   │                                # - 90%+ coverage, slower execution
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
  "license": "GPL-3.0",
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

## Sprint 1 Tickets

This sprint includes 5 foundation tickets:

1. [TICKET-001: Project Setup with Component/Stripe Structure](SPRINT-1-TICKET-01-project-setup.md) - **8 points** (P0)
2. [TICKET-002: Component Event Layer](SPRINT-1-TICKET-02-event-layer.md) - **8 points** (P0)
3. [TICKET-003: Component Models](SPRINT-1-TICKET-03-component-models.md) - **8 points** (P1)
4. [TICKET-004: Component Repositories](SPRINT-1-TICKET-04-repositories.md) - **5 points** (P1)
5. [TICKET-005: SDK-Adapter Layer](SPRINT-1-TICKET-05-sdk-adapter.md) - **8 points** (P1)

**Total Story Points:** 37 points

---

[Back to Index](SPRINT-1-index.md)
