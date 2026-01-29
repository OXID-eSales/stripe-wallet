# 2025-12-08 - Stripe Webhook & Payment Date Fixes

**Branch:** b-7.4.x
**Status:** ALL SPRINTS COMPLETE

---

## Completed Sprints

| Sprint | Description | Status |
|--------|-------------|--------|
| **Sprint 13** | Webhook URL Configuration (404 Fix) | DONE |
| **Sprint 14** | OXPAID Not Being Updated Fix | DONE |

---

## Development Principles

All code follows these principles:

| Principle | Description |
|-----------|-------------|
| **TDD-FIRST** | Write failing tests BEFORE implementation (RED → GREEN → REFACTOR) |
| **SOLID** | Single Responsibility, Open/Closed, Liskov, Interface Segregation, DI |
| **Liskov Substitution (LSP)** | Use interfaces as types |
| **Dependency Injection (DI)** | All dependencies injected via constructor |
| **Clean Code** | Human readable, maintainable, self-documenting |
| **No Over-Engineering** | Minimal changes to achieve the goal |
| **No Duplicate Code** | Reuse existing services and methods |

---

## Sprint 13: Webhook URL Configuration - DONE

### Problem
Stripe webhooks receiving HTTP 404 responses.

### Root Cause
`ModuleConfigurationService::getWebhookUrl()` called `$this->config->getShopUrl()` but `$this->config` was `ModuleConfiguration` (OXID Framework class) which has no `getShopUrl()` method.

### Solution
- Injected `ShopAdapterInterface` via constructor with Registry fallback
- Added `getShopBaseUrl()` private method
- Fixed webhook controller name to match `metadata.php` registration

### Files Modified
1. `src/Stripe/Service/ModuleConfigurationService.php`
2. `services.yaml`

### Verification
- Webhooks now receive HTTP 200
- All 1229 unit tests pass
- Pre-commit checks pass

---

## Sprint 14: OXPAID Not Being Updated - DONE

### Problem
After Sprint 13 fix, webhooks were received successfully but OXPAID showed `0000-00-00 00:00:00`.

### Root Cause
**Race condition**: Stripe sends webhook immediately after payment, but arrives BEFORE user's browser completes return flow.
- Webhook finds contract in `draft` state
- Cannot fulfill contract (requires `committed` state)
- Return flow completes later, order created, but OXPAID not set

### Solution
Update OXPAID directly in `StripeOrderCreationHandler` when order is created. This is the reliable path since:
- Payment is confirmed (user returned from Stripe with `paid` status)
- Order is being created
- No race condition possible

### Additional Fix
Timezone mismatch between OXORDERDATE and OXPAID fixed by using PHP's `date()` instead of MySQL's `NOW()`.

### Files Modified
1. `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php` - Add OXPAID update
2. `services.yaml` - Inject Connection dependency
3. `src/Stripe/Service/WebhookProcessingService.php` - Handle race condition gracefully

### Verification
- E2E test passes
- OXPAID correctly shows payment timestamp
- Timezone matches OXORDERDATE
- Pre-commit checks pass

---

## Quick Commands

```bash
# Run E2E tests
cd tests/e2e/playwright && npx playwright test tests/checkout/

# Run pre-commit checks
./bin/pre-commit-check.sh --no-phpunit

# Check webhook logs
tail -f source/log/osc/stripe_webhooks.log

# Reactivate module
docker compose exec -T php bash -c "cd /var/www && bin/oe-console oe:module:deactivate osc_stripe_wallet && bin/oe-console oe:module:activate osc_stripe_wallet"
```

---

## Database Verification

```sql
-- Check recent orders with payment dates
SELECT OXORDERNR, OXORDERDATE, OXPAID, OXTRANSSTATUS, OXTRANSID
FROM oxorder ORDER BY OXORDERDATE DESC LIMIT 5;

-- Expected: OXORDERDATE and OXPAID should match (same timezone)
-- OXTRANSSTATUS should be 'OK'
-- OXTRANSID should contain PaymentIntent ID (pi_xxx)
```

---

## Architecture Decision: Order Creation vs Webhook

| Approach | Reliability | Race Condition? |
|----------|-------------|-----------------|
| **Order Creation Flow** (primary) | High - always runs when user completes checkout | No |
| **Webhook Handler** (backup) | Medium - handles edge cases (browser close) | Yes |

**Decision:** Use order creation flow as primary for OXPAID update. Webhook handler remains as backup for edge cases where user closes browser before return.

---

## Files in This Directory

```
20251208/
├── README.md                    # This file
├── status.md                    # Current status summary
├── stripe-sdk-webhook-reference.md  # Stripe SDK v18 reference
├── done/
│   ├── sprint-13-webhook-url-fix.md  # Sprint 13 documentation
│   └── sprint-14-oxpaid-fix.md       # Sprint 14 documentation
└── todo/
    ├── sprint-13.1-diagnosis-tests.md  # (completed)
    └── sprint-13.2-fix-webhook-url.md  # (completed)
```

---

## Key Learnings

1. **Race conditions** can occur between webhook delivery and browser return flow
2. Use PHP `date()` instead of MySQL `NOW()` to match OXID's timezone handling
3. Order creation flow is more reliable than webhook for payment confirmation in the happy path
4. Always check which class a variable actually is before calling methods on it
