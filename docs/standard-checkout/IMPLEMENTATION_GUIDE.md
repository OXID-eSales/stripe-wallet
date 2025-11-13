# Standard Checkout Implementation Guide

**Complete Step-by-Step Implementation**
**Version:** 1.0.0
**Date:** 2025-11-13
**Estimated Time:** 40-60 hours

---

## Overview

This guide provides complete, step-by-step instructions for implementing Stripe payments in OXID's standard multi-step checkout flow. Follow each step in order for a complete working implementation.

---

## Phase 1: Project Setup (2-3 hours)

### Step 1.1: Install Stripe PHP SDK

```bash
cd /path/to/oxid/source/extensions/stripe
composer require stripe/stripe-php:^10.0
```

**Verify Installation:**
```bash
composer show stripe/stripe-php
```

Expected output:
```
name     : stripe/stripe-php
versions : * 10.x.x
```

---

### Step 1.2: Create Module Directory Structure

```bash
mkdir -p source/modules/osc/stripe/src/{Controller,Service,Model,Repository}
mkdir -p source/modules/osc/stripe/views/tpl
mkdir -p source/modules/osc/stripe/views/js
mkdir -p source/modules/osc/stripe/tests/{Unit,Integration}
mkdir -p source/modules/osc/stripe/config
```

**Directory Structure:**
```
source/modules/osc/stripe/
├── composer.json
├── metadata.php
├── src/
│   ├── Controller/
│   │   ├── PaymentController.php
│   │   ├── OrderController.php
│   │   └── WebhookController.php
│   ├── Service/
│   │   ├── StripePaymentService.php
│   │   └── StripeConfigurationService.php
│   ├── Model/
│   │   └── PaymentTransaction.php
│   ├── Repository/
│   │   └── PaymentTransactionRepository.php
│   └── Core/
│       └── Events.php
├── views/
│   ├── tpl/
│   │   ├── payment_stripe.tpl
│   │   └── stripe_3ds.tpl
│   └── js/
│       └── stripe_checkout.js
├── config/
│   └── services.yaml
├── migrations/
│   └── 001_create_payment_tables.sql
└── tests/
    ├── Unit/
    └── Integration/
```

---

### Step 1.3: Create composer.json

```json
{
    "name": "osc/stripe",
    "description": "Stripe Payment Integration for OXID eShop",
    "type": "oxideshop-module",
    "keywords": ["oxid", "modules", "eShop", "stripe", "payment"],
    "homepage": "https://www.oxidforge.org/",
    "license": "MIT",
    "authors": [
        {
            "name": "Your Company",
            "email": "info@example.com"
        }
    ],
    "require": {
        "php": "^8.0",
        "stripe/stripe-php": "^10.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.0",
        "phpstan/phpstan": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "OxidSolutionCatalysts\\Stripe\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "OxidSolutionCatalysts\\Stripe\\Tests\\": "tests/"
        }
    },
    "extra": {
        "oxideshop": {
            "target-directory": "osc/stripe"
        }
    }
}
```

---

### Step 1.4: Create metadata.php

