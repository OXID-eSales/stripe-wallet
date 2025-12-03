# Database Schema Documentation

**Payment Component Database Tables**
**Version:** 1.0.0
**Date:** 2025-11-13

---

## Overview

The Stripe payment module uses **component tables with foreign key references** to OXID core tables. This approach keeps payment data isolated while maintaining referential integrity.

**Key Principle:** NO ALTER TABLE on OXID core tables (oxorder, oxuser, oxbasket)

---

## Architecture Philosophy

### Component Tables Pattern

```
OXID Core Tables (Unchanged)
├── oxorder (orders)
├── oxuser (customers)
└── oxbasket (shopping carts)
         │
         │ Foreign Keys (FK)
         ▼
Component Tables (Payment Module)
├── osc_payment_transaction    (payment records)
├── osc_payment_order_state    (payment state per order)
├── osc_payment_customer       (Stripe customer IDs)
└── osc_payment_webhook_log    (webhook events)
```

**Benefits:**
- ✅ No core table modifications
- ✅ Clean module uninstallation
- ✅ OXID upgrade-safe
- ✅ Multi-provider support
- ✅ Complete audit trail

---

## Table Relationships

```sql
┌─────────────────┐
│    oxuser       │
│   (OXID Core)   │
└────────┬────────┘
         │ 1:1
         ▼
┌────────────────────────────┐
│  osc_payment_customer      │
│  - OXSTRIPECUSTOMERID      │
└────────────────────────────┘


┌─────────────────┐
│    oxorder      │
│   (OXID Core)   │
└────────┬────────┘
         │ 1:1
         ├────────────────────┐
         │                    │ 1:N
         ▼                    ▼
┌──────────────────────┐  ┌─────────────────────────┐
│ osc_payment_         │  │ osc_payment_            │
│ order_state          │  │ transaction             │
│ - OXPAYMENTSTATE     │  │ - OXPROVIDERORDERID     │
│ - OXCAPTUREDAMOUNT   │  │ - OXAMOUNT              │
│ - OXREFUNDEDAMOUNT   │  │ - OXSTATUS              │
└──────────────────────┘  └─────────────────────────┘


┌─────────────────────────┐
│ osc_payment_webhook_log │
│ - OXEVENTID             │
│ - OXORDERID (nullable)  │
└─────────────────────────┘
```

---

## Table Definitions

### 1. osc_payment_transaction

**Purpose:** Store all payment transactions (payments, refunds, disputes)

**Cardinality:** 1:N with oxorder (one order can have multiple transactions)

