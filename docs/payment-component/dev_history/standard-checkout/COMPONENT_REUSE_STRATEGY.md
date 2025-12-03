# Component Database Reuse Strategy

**How standard checkout reuses existing Component infrastructure instead of duplicating tables.**

## Overview

The Component system (`/src/Component`) already provides database tables and repositories for:
- **Transactions** (`osc_payment_transaction`)
- **Contracts** (`osc_payment_contract`)
- **Event System** (EventDispatcher, Events, Handlers)

**Standard checkout MUST reuse these Component tables** to avoid duplication and ensure consistency across all payment methods.

---

## Existing Component Tables

### 1. `osc_payment_transaction` (Component)

**Managed by:** `Component\Repository\DoctrineTransactionRepository`
**Entity:** `Component\Transaction\Transaction`

**Columns:**
```sql
CREATE TABLE `osc_payment_transaction` (
    `OXID` CHAR(32) NOT NULL,                      -- Transaction ID
    `OXSHOPID` INT(11) NOT NULL,                    -- Shop ID
    `OXORDERID` CHAR(32) NOT NULL,                  -- Order ID (FK)
    `OXCONTRACTID` CHAR(32) NULL,                   -- Contract ID (NULL for standard checkout)
    `OXPROVIDER` VARCHAR(50) NOT NULL,              -- Provider: 'stripe', 'paypal', etc.
    `OXPROVIDERORDERID` VARCHAR(255) NULL,          -- Provider Order ID (PaymentIntent ID)
    `OXTRANSACTIONID` VARCHAR(255) NULL,            -- Provider Transaction ID (Charge ID)
    `OXTYPE` VARCHAR(50) NOT NULL,                  -- Type: 'payment', 'refund', 'authorization'
    `OXSTATUS` VARCHAR(50) NOT NULL,                -- Status: 'pending', 'completed', 'failed'
    `OXAMOUNT` DECIMAL(10,2) NOT NULL,              -- Transaction amount
    `OXCURRENCY` VARCHAR(3) NOT NULL,               -- Currency code (ISO 4217)
    `OXPAYMENTMETHODID` VARCHAR(255) NULL,          -- Payment method ID
    `OXPAYMENTMETHODTYPE` VARCHAR(50) NULL,         -- Payment method type
    `OXPARENTTRANSACTIONID` VARCHAR(255) NULL,      -- Parent transaction (for refunds)
    `OXCREATED` DATETIME NOT NULL,                  -- Created timestamp
    `OXUPDATED` DATETIME NOT NULL,                  -- Updated timestamp
    PRIMARY KEY (`OXID`),
    KEY `IDX_ORDER` (`OXORDERID`),
    KEY `IDX_CONTRACT` (`OXCONTRACTID`),
    KEY `IDX_PROVIDER_ORDER` (`OXPROVIDERORDERID`),
    CONSTRAINT `FK_TRANSACTION_ORDER` FOREIGN KEY (`OXORDERID`) REFERENCES `oxorder` (`OXID`) ON DELETE CASCADE
) ENGINE=InnoDB;
```

**✅ USE THIS TABLE** - Don't create a duplicate!

---

### 2. `osc_payment_contract` (Component)

**Managed by:** `Component\Repository\DoctrineContractRepository`
**Entity:** `Component\Contract\PaymentContract`

**Purpose:** Smart contract checkout (NOT used by standard checkout)

**Columns:**
```sql
CREATE TABLE `osc_payment_contract` (
    `OXID` CHAR(32) NOT NULL,
    `OXSHOPID` INT(11) NOT NULL,
    `OXUSERID` CHAR(32) NOT NULL,
    `OXORDERID` CHAR(32) NULL,                      -- NULL until order created
    `OXPROVIDER` VARCHAR(50) NOT NULL,
    `OXPROVIDERORDERID` VARCHAR(255) NULL,
    `OXSTATE` VARCHAR(50) NOT NULL,                 -- pending, committed, fulfilled, etc.
    `OXCONDITIONS` TEXT NULL,                       -- JSON conditions
    `OXAMOUNT` DECIMAL(10,2) NOT NULL,
    `OXCURRENCY` VARCHAR(3) NOT NULL,
    `OXCREATED` DATETIME NOT NULL,
    `OXUPDATED` DATETIME NOT NULL,
    `OXEXPIRESAT` DATETIME NULL,
    PRIMARY KEY (`OXID`)
) ENGINE=InnoDB;
```