```php
<?php

/**
 * Metadata version
 */
$sMetadataVersion = '2.1';

/**
 * Module information
 */
$aModule = [
    'id'          => 'osc_stripe',
    'title'       => 'Stripe Payment Integration',
    'description' => [
        'en' => 'Accept credit card payments via Stripe in OXID eShop standard checkout',
        'de' => 'Kreditkartenzahlungen über Stripe im OXID eShop Standard-Checkout akzeptieren',
    ],
    'thumbnail'   => 'logo.png',
    'version'     => '1.0.0',
    'author'      => 'Your Company',
    'url'         => 'https://www.example.com',
    'email'       => 'info@example.com',
    'extend'      => [
        // Extend OXID core controllers
        \OxidEsales\Eshop\Application\Controller\PaymentController::class =>
            \OxidSolutionCatalysts\Stripe\Controller\PaymentController::class,
        \OxidEsales\Eshop\Application\Controller\OrderController::class =>
            \OxidSolutionCatalysts\Stripe\Controller\OrderController::class,
    ],
    'controllers' => [
        // Custom controllers
        'stripe_webhook' => \OxidSolutionCatalysts\Stripe\Controller\WebhookController::class,
        'stripe_return' => \OxidSolutionCatalysts\Stripe\Controller\ReturnController::class,
    ],
    'templates'   => [
        // Template overrides
        'payment_stripe.tpl' => 'osc/stripe/views/tpl/payment_stripe.tpl',
    ],
    'blocks'      => [
        // Template blocks
        [
            'template' => 'page/checkout/payment.tpl',
            'block'    => 'select_payment',
            'file'     => 'views/blocks/payment_stripe_method.tpl',
        ],
    ],
    'settings'    => [
        // Module settings
        [
            'group' => 'stripe_main',
            'name'  => 'stripeMode',
            'type'  => 'select',
            'value' => 'test',
            'constraints' => 'test|live',
        ],
        [
            'group' => 'stripe_main',
            'name'  => 'stripeTestPublicKey',
            'type'  => 'str',
            'value' => '',
        ],
        [
            'group' => 'stripe_main',
            'name'  => 'stripeTestSecretKey',
            'type'  => 'password',
            'value' => '',
        ],
        [
            'group' => 'stripe_main',
            'name'  => 'stripeLivePublicKey',
            'type'  => 'str',
            'value' => '',
        ],
        [
            'group' => 'stripe_main',
            'name'  => 'stripeLiveSecretKey',
            'type'  => 'password',
            'value' => '',
        ],
        [
            'group' => 'stripe_main',
            'name'  => 'stripeWebhookSecret',
            'type'  => 'password',
            'value' => '',
        ],
        [
            'group' => 'stripe_features',
            'name'  => 'stripeCapture',
            'type'  => 'select',
            'value' => 'automatic',
            'constraints' => 'automatic|manual',
        ],
        [
            'group' => 'stripe_features',
            'name'  => 'stripe3DSecure',
            'type'  => 'bool',
            'value' => true,
        ],
    ],
    'events'      => [
        'onActivate'   => '\OxidSolutionCatalysts\Stripe\Core\Events::onActivate',
        'onDeactivate' => '\OxidSolutionCatalysts\Stripe\Core\Events::onDeactivate',
    ],
];
```

---

## Phase 2: Database Setup (1-2 hours)

### Step 2.1: Create Migration SQL

Create file: `migrations/001_create_payment_tables.sql`