```sql
CREATE TABLE IF NOT EXISTS `osc_payment_transaction` (
    -- Primary Key
    `OXID` CHAR(32) NOT NULL COMMENT 'Transaction unique ID',

    -- Shop & User References
    `OXSHOPID` INT(11) NOT NULL DEFAULT 1 COMMENT 'Multi-shop ID',
    `OXORDERID` CHAR(32) NOT NULL COMMENT 'FK → oxorder.OXID',
    `OXUSERID` CHAR(32) NOT NULL COMMENT 'FK → oxuser.OXID',

    -- Provider Information
    `OXPROVIDER` VARCHAR(50) NOT NULL DEFAULT 'stripe',
    `OXPROVIDERORDERID` VARCHAR(255) NULL COMMENT 'Stripe PaymentIntent ID',
    `OXPROVIDERTRANSACTIONID` VARCHAR(255) NULL COMMENT 'Stripe Charge ID',

    -- Transaction Details
    `OXAMOUNT` DECIMAL(10,2) NOT NULL COMMENT 'Amount in shop currency',
    `OXCURRENCY` VARCHAR(3) NOT NULL COMMENT 'ISO 4217 currency code',
    `OXSTATUS` VARCHAR(50) NOT NULL COMMENT 'Transaction status',
    `OXTYPE` VARCHAR(50) NOT NULL DEFAULT 'payment' COMMENT 'payment|refund|dispute',

    -- Payment Method Details
    `OXPAYMENTMETHOD` VARCHAR(50) NULL COMMENT 'card|sepa_debit|ideal',
    `OXCARDLAST4` VARCHAR(4) NULL COMMENT 'Last 4 digits',
    `OXCARDBRAND` VARCHAR(20) NULL COMMENT 'visa|mastercard|amex',

    -- Security & Compliance
    `OX3DSECURE` TINYINT(1) DEFAULT 0 COMMENT '3D Secure used: 0=no, 1=yes',

    -- Metadata (JSON)
    `OXMETADATA` TEXT NULL COMMENT 'Additional data as JSON',

    -- Error Handling
    `OXERRORCODE` VARCHAR(100) NULL COMMENT 'Error code if failed',
    `OXERRORMESSAGE` TEXT NULL COMMENT 'Error message',

    -- Timestamps
    `OXCREATED` DATETIME NOT NULL COMMENT 'Transaction created',
    `OXUPDATED` DATETIME NULL COMMENT 'Last updated',

    -- Indexes
    PRIMARY KEY (`OXID`),
    KEY `IDX_ORDER` (`OXORDERID`),
    KEY `IDX_USER` (`OXUSERID`),
    KEY `IDX_PROVIDER_ORDER` (`OXPROVIDERORDERID`),
    KEY `IDX_STATUS` (`OXSTATUS`),
    KEY `IDX_CREATED` (`OXCREATED`),
    KEY `IDX_TYPE` (`OXTYPE`),

    -- Foreign Keys
    CONSTRAINT `FK_TRANSACTION_ORDER`
        FOREIGN KEY (`OXORDERID`)
        REFERENCES `oxorder` (`OXID`)
        ON DELETE CASCADE,

    CONSTRAINT `FK_TRANSACTION_USER`
        FOREIGN KEY (`OXUSERID`)
        REFERENCES `oxuser` (`OXID`)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8
COMMENT='All payment transactions from all providers';
```

**Fields Explained:**

| Field | Type | Description | Example |
|-------|------|-------------|---------|
| OXID | CHAR(32) | Unique transaction ID | `tx_abc123...` |
| OXORDERID | CHAR(32) | Link to oxorder table | `order_xyz...` |
| OXPROVIDERORDERID | VARCHAR(255) | Stripe PaymentIntent ID | `pi_1234567890` |
| OXPROVIDERTRANSACTIONID | VARCHAR(255) | Stripe Charge ID | `ch_1234567890` |
| OXAMOUNT | DECIMAL(10,2) | Transaction amount | `99.99` |
| OXCURRENCY | VARCHAR(3) | Currency code | `EUR`, `USD`, `GBP` |
| OXSTATUS | VARCHAR(50) | Status | `succeeded`, `failed`, `refunded` |
| OXTYPE | VARCHAR(50) | Transaction type | `payment`, `refund`, `dispute` |
| OXCARDLAST4 | VARCHAR(4) | Card last 4 digits | `4242` |
| OXCARDBRAND | VARCHAR(20) | Card brand | `visa`, `mastercard` |
| OX3DSECURE | TINYINT(1) | 3DS used | `0` or `1` |

**Status Values:**
- `pending` - Payment initiated
- `succeeded` - Payment captured successfully
- `failed` - Payment failed
- `canceled` - Payment canceled
- `refunded` - Full refund processed
- `partially_refunded` - Partial refund
- `disputed` - Chargeback/dispute

---

### 2. osc_payment_order_state

**Purpose:** Track payment state per order (1:1 relationship)

**Cardinality:** 1:1 with oxorder (one payment state per order)

