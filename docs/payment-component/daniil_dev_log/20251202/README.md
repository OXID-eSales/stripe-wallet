# Stripe Payment Module - Development Log

**Date:** December 2, 2025
**Module:** osc/stripe for OXID eShop 7
**Developer:** Daniil
**Branch:** b-8.0.x

---

## Today's Objectives

1. **Resolve Order Creation Issue** - `invalid_delivery_address` error (state 7)
2. **Database Architecture Review** - Verify Component vs Stripe table separation
3. **Code Review** - Check all DB requests, data models, migrations

---

## Critical Issue: Order Creation Fails

### Error from oxideshop.log (2025-12-01)

```
OxidShopOrderService: Order finalization failed
  order_state: 7
  error_code: invalid_delivery_address
  session_id: ec9ec56a5aa9147aec973c2a3b2c9820
  user_id: 3443d2a1dc4b21b6dc0cfb817d3d8087
  payment_id: osc_stripe_wallet
```

### Root Cause Analysis

The issue is in the **address hash validation** during order creation:

1. **On checkout start**: Customer selects delivery address, OXID stores hash in `$_REQUEST['sDeliveryAddressMD5']`
2. **After Stripe redirect**: Customer returns via GET, but `$_REQUEST['sDeliveryAddressMD5']` is EMPTY
3. **OXID validation**: `Order::validateDeliveryAddress()` computes hash from current address, compares with empty REQUEST value
4. **Result**: Hash mismatch → order state 7 (invalid_delivery_address)

### Implemented Fix (from previous session)

Store the address hash in contract metadata before redirect:

```php
// StripeContractCreationHandler.php
$addressHash = $this->computeDeliveryAddressHash($user);
$contract->setMetadata('delivery_address_hash', $addressHash);
$contract->setMetadata('delivery_address_id', $session->getVariable('deladrid'));
```

Restore on return:

```php
// StripeCheckoutReturnHandler.php
$hash = $contract->getMetadata('delivery_address_hash');
$session->setVariable('sDeliveryAddressMD5', $hash);
```

### Current Status

The fix is implemented but **still failing**. Need to investigate:
- Is the hash being restored BEFORE `Order::finalizeOrder()` is called?
- Is the restored value being read by OXID's validation?

---

## Database Architecture Review

### Provider-Agnostic Tables (src/Component)

| Table | Purpose | Location |
|-------|---------|----------|
| `oe_payments_contract` | Payment lifecycle management | Migration V20251031140000 |
| `oe_payments_transaction` | Transaction records (all providers) | Migration V20251031140100 |
| `oe_payments_order_state` | Order payment state tracking | Migration V20251031140200 |
| `oe_payments_customer` | Customer-provider mapping | Migration V20251031140200 |
| `oe_payments_idempotency` | Idempotency key storage | Migration V20251031140200 |
| `oe_payments_sessions` | Payment session data | Migration V20251031140200 |
| `oe_payments_webhooklogs` | Webhook event logging | Migration V20251031140200 |

### Stripe-Specific Tables (Events.php)

| Table | Purpose | Location |
|-------|---------|----------|
| `osc_stripe_payment_details` | Card info, 3DS, risk scores | Events.php |
| `osc_stripe_customer_mapping` | OXID user → Stripe customer | Events.php |
| `oe_payments_webhook_log` | Webhook logs (duplicate?) | Events.php |

### OXID Core Extensions

Added columns to existing tables:
- `oxorder`: STRIPEDELCOSTREFUNDED, STRIPEMODE, STRIPEEXTERNALTRANSID, etc.
- `oxorderarticles`: STRIPEQUANTITYREFUNDED, STRIPEAMOUNTREFUNDED
- `oxuser`: STRIPECUSTOMERID

---

## Architecture Verification

### Question: Are Component tables provider-agnostic?

**YES** - The migrations in `/migration/data/` create tables with:
- No Stripe-specific columns
- Generic provider field (VARCHAR for stripe, paypal, unzer, etc.)
- Contract-first pattern (OXORDERID is NULL until committed)

### Question: Does Stripe layer rely on Component infrastructure?

**YES** - The Stripe handlers use:
- `DoctrineContractRepository` → reads/writes `oe_payments_contract`
- `PaymentContract` model → provider-agnostic with metadata support
- Event system → Component's EventDispatcher

### Potential Issue: Duplicate webhook tables

1. `oe_payments_webhooklogs` (Migration V20251031140200) - provider-agnostic
2. `oe_payments_webhook_log` (Events.php) - also provider-agnostic

**Action Required:** Review and consolidate these tables.

---

## Test Results (Previous Session)

| Test Suite | Total | Pass | Fail | Skip |
|------------|-------|------|------|------|
| Unit Tests | 852 | 852 | 0 | 1 |
| Integration Tests | 226 | 169 | 0 | 56 |

---

## Sprint 1 Priorities

See `todo/` folder for detailed sprint breakdown:

1. **Sprint 1-1**: Debug order creation flow - trace address hash restoration
2. **Sprint 1-2**: Verify webhook table consolidation
3. **Sprint 1-3**: Review StripeOrderCreationHandler error handling

---

## Quick Commands

```bash
# Run pre-commit checks
./source/extensions/stripe/bin/pre-commit-check.sh

# Run unit tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit

# Run integration tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php

# Check database tables
docker compose exec mysql mysql -uroot -proot example -e "SHOW TABLES LIKE 'osc_%';"
```

---

**Last Updated:** 2025-12-02 Morning Session