```sql
-- Payment Transaction Table
CREATE TABLE IF NOT EXISTS `osc_payment_transaction` (
    `OXID` CHAR(32) NOT NULL COMMENT 'Transaction ID',
    `OXSHOPID` INT(11) NOT NULL DEFAULT 1 COMMENT 'Shop ID',
    `OXORDERID` CHAR(32) NOT NULL COMMENT 'Order ID (FK to oxorder)',
    `OXUSERID` CHAR(32) NOT NULL COMMENT 'User ID (FK to oxuser)',

    -- Provider Information
    `OXPROVIDER` VARCHAR(50) NOT NULL DEFAULT 'stripe' COMMENT 'Payment provider name',
    `OXPROVIDERORDERID` VARCHAR(255) NULL COMMENT 'Provider payment ID (Stripe PaymentIntent)',
    `OXPROVIDERTRANSACTIONID` VARCHAR(255) NULL COMMENT 'Provider transaction ID (Stripe Charge)',

    -- Transaction Details
    `OXAMOUNT` DECIMAL(10,2) NOT NULL COMMENT 'Transaction amount',
    `OXCURRENCY` VARCHAR(3) NOT NULL COMMENT 'Currency code (ISO 4217)',
    `OXSTATUS` VARCHAR(50) NOT NULL COMMENT 'Transaction status',
    `OXTYPE` VARCHAR(50) NOT NULL DEFAULT 'payment' COMMENT 'Transaction type (payment, refund, etc)',

    -- Payment Method Details
    `OXPAYMENTMETHOD` VARCHAR(50) NULL COMMENT 'Payment method type (card, sepa, etc)',
    `OXCARDLAST4` VARCHAR(4) NULL COMMENT 'Last 4 digits of card',
    `OXCARDBRAND` VARCHAR(20) NULL COMMENT 'Card brand (visa, mastercard, etc)',

    -- 3D Secure / SCA
    `OX3DSECURE` TINYINT(1) DEFAULT 0 COMMENT '3D Secure used (0=no, 1=yes)',

    -- Metadata (JSON)
    `OXMETADATA` TEXT NULL COMMENT 'Additional metadata (JSON)',

    -- Error Handling
    `OXERRORCODE` VARCHAR(100) NULL COMMENT 'Error code if failed',
    `OXERRORMESSAGE` TEXT NULL COMMENT 'Error message if failed',

    -- Timestamps
    `OXCREATED` DATETIME NOT NULL COMMENT 'Created timestamp',
    `OXUPDATED` DATETIME NULL COMMENT 'Last updated timestamp',

    PRIMARY KEY (`OXID`),
    KEY `IDX_ORDER` (`OXORDERID`),
    KEY `IDX_USER` (`OXUSERID`),
    KEY `IDX_PROVIDER_ORDER` (`OXPROVIDERORDERID`),
    KEY `IDX_STATUS` (`OXSTATUS`),
    KEY `IDX_CREATED` (`OXCREATED`),

    CONSTRAINT `FK_TRANSACTION_ORDER`
        FOREIGN KEY (`OXORDERID`)
        REFERENCES `oxorder` (`OXID`)
        ON DELETE CASCADE,

    CONSTRAINT `FK_TRANSACTION_USER`
        FOREIGN KEY (`OXUSERID`)
        REFERENCES `oxuser` (`OXID`)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Payment transactions from all providers';

-- Payment Order State Table (1:1 with order)
CREATE TABLE IF NOT EXISTS `osc_payment_order_state` (
    `OXID` CHAR(32) NOT NULL COMMENT 'State ID',
    `OXSHOPID` INT(11) NOT NULL DEFAULT 1 COMMENT 'Shop ID',
    `OXORDERID` CHAR(32) NOT NULL COMMENT 'Order ID (FK to oxorder) - UNIQUE',

    -- Payment State
    `OXPAYMENTSTATE` VARCHAR(50) NOT NULL DEFAULT 'pending' COMMENT 'Payment state',
    `OXPAYMENTMETHOD` VARCHAR(50) NULL COMMENT 'Selected payment method',

    -- Authorization Details
    `OXAUTHORIZED` TINYINT(1) DEFAULT 0 COMMENT 'Payment authorized',
    `OXAUTHORIZEDAMOUNT` DECIMAL(10,2) NULL COMMENT 'Authorized amount',
    `OXAUTHORIZEDAT` DATETIME NULL COMMENT 'Authorization timestamp',

    -- Capture Details
    `OXCAPTURED` TINYINT(1) DEFAULT 0 COMMENT 'Payment captured',
    `OXCAPTUREDAMOUNT` DECIMAL(10,2) NULL COMMENT 'Captured amount',
    `OXCAPTUREDAT` DATETIME NULL COMMENT 'Capture timestamp',

    -- Refund Details
    `OXREFUNDED` TINYINT(1) DEFAULT 0 COMMENT 'Payment refunded',
    `OXREFUNDEDAMOUNT` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total refunded amount',
    `OXREFUNDEDAT` DATETIME NULL COMMENT 'Last refund timestamp',

    -- Timestamps
    `OXCREATED` DATETIME NOT NULL COMMENT 'Created timestamp',
    `OXUPDATED` DATETIME NULL COMMENT 'Last updated timestamp',

    PRIMARY KEY (`OXID`),
    UNIQUE KEY `UNQ_ORDER` (`OXORDERID`),
    KEY `IDX_STATE` (`OXPAYMENTSTATE`),

    CONSTRAINT `FK_STATE_ORDER`
        FOREIGN KEY (`OXORDERID`)
        REFERENCES `oxorder` (`OXID`)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Payment state per order (1:1)';

-- Payment Customer Table (1:1 with user)
CREATE TABLE IF NOT EXISTS `osc_payment_customer` (
    `OXID` CHAR(32) NOT NULL COMMENT 'Customer ID',
    `OXSHOPID` INT(11) NOT NULL DEFAULT 1 COMMENT 'Shop ID',
    `OXUSERID` CHAR(32) NOT NULL COMMENT 'User ID (FK to oxuser) - UNIQUE',

    -- Stripe Customer
    `OXSTRIPECUSTOMERID` VARCHAR(255) NULL COMMENT 'Stripe Customer ID',

    -- Metadata
    `OXMETADATA` TEXT NULL COMMENT 'Additional metadata (JSON)',

    -- Timestamps
    `OXCREATED` DATETIME NOT NULL COMMENT 'Created timestamp',
    `OXUPDATED` DATETIME NULL COMMENT 'Last updated timestamp',

    PRIMARY KEY (`OXID`),
    UNIQUE KEY `UNQ_USER` (`OXUSERID`),
    KEY `IDX_STRIPE_CUSTOMER` (`OXSTRIPECUSTOMERID`),

    CONSTRAINT `FK_CUSTOMER_USER`
        FOREIGN KEY (`OXUSERID`)
        REFERENCES `oxuser` (`OXID`)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Payment customer data (1:1 with user)';

-- Webhook Log Table
CREATE TABLE IF NOT EXISTS `osc_payment_webhook_log` (
    `OXID` CHAR(32) NOT NULL COMMENT 'Log ID',
    `OXSHOPID` INT(11) NOT NULL DEFAULT 1 COMMENT 'Shop ID',

    -- Webhook Details
    `OXEVENTID` VARCHAR(255) NOT NULL COMMENT 'Webhook event ID (idempotency)',
    `OXEVENTTYPE` VARCHAR(100) NOT NULL COMMENT 'Event type (payment_intent.succeeded, etc)',
    `OXPROVIDER` VARCHAR(50) NOT NULL DEFAULT 'stripe' COMMENT 'Provider name',

    -- Related Entities
    `OXORDERID` CHAR(32) NULL COMMENT 'Related order ID',
    `OXTRANSACTIONID` CHAR(32) NULL COMMENT 'Related transaction ID',

    -- Payload
    `OXPAYLOAD` MEDIUMTEXT NOT NULL COMMENT 'Full webhook payload (JSON)',

    -- Processing
    `OXSTATUS` VARCHAR(50) NOT NULL DEFAULT 'received' COMMENT 'Processing status',
    `OXPROCESSEDAT` DATETIME NULL COMMENT 'Processing timestamp',
    `OXERROR` TEXT NULL COMMENT 'Error message if processing failed',

    -- Timestamps
    `OXCREATED` DATETIME NOT NULL COMMENT 'Received timestamp',

    PRIMARY KEY (`OXID`),
    UNIQUE KEY `UNQ_EVENT` (`OXEVENTID`),
    KEY `IDX_EVENT_TYPE` (`OXEVENTTYPE`),
    KEY `IDX_ORDER` (`OXORDERID`),
    KEY `IDX_STATUS` (`OXSTATUS`),
    KEY `IDX_CREATED` (`OXCREATED`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Webhook event log';
```