```sql
CREATE TABLE IF NOT EXISTS `osc_payment_order_state` (
    -- Primary Key
    `OXID` CHAR(32) NOT NULL COMMENT 'State record ID',

    -- Shop & Order Reference
    `OXSHOPID` INT(11) NOT NULL DEFAULT 1,
    `OXORDERID` CHAR(32) NOT NULL COMMENT 'FK → oxorder.OXID (UNIQUE)',

    -- Payment State
    `OXPAYMENTSTATE` VARCHAR(50) NOT NULL DEFAULT 'pending'
        COMMENT 'pending|authorized|paid|failed|refunded|disputed',
    `OXPAYMENTMETHOD` VARCHAR(50) NULL COMMENT 'stripe|paypal|...',

    -- Authorization Details
    `OXAUTHORIZED` TINYINT(1) DEFAULT 0 COMMENT 'Payment authorized',
    `OXAUTHORIZEDAMOUNT` DECIMAL(10,2) NULL COMMENT 'Authorized amount',
    `OXAUTHORIZEDAT` DATETIME NULL COMMENT 'Authorization time',

    -- Capture Details
    `OXCAPTURED` TINYINT(1) DEFAULT 0 COMMENT 'Payment captured',
    `OXCAPTUREDAMOUNT` DECIMAL(10,2) NULL COMMENT 'Captured amount',
    `OXCAPTUREDAT` DATETIME NULL COMMENT 'Capture time',

    -- Refund Details
    `OXREFUNDED` TINYINT(1) DEFAULT 0 COMMENT 'Payment refunded',
    `OXREFUNDEDAMOUNT` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total refunded',
    `OXREFUNDEDAT` DATETIME NULL COMMENT 'Last refund time',

    -- Timestamps
    `OXCREATED` DATETIME NOT NULL,
    `OXUPDATED` DATETIME NULL,

    -- Indexes
    PRIMARY KEY (`OXID`),
    UNIQUE KEY `UNQ_ORDER` (`OXORDERID`),
    KEY `IDX_STATE` (`OXPAYMENTSTATE`),

    -- Foreign Keys
    CONSTRAINT `FK_STATE_ORDER`
        FOREIGN KEY (`OXORDERID`)
        REFERENCES `oxorder` (`OXID`)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8
COMMENT='Payment state per order (1:1 relationship)';
```

**Payment States:**
- `pending` - Payment not yet completed
- `authorized` - Payment authorized (not captured)
- `paid` - Payment captured successfully
- `failed` - Payment failed
- `partially_refunded` - Some amount refunded
- `refunded` - Fully refunded
- `disputed` - Chargeback initiated

---

### 3. osc_payment_customer

**Purpose:** Store Stripe customer IDs for OXID users

**Cardinality:** 1:1 with oxuser (one Stripe customer per user)

```sql
CREATE TABLE IF NOT EXISTS `osc_payment_customer` (
    -- Primary Key
    `OXID` CHAR(32) NOT NULL COMMENT 'Customer record ID',

    -- Shop & User Reference
    `OXSHOPID` INT(11) NOT NULL DEFAULT 1,
    `OXUSERID` CHAR(32) NOT NULL COMMENT 'FK → oxuser.OXID (UNIQUE)',

    -- Stripe Customer
    `OXSTRIPECUSTOMERID` VARCHAR(255) NULL COMMENT 'Stripe Customer ID (cus_xxx)',

    -- Metadata
    `OXMETADATA` TEXT NULL COMMENT 'Additional data as JSON',

    -- Timestamps
    `OXCREATED` DATETIME NOT NULL,
    `OXUPDATED` DATETIME NULL,

    -- Indexes
    PRIMARY KEY (`OXID`),
    UNIQUE KEY `UNQ_USER` (`OXUSERID`),
    KEY `IDX_STRIPE_CUSTOMER` (`OXSTRIPECUSTOMERID`),

    -- Foreign Keys
    CONSTRAINT `FK_CUSTOMER_USER`
        FOREIGN KEY (`OXUSERID`)
        REFERENCES `oxuser` (`OXID`)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8
COMMENT='Payment customer data (1:1 with user)';
```

**Use Cases:**
- Store Stripe Customer ID for recurring payments
- Link saved payment methods
- Customer billing history
- Subscription management (future)

---

### 4. osc_payment_webhook_log

**Purpose:** Log all webhook events for debugging and idempotency

**Cardinality:** Standalone (optional FK to oxorder)