**⚠️ NOT USED** in standard checkout (contracts are for smart contract checkout only)

---

## Standard Checkout Strategy

### Reuse Component Tables ✅

| Component Table | Standard Checkout Usage |
|----------------|------------------------|
| `osc_payment_transaction` | ✅ **YES** - Store all payment transactions |
| `osc_payment_contract` | ❌ **NO** - Contracts not used in standard checkout |

### Additional Stripe-Specific Tables ➕

Standard checkout needs **2 additional tables** for data not covered by Component:

#### 1. `osc_stripe_payment_details` (NEW)

**Purpose:** Stripe-specific payment details (card info, 3DS, etc.)

```sql
CREATE TABLE `osc_stripe_payment_details` (
    `OXID` CHAR(32) NOT NULL COMMENT 'Primary key',
    `OXTRANSACTIONID` CHAR(32) NOT NULL COMMENT 'FK: osc_payment_transaction.OXID',

    -- Card Details
    `OXCARDLAST4` VARCHAR(4) NULL COMMENT 'Last 4 digits of card',
    `OXCARDBRAND` VARCHAR(20) NULL COMMENT 'Card brand (visa, mastercard, amex)',
    `OXCARDEXPMONTH` VARCHAR(2) NULL COMMENT 'Card expiration month',
    `OXCARDEXPYEAR` VARCHAR(4) NULL COMMENT 'Card expiration year',
    `OXCARDFUNDING` VARCHAR(20) NULL COMMENT 'Card funding type (credit, debit, prepaid)',
    `OXCARDCOUNTRY` VARCHAR(2) NULL COMMENT 'Card country code',

    -- 3D Secure / SCA
    `OX3DSECURE` TINYINT(1) DEFAULT 0 COMMENT '3D Secure used (0=no, 1=yes)',
    `OX3DSVERSION` VARCHAR(10) NULL COMMENT '3DS version (1.0, 2.0)',
    `OX3DSAUTHENTICATED` TINYINT(1) NULL COMMENT '3DS authentication result',

    -- Risk / Fraud
    `OXRISKSCORE` INT(3) NULL COMMENT 'Risk score (0-100)',
    `OXRISKLEVEL` VARCHAR(20) NULL COMMENT 'Risk level (normal, elevated, highest)',

    -- Metadata
    `OXMETADATA` TEXT NULL COMMENT 'Additional metadata (JSON)',

    -- Timestamps
    `OXCREATED` DATETIME NOT NULL,
    `OXUPDATED` DATETIME NULL,

    PRIMARY KEY (`OXID`),
    UNIQUE KEY `UNQ_TRANSACTION` (`OXTRANSACTIONID`),

    CONSTRAINT `FK_DETAILS_TRANSACTION`
        FOREIGN KEY (`OXTRANSACTIONID`)
        REFERENCES `osc_payment_transaction` (`OXID`)
        ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Stripe-specific payment details';
```

#### 2. `osc_stripe_customer_mapping` (NEW)

**Purpose:** Map OXID users to Stripe customers

```sql
CREATE TABLE `osc_stripe_customer_mapping` (
    `OXID` CHAR(32) NOT NULL,
    `OXSHOPID` INT(11) NOT NULL DEFAULT 1,
    `OXUSERID` CHAR(32) NOT NULL COMMENT 'FK: oxuser.OXID',
    `OXSTRIPECUSTOMERID` VARCHAR(255) NOT NULL COMMENT 'Stripe Customer ID',
    `OXCREATED` DATETIME NOT NULL,
    `OXUPDATED` DATETIME NULL,

    PRIMARY KEY (`OXID`),
    UNIQUE KEY `UNQ_USER` (`OXUSERID`),
    KEY `IDX_STRIPE_CUSTOMER` (`OXSTRIPECUSTOMERID`),

    CONSTRAINT `FK_CUSTOMER_USER`
        FOREIGN KEY (`OXUSERID`)
        REFERENCES `oxuser` (`OXID`)
        ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Stripe customer mapping';
```

#### 3. `osc_payment_webhook_log` (NEW)

**Purpose:** Log webhook events from all providers

