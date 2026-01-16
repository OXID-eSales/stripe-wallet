# Sprint 2: Database Architecture Cleanup & Table Consolidation

**Priority:** HIGH
**Status:** TODO
**Estimated Effort:** 3-4 hours

---

## Architecture Principles

### 1. Liskov Substitution Principle (LSP)

All repository classes MUST follow LSP:
- `WebhookLogRepositoryInterface` implementations must be interchangeable
- `CustomerRepositoryInterface` implementations must be interchangeable
- No service should require knowing the concrete repository type

```php
// GOOD: Works with any implementation
class WebhookService {
    public function __construct(
        private WebhookLogRepositoryInterface $repository  // Interface, not concrete
    ) {}
}

// BAD: Tied to implementation
class WebhookService {
    public function __construct(
        private DoctrineWebhookLogRepository $repository  // Concrete class
    ) {}
}
```

### 2. Component vs Stripe Separation

**Database Architecture:**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     DATABASE LAYER SEPARATION                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  src/Component/ (FRAMEWORK - Provider Agnostic)                             │
│  ├── Repository/                                                            │
│  │   ├── WebhookLogRepositoryInterface.php      ◄── Interface               │
│  │   ├── CustomerRepositoryInterface.php        ◄── Interface               │
│  │   ├── DoctrineWebhookLogRepository.php       ◄── Implementation          │
│  │   └── DoctrinePaymentCustomerRepository.php  ◄── Implementation          │
│  └── tables: oe_payments_* (7 tables, all provider-agnostic)                │
│                                                                             │
│  src/Stripe/ (PROVIDER SPECIFIC)                                            │
│  └── NO TABLES! Stripe uses Component tables via interfaces                 │
│      - StripeCustomerService → uses CustomerRepositoryInterface             │
│      - WebhookController → uses WebhookLogRepositoryInterface               │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 3. Table Ownership Rules

| Owner | Table Prefix | Created By | Used By |
|-------|-------------|------------|---------|
| Component | `oe_payments_*` | Migrations | All providers via interfaces |
| Stripe | NONE | - | Uses Component tables |
| PayPal (future) | NONE | - | Would use Component tables |

**Rule:** Provider-specific modules MUST NOT create their own tables. They use the Component's tables through repository interfaces.

---

## Summary of Issues Found

| Issue | Table | Status | Action |
|-------|-------|--------|--------|
| Duplicate webhook logs | `oe_payments_webhooklogs` + `oe_payments_webhook_log` | REDUNDANT | Consolidate |
| Duplicate customer mapping | `osc_stripe_customer_mapping` + `oe_payments_customer` | REDUNDANT | Remove Stripe-specific |
| Unused payment details | `osc_stripe_payment_details` | UNUSED | Analyze & possibly remove |

---

## Issue 1: Duplicate Webhook Tables

### Current State

| Table | Created By | Used By |
|-------|-----------|---------|
| `oe_payments_webhooklogs` | Migration V20251031140200 | DoctrineWebhookLogRepository |
| `oe_payments_webhook_log` | Events.php | WebhookController, WebhookProcessingService |

### Problem

Webhook logs are written to DIFFERENT tables depending on code path:
- Component repository writes to `oe_payments_webhooklogs`
- Stripe services write to `oe_payments_webhook_log`

### Resolution

**Keep:** `oe_payments_webhooklogs` (from migrations - provider-agnostic)

**Remove:** `oe_payments_webhook_log` (from Events.php)

**Migrate:**
1. Update `WebhookController.php` to use `DoctrineWebhookLogRepository`
2. Update `WebhookProcessingService.php` to use `DoctrineWebhookLogRepository`
3. Remove table creation from `Events.php`
4. Add migration to drop `oe_payments_webhook_log` if empty

---

## Issue 2: Duplicate Customer Mapping Tables

### Current State

**oe_payments_customer** (Migration - Provider-Agnostic):
```sql
OXID, OXUSERID (unique), OXPAYMENTCUSTOMERID, OXDEFAULTPAYMENTMETHOD,
OXSAVEDPAYMENTMETHODS, OXBILLINGAGREEMENT, OXLASTPAYMENTDATE
```

**osc_stripe_customer_mapping** (Events.php - Stripe-Specific):
```sql
OXID, OXSHOPID, OXUSERID (unique), OXSTRIPECUSTOMERID
```

### Analysis

**Daniil's observation is correct** - these tables are redundant!

The `oe_payments_customer.OXPAYMENTCUSTOMERID` field can store the Stripe customer ID.

### Resolution

**Keep:** `oe_payments_customer` (provider-agnostic)

**Remove:** `osc_stripe_customer_mapping`

**Migrate:**
1. If `osc_stripe_customer_mapping` has data, migrate to `oe_payments_customer`
2. Update `StripeCustomerService.php` to use `oe_payments_customer` table
3. Remove table creation from `Events.php`
4. Add migration to drop `osc_stripe_customer_mapping`

### Code Changes Required

**File:** `src/Stripe/Service/StripeCustomerService.php`