```sql
CREATE TABLE IF NOT EXISTS `osc_payment_webhook_log` (
    -- Primary Key
    `OXID` CHAR(32) NOT NULL COMMENT 'Log entry ID',
    `OXSHOPID` INT(11) NOT NULL DEFAULT 1,

    -- Webhook Details
    `OXEVENTID` VARCHAR(255) NOT NULL COMMENT 'Stripe event ID (idempotency)',
    `OXEVENTTYPE` VARCHAR(100) NOT NULL COMMENT 'payment_intent.succeeded, etc',
    `OXPROVIDER` VARCHAR(50) NOT NULL DEFAULT 'stripe',

    -- Related Entities (optional)
    `OXORDERID` CHAR(32) NULL COMMENT 'Related order (if found)',
    `OXTRANSACTIONID` CHAR(32) NULL COMMENT 'Related transaction',

    -- Payload
    `OXPAYLOAD` MEDIUMTEXT NOT NULL COMMENT 'Full webhook payload (JSON)',

    -- Processing Status
    `OXSTATUS` VARCHAR(50) NOT NULL DEFAULT 'received'
        COMMENT 'received|processed|failed',
    `OXPROCESSEDAT` DATETIME NULL COMMENT 'Processing time',
    `OXERROR` TEXT NULL COMMENT 'Error message if failed',

    -- Timestamps
    `OXCREATED` DATETIME NOT NULL COMMENT 'Webhook received time',

    -- Indexes
    PRIMARY KEY (`OXID`),
    UNIQUE KEY `UNQ_EVENT` (`OXEVENTID`),
    KEY `IDX_EVENT_TYPE` (`OXEVENTTYPE`),
    KEY `IDX_ORDER` (`OXORDERID`),
    KEY `IDX_STATUS` (`OXSTATUS`),
    KEY `IDX_CREATED` (`OXCREATED`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8
COMMENT='Webhook event log for debugging and idempotency';
```

**Use Cases:**
- Prevent duplicate webhook processing
- Debug webhook delivery issues
- Audit trail of all payment events
- Replay failed webhooks

---

## Query Examples

### Get Order Payment Status

```sql
SELECT
    o.OXORDERNR AS order_number,
    o.OXTOTALORDERSUM AS order_total,
    ps.OXPAYMENTSTATE AS payment_state,
    ps.OXCAPTUREDAMOUNT AS captured_amount,
    ps.OXREFUNDEDAMOUNT AS refunded_amount,
    t.OXPROVIDERORDERID AS stripe_payment_id,
    t.OXSTATUS AS transaction_status,
    t.OXCREATED AS payment_date
FROM oxorder o
LEFT JOIN osc_payment_order_state ps ON o.OXID = ps.OXORDERID
LEFT JOIN osc_payment_transaction t ON o.OXID = t.OXORDERID
WHERE o.OXORDERNR = '123456';
```

### Get All Transactions for Order

```sql
SELECT
    OXTYPE AS type,
    OXAMOUNT AS amount,
    OXCURRENCY AS currency,
    OXSTATUS AS status,
    OXCARDLAST4 AS card,
    OXCARDBRAND AS brand,
    OX3DSECURE AS three_d_secure,
    OXCREATED AS created
FROM osc_payment_transaction
WHERE OXORDERID = 'order_oxid_here'
ORDER BY OXCREATED DESC;
```

### Get Customer's Stripe ID

```sql
SELECT
    u.OXUSERNAME AS email,
    c.OXSTRIPECUSTOMERID AS stripe_customer_id,
    c.OXCREATED AS customer_since
FROM oxuser u
LEFT JOIN osc_payment_customer c ON u.OXID = c.OXUSERID
WHERE u.OXID = 'user_oxid_here';
```

### Get Failed Webhooks

```sql
SELECT
    OXEVENTTYPE AS event_type,
    OXERROR AS error_message,
    OXCREATED AS received_at
FROM osc_payment_webhook_log
WHERE OXSTATUS = 'failed'
ORDER BY OXCREATED DESC
LIMIT 50;
```