---

### Step 2.2: Run Migration

```bash
# Via OXID CLI (if available)
php vendor/bin/oe-console oe:module:install-configuration osc_stripe

# Or manually via MySQL
mysql -u [username] -p [database_name] < migrations/001_create_payment_tables.sql
```

---

### Step 2.3: Verify Tables

```sql
-- Check tables exist
SHOW TABLES LIKE 'osc_payment%';

-- Should return:
-- osc_payment_transaction
-- osc_payment_order_state
-- osc_payment_customer
-- osc_payment_webhook_log

-- Check table structure
DESCRIBE osc_payment_transaction;
```

---

## Phase 3: Core Classes (8-12 hours)

### Step 3.1: Create Events Class

Create file: `src/Core/Events.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Core;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Application\Model\Payment;
use OxidEsales\Eshop\Core\Field;

/**
 * Module events handler
 */
class Events
{
    /**
     * Execute on module activation
     */
    public static function onActivate(): void
    {
        self::addPaymentMethod();
        self::addDatabaseTables();
        self::clearCache();
    }

    /**
     * Execute on module deactivation
     */
    public static function onDeactivate(): void
    {
        self::deactivatePaymentMethod();
        self::clearCache();
    }

    /**
     * Add Stripe payment method to OXID
     */
    private static function addPaymentMethod(): void
    {
        $payment = oxNew(Payment::class);

        // Check if already exists
        if ($payment->load('osc_stripe_card')) {
            // Update existing
            $payment->oxpayments__oxactive = new Field(1);
            $payment->save();
            return;
        }

        // Create new payment method
        $payment->setId('osc_stripe_card');
        $payment->oxpayments__oxactive = new Field(1);
        $payment->oxpayments__oxdesc = new Field('Credit Card (Stripe)');
        $payment->oxpayments__oxlongdesc = new Field(
            'Pay securely with your credit or debit card via Stripe'
        );
        $payment->oxpayments__oxsort = new Field(100);
        $payment->save();
    }

    /**
     * Deactivate payment method
     */
    private static function deactivatePaymentMethod(): void
    {
        $payment = oxNew(Payment::class);

        if ($payment->load('osc_stripe_card')) {
            $payment->oxpayments__oxactive = new Field(0);
            $payment->save();
        }
    }

    /**
     * Check and create database tables if needed
     */
    private static function addDatabaseTables(): void
    {
        $db = DatabaseProvider::getDb();

        // Check if tables exist
        $tables = [
            'osc_payment_transaction',
            'osc_payment_order_state',
            'osc_payment_customer',
            'osc_payment_webhook_log',
        ];

        foreach ($tables as $table) {
            $exists = $db->getOne("SHOW TABLES LIKE '{$table}'");

            if (!$exists) {
                $migrationFile = __DIR__ . '/../../migrations/001_create_payment_tables.sql';

                if (file_exists($migrationFile)) {
                    $sql = file_get_contents($migrationFile);
                    $db->execute($sql);
                    break; // Migration file creates all tables
                }
            }
        }
    }

    /**
     * Clear OXID cache
     */
    private static function clearCache(): void
    {
        $utils = Registry::getUtils();
        $utils->resetTemplateCache([]);
        $utils->resetLanguageCache();
    }
}
```