```php
// BEFORE
"SELECT OXSTRIPECUSTOMERID FROM osc_stripe_customer_mapping WHERE OXUSERID = ?"

// AFTER
"SELECT OXPAYMENTCUSTOMERID FROM oe_payments_customer WHERE OXUSERID = ?"

// BEFORE
"INSERT INTO osc_stripe_customer_mapping (OXID, OXSHOPID, OXUSERID, OXSTRIPECUSTOMERID, OXCREATED)"

// AFTER
"INSERT INTO oe_payments_customer (OXID, OXUSERID, OXPAYMENTCUSTOMERID, OXCREATED, OXUPDATED)"
```

---

## Issue 3: Stripe Payment Details Table Analysis

### Current State

**osc_stripe_payment_details** (Events.php):
```sql
OXID, OXTRANSACTIONID (FK), OXCARDLAST4, OXCARDBRAND, OXCARDEXPMONTH,
OXCARDEXPYEAR, OXCARDFUNDING, OXCARDCOUNTRY, OX3DSECURE, OX3DSVERSION,
OX3DSAUTHENTICATED, OXRISKSCORE, OXRISKLEVEL, OXMETADATA
```

### Daniil's Feedback

> "We do not need card data - we do not store them. This is Stripe wallet - all data is inside Stripe and the shop has no payment data."

### Analysis

**Usage check:**
```php
// Only used by StripePaymentDetailsRepository.php
// Which is only used to STORE data, not read for business logic
```

**Current data:**
```sql
SELECT * FROM osc_stripe_payment_details LIMIT 5;
-- Returns empty (no data stored)
```

### Resolution Options

**Option A: Remove entirely**
- Table is unused
- Card data shouldn't be stored (PCI compliance)
- Stripe handles all payment details

**Option B: Keep for security/audit logging only**
- Store: 3DS status, risk score, risk level
- DON'T store: card numbers, expiry (even last 4 is questionable)
- Useful for fraud analysis

### Recommendation

**Remove the table** - Stripe wallet means we don't handle card data.

If we need risk scoring later, we can:
1. Store in `oe_payments_contract.OXMETADATA` (JSON)
2. Or in `oe_payments_transaction.OXMETADATA` (doesn't exist yet but could add)

---

## Implementation Tasks

### Task 1: Consolidate Webhook Tables

- [ ] Update `WebhookController.php` to use DoctrineWebhookLogRepository
- [ ] Update `WebhookProcessingService.php` to use DoctrineWebhookLogRepository
- [ ] Remove `oe_payments_webhook_log` creation from Events.php
- [ ] Create migration to drop old table
- [ ] Test webhook processing

### Task 2: Consolidate Customer Tables

- [ ] Check for existing data in `osc_stripe_customer_mapping`
- [ ] Update `StripeCustomerService.php` to use `oe_payments_customer`
- [ ] Remove `osc_stripe_customer_mapping` creation from Events.php
- [ ] Create migration to drop old table
- [ ] Test customer creation/lookup

### Task 3: Remove Payment Details Table

- [ ] Verify table is empty
- [ ] Remove `StripePaymentDetailsRepository.php` (if not needed)
- [ ] Remove `osc_stripe_payment_details` creation from Events.php
- [ ] Create migration to drop table
- [ ] Update any code that references it

### Task 4: Clean Events.php

After above tasks, Events.php should only:
- Add columns to OXID core tables (oxorder, oxorderarticles, oxuser)
- NOT create any new tables (migrations handle that)

---

## Database State After Cleanup

### Tables to KEEP

| Table | Purpose | Owner |
|-------|---------|-------|
| `oe_payments_contract` | Contract lifecycle | Migrations |
| `oe_payments_transaction` | Transaction records | Migrations |
| `oe_payments_order_state` | Order payment state | Migrations |
| `oe_payments_customer` | Customer-provider mapping | Migrations |
| `oe_payments_idempotency` | Idempotency keys | Migrations |
| `oe_payments_sessions` | Payment sessions | Migrations |
| `oe_payments_webhooklogs` | Webhook logs | Migrations |

### Tables to REMOVE

| Table | Reason |
|-------|--------|
| `oe_payments_webhook_log` | Duplicate of webhooklogs |
| `osc_stripe_customer_mapping` | Duplicate of payment_customer |
| `osc_stripe_payment_details` | Unused, no card data needed |

---

## Test Plan

```bash
# Before changes - verify current state
docker compose exec mysql mysql -uroot -proot example -e "SHOW TABLES LIKE 'osc_%';"
docker compose exec mysql mysql -uroot -proot example -e "SELECT COUNT(*) FROM osc_stripe_customer_mapping;"
docker compose exec mysql mysql -uroot -proot example -e "SELECT COUNT(*) FROM osc_stripe_payment_details;"

# Run tests
./source/extensions/stripe/bin/pre-commit-check.sh

# After changes - verify cleanup
docker compose exec mysql mysql -uroot -proot example -e "SHOW TABLES LIKE 'osc_%';"
# Should show 7 tables, not 10
```

---

## Acceptance Criteria

1. [ ] Only ONE webhook log table exists (`oe_payments_webhooklogs`)
2. [ ] Only ONE customer table exists (`oe_payments_customer`)
3. [ ] `osc_stripe_payment_details` table removed
4. [ ] Events.php doesn't create tables (only adds columns)
5. [ ] All webhook processing works
6. [ ] All customer mapping works
7. [ ] All tests pass
8. [ ] Module activation/deactivation works

---

**Created:** 2025-12-02
**Last Updated:** 2025-12-02