### Get Payment Statistics

```sql
SELECT
    DATE(OXCREATED) AS date,
    COUNT(*) AS total_transactions,
    SUM(CASE WHEN OXSTATUS = 'succeeded' THEN 1 ELSE 0 END) AS successful,
    SUM(CASE WHEN OXSTATUS = 'failed' THEN 1 ELSE 0 END) AS failed,
    SUM(CASE WHEN OX3DSECURE = 1 THEN 1 ELSE 0 END) AS with_3ds,
    SUM(OXAMOUNT) AS total_amount,
    OXCURRENCY AS currency
FROM osc_payment_transaction
WHERE OXTYPE = 'payment'
  AND OXCREATED >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(OXCREATED), OXCURRENCY
ORDER BY date DESC;
```

---

## Indexing Strategy

### Primary Indexes (created automatically)

- Primary keys (OXID)
- Unique constraints (OXEVENTID, OXORDERID where 1:1)
- Foreign keys (OXORDERID, OXUSERID)

### Secondary Indexes (for performance)

```sql
-- Fast order lookup
IDX_ORDER (OXORDERID)

-- Fast webhook idempotency check
UNQ_EVENT (OXEVENTID)

-- Fast status queries
IDX_STATUS (OXSTATUS)

-- Fast provider queries
IDX_PROVIDER_ORDER (OXPROVIDERORDERID)

-- Fast date range queries
IDX_CREATED (OXCREATED)
```

---

## Data Retention

### Recommended Retention Policy

```sql
-- Delete old webhook logs (keep 90 days)
DELETE FROM osc_payment_webhook_log
WHERE OXCREATED < DATE_SUB(NOW(), INTERVAL 90 DAY)
  AND OXSTATUS = 'processed';

-- Archive old transactions (keep 7 years for accounting)
-- Move to archive table instead of deleting
INSERT INTO osc_payment_transaction_archive
SELECT * FROM osc_payment_transaction
WHERE OXCREATED < DATE_SUB(NOW(), INTERVAL 7 YEAR);
```

### GDPR Compliance

```sql
-- Delete user payment data on account deletion
-- (CASCADE will handle this automatically via FK)
DELETE FROM oxuser WHERE OXID = 'user_to_delete';

-- Anonymize transaction data (keep for accounting)
UPDATE osc_payment_transaction
SET OXUSERID = 'anonymous',
    OXMETADATA = NULL
WHERE OXUSERID = 'user_to_delete';
```

---

## Backup Recommendations

### Daily Backups

```bash
# Backup payment tables
mysqldump -u user -p database \
  osc_payment_transaction \
  osc_payment_order_state \
  osc_payment_customer \
  osc_payment_webhook_log \
  > stripe_payment_backup_$(date +%Y%m%d).sql
```

### Restore

```bash
mysql -u user -p database < stripe_payment_backup_20241113.sql
```

---

## Migration & Upgrade

### Adding New Fields (Safe)

```sql
-- Add new field to transaction table
ALTER TABLE osc_payment_transaction
ADD COLUMN OXNEWFIELD VARCHAR(100) NULL AFTER OXMETADATA;

-- Add index
ALTER TABLE osc_payment_transaction
ADD KEY IDX_NEWFIELD (OXNEWFIELD);
```

### Module Uninstallation

```sql
-- Drop all tables (order matters due to FKs)
DROP TABLE IF EXISTS osc_payment_webhook_log;
DROP TABLE IF EXISTS osc_payment_transaction;
DROP TABLE IF EXISTS osc_payment_order_state;
DROP TABLE IF EXISTS osc_payment_customer;
```

**Note:** CASCADE will clean up related records automatically

---

## Next Steps

1. Read [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md) for using these tables
2. Read [SERVICE_LAYER.md](SERVICE_LAYER.md) for data access patterns
3. Read [WEBHOOK_HANDLING.md](WEBHOOK_HANDLING.md) for webhook log usage