```sql
CREATE TABLE `osc_payment_webhook_log` (
    `OXID` CHAR(32) NOT NULL,
    `OXSHOPID` INT(11) NOT NULL DEFAULT 1,
    `OXEVENTID` VARCHAR(255) NOT NULL COMMENT 'Webhook event ID (idempotency)',
    `OXEVENTTYPE` VARCHAR(100) NOT NULL,
    `OXPROVIDER` VARCHAR(50) NOT NULL COMMENT 'stripe, paypal, etc.',
    `OXPAYLOAD` MEDIUMTEXT NOT NULL COMMENT 'Full webhook payload (JSON)',
    `OXSTATUS` VARCHAR(50) NOT NULL DEFAULT 'received',
    `OXERRORMESSAGE` TEXT NULL,
    `OXCREATED` DATETIME NOT NULL,
    `OXUPDATED` DATETIME NULL,

    PRIMARY KEY (`OXID`),
    UNIQUE KEY `UNQ_EVENT` (`OXEVENTID`),
    KEY `IDX_EVENT_TYPE` (`OXEVENTTYPE`),
    KEY `IDX_STATUS` (`OXSTATUS`)
) ENGINE=InnoDB COMMENT='Webhook event log';
```

---

## Updated Service Integration

### Use Component Transaction Repository

**Before (Custom implementation):**
```php
// ❌ DON'T DO THIS
class StripeAdapter
{
    private function storeTransaction(Order $order, array $paymentIntent): void
    {
        $db = DatabaseProvider::getDb();
        $sql = "INSERT INTO osc_payment_transaction ...";
        $db->execute($sql, [...]);
    }
}
```

**After (Component repository):**
```php
// ✅ DO THIS
use OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Transaction\Transaction;

class StripeAdapter
{
    public function __construct(
        private TransactionRepositoryInterface $transactionRepository
    ) {}

    private function storeTransaction(Order $order, array $paymentIntent): Transaction
    {
        $transaction = new Transaction(
            id: UtilsObject::getInstance()->generateUId(),
            shopId: (int) Registry::getConfig()->getShopId(),
            orderId: $order->getId(),
            contractId: null, // Standard checkout doesn't use contracts
            provider: 'stripe',
            type: 'payment',
            status: 'completed',
            amount: $this->convertFromCents($paymentIntent['amount']),
            currency: strtoupper($paymentIntent['currency'])
        );

        $transaction->setProviderOrderId($paymentIntent['id']);

        $charges = $paymentIntent['charges'] ?? [];
        if (!empty($charges)) {
            $transaction->setTransactionId($charges[0]['id']);
        }

        $transaction->setPaymentMethodId('osc_stripe_card');
        $transaction->setPaymentMethodType('card');

        $this->transactionRepository->save($transaction);

        // Store Stripe-specific details separately
        $this->storeStripeDetails($transaction->getId(), $paymentIntent);

        return $transaction;
    }

    private function storeStripeDetails(string $transactionId, array $paymentIntent): void
    {
        $charges = $paymentIntent['charges'] ?? [];
        $charge = $charges[0] ?? null;

        if (!$charge) {
            return;
        }

        $db = DatabaseProvider::getDb();

        $card = $charge['payment_method_details']['card'] ?? null;
        $threeDSecure = $card['three_d_secure'] ?? null;

        $sql = "INSERT INTO osc_stripe_payment_details
                (OXID, OXTRANSACTIONID, OXCARDLAST4, OXCARDBRAND, OX3DSECURE, OXCREATED)
                VALUES (?, ?, ?, ?, ?, NOW())";

        $db->execute($sql, [
            UtilsObject::getInstance()->generateUId(),
            $transactionId,
            $card['last4'] ?? null,
            $card['brand'] ?? null,
            $threeDSecure ? 1 : 0,
        ]);
    }
}
```

---

## Benefits of Reusing Component Tables

### 1. **Consistency Across Payment Methods**
- All providers (Stripe, PayPal, etc.) use same transaction table
- Unified reporting and analytics
- Single source of truth

### 2. **Reduced Code Duplication**
- Component already has Transaction entity and repository
- No need to write custom SQL
- Type-safe operations

### 3. **Future-Proof**
- When new payment methods are added, they use same infrastructure
- Updates to Component benefit all providers

### 4. **Better Testing**
- Component repositories are already tested
- Mock repositories for unit tests
- Integration tests reuse same infrastructure