---

### Step 3.2: Create Configuration Service

Create file: `src/Service/StripeConfigurationService.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Service;

use OxidEsales\Eshop\Core\Config;

/**
 * Stripe configuration service
 */
class StripeConfigurationService
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Check if module is in test mode
     */
    public function isTestMode(): bool
    {
        return $this->config->getConfigParam('stripeMode') === 'test';
    }

    /**
     * Get public API key (based on mode)
     */
    public function getPublicKey(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('stripeTestPublicKey');
        }

        return (string) $this->config->getConfigParam('stripeLivePublicKey');
    }

    /**
     * Get secret API key (based on mode)
     */
    public function getSecretKey(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('stripeTestSecretKey');
        }

        return (string) $this->config->getConfigParam('stripeLiveSecretKey');
    }

    /**
     * Get webhook secret
     */
    public function getWebhookSecret(): string
    {
        return (string) $this->config->getConfigParam('stripeWebhookSecret');
    }

    /**
     * Get capture mode (automatic or manual)
     */
    public function getCaptureMode(): string
    {
        return (string) $this->config->getConfigParam('stripeCapture') ?: 'automatic';
    }

    /**
     * Check if 3D Secure is enabled
     */
    public function is3DSecureEnabled(): bool
    {
        return (bool) $this->config->getConfigParam('stripe3DSecure');
    }

    /**
     * Validate configuration
     */
    public function isConfigured(): bool
    {
        $publicKey = $this->getPublicKey();
        $secretKey = $this->getSecretKey();

        return !empty($publicKey) && !empty($secretKey);
    }
}
```

---

### Step 3.3: Create Payment Transaction Model

Create file: `src/Model/PaymentTransaction.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Model;

use OxidEsales\Eshop\Core\Model\BaseModel;

/**
 * Payment transaction model
 */
class PaymentTransaction extends BaseModel
{
    /**
     * Class constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->init('osc_payment_transaction');
    }

    /**
     * Get order ID
     */
    public function getOrderId(): string
    {
        return (string) $this->getFieldData('oxorderid');
    }

    /**
     * Get provider order ID (PaymentIntent ID)
     */
    public function getProviderOrderId(): ?string
    {
        return $this->getFieldData('oxproviderorderid');
    }

    /**
     * Get transaction amount
     */
    public function getAmount(): float
    {
        return (float) $this->getFieldData('oxamount');
    }

    /**
     * Get transaction status
     */
    public function getStatus(): string
    {
        return (string) $this->getFieldData('oxstatus');
    }

    /**
     * Check if transaction is successful
     */
    public function isSuccessful(): bool
    {
        return in_array($this->getStatus(), ['succeeded', 'paid', 'completed']);
    }

    /**
     * Get metadata as array
     */
    public function getMetadata(): array
    {
        $metadata = $this->getFieldData('oxmetadata');

        if (empty($metadata)) {
            return [];
        }

        return json_decode($metadata, true) ?: [];
    }

    /**
     * Set metadata from array
     */
    public function setMetadata(array $metadata): void
    {
        $this->assign([
            'oxmetadata' => json_encode($metadata),
        ]);
    }
}
```

---

[**CONTINUED IN NEXT FILE DUE TO LENGTH...**]

This implementation guide continues with:
- Phase 4: Service Layer Implementation
- Phase 5: Controller Implementation
- Phase 6: Template Integration
- Phase 7: Webhook Handling
- Phase 8: Testing
- Phase 9: Deployment

Would you like me to continue with the remaining phases?