---

## Migration Path

### Step 1: Remove Duplicate Table Creation

**Remove from `Events.php::addStandardCheckoutTables()`:**
```php
// ❌ REMOVE THIS
self::addTableIfNotExists('osc_payment_transaction', "
    CREATE TABLE `osc_payment_transaction` (
        // ... old definition
    )
");
```

**Replace with Component-compatible version:**
```php
// ✅ ADD THIS (ensuring Component columns exist)
self::addTableIfNotExists('osc_payment_transaction', "
    CREATE TABLE `osc_payment_transaction` (
        `OXID` CHAR(32) NOT NULL,
        `OXSHOPID` INT(11) NOT NULL,
        `OXORDERID` CHAR(32) NOT NULL,
        `OXCONTRACTID` CHAR(32) NULL,
        `OXPROVIDER` VARCHAR(50) NOT NULL,
        `OXPROVIDERORDERID` VARCHAR(255) NULL,
        `OXTRANSACTIONID` VARCHAR(255) NULL,
        `OXTYPE` VARCHAR(50) NOT NULL,
        `OXSTATUS` VARCHAR(50) NOT NULL,
        `OXAMOUNT` DECIMAL(10,2) NOT NULL,
        `OXCURRENCY` VARCHAR(3) NOT NULL,
        `OXPAYMENTMETHODID` VARCHAR(255) NULL,
        `OXPAYMENTMETHODTYPE` VARCHAR(50) NULL,
        `OXPARENTTRANSACTIONID` VARCHAR(255) NULL,
        `OXCREATED` DATETIME NOT NULL,
        `OXUPDATED` DATETIME NOT NULL,
        PRIMARY KEY (`OXID`),
        KEY `IDX_ORDER` (`OXORDERID`),
        KEY `IDX_CONTRACT` (`OXCONTRACTID`),
        KEY `IDX_PROVIDER_ORDER` (`OXPROVIDERORDERID`),
        CONSTRAINT `FK_TRANSACTION_ORDER` FOREIGN KEY (`OXORDERID`)
            REFERENCES `oxorder` (`OXID`) ON DELETE CASCADE
    ) ENGINE=InnoDB COMMENT='Component payment transactions';
");

// ✅ ADD Stripe-specific details table
self::addTableIfNotExists('osc_stripe_payment_details', "
    CREATE TABLE `osc_stripe_payment_details` (
        // ... Stripe-specific columns
    )
");
```

### Step 2: Update Services

Inject `TransactionRepositoryInterface` instead of using raw SQL:

```yaml
# services.yaml
services:
  OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapter:
    arguments:
      - '@stripe.payment.adapter.client'
      - '@OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService'
      - '@OxidSolutionCatalysts\Stripe\Service\StripeCustomerService'
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
      - '@OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface'
```

### Step 3: Update Queries

Replace custom transaction queries with repository methods:

```php
// ❌ OLD
$db = DatabaseProvider::getDb();
$db->execute("INSERT INTO osc_payment_transaction ...", [...]);

// ✅ NEW
$transaction = new Transaction(...);
$this->transactionRepository->save($transaction);
```

---

## Tables Summary

| Table | Owner | Usage | Action |
|-------|-------|-------|--------|
| `osc_payment_transaction` | Component | All transactions | ✅ **REUSE** - Use Component table |
| `osc_payment_contract` | Component | Smart contracts | ❌ **SKIP** - Not used in standard checkout |
| `osc_stripe_payment_details` | Standard Checkout | Card details, 3DS | ➕ **CREATE** - Stripe-specific |
| `osc_stripe_customer_mapping` | Standard Checkout | User→Customer mapping | ➕ **CREATE** - Stripe-specific |
| `osc_payment_webhook_log` | Shared | Webhook events | ➕ **CREATE** - Shared across providers |

---

## Next Steps

1. ✅ **Update Events.php** - Remove duplicate table, use Component structure
2. ✅ **Update StripeAdapter** - Uses Component TransactionRepository via adapter pattern
3. ✅ **Update IMPLEMENTATION_GUIDE.md** - Document Component reuse
4. ✅ **Update DATABASE_SCHEMA.md** - Show final table structure
5. ✅ **Create migration** - Alter existing tables if needed

---

**Last Updated:** 2025-11-13
**Version:** 1.0.0
