# Follow-up Tasks

## Required Before Deployment

- [ ] Run integration tests with database to verify table name changes work
- [ ] Create database migration script for existing deployments (RENAME TABLE statements)
- [ ] Update any CI/CD scripts that reference old namespace/table names

## Optional Improvements

- [ ] Update external documentation referencing old namespace
- [ ] Tag new version after full verification
- [ ] Update changelog with namespace/table changes

## Database Migration Script

For existing deployments, run:

```sql
-- Rename tables from osc_payment_* to oe_payments_*
RENAME TABLE osc_payment_contract TO oe_payments_contract;
RENAME TABLE osc_payment_transaction TO oe_payments_transaction;
RENAME TABLE osc_payment_webhooklogs TO oe_payments_webhooklogs;
RENAME TABLE osc_payment_customer TO oe_payments_customer;
RENAME TABLE osc_payment_sessions TO oe_payments_sessions;
RENAME TABLE osc_payment_idempotency TO oe_payments_idempotency;

-- If order_state table exists (may have been dropped in Sprint 8)
RENAME TABLE osc_payment_order_state TO oe_payments_order_state;
```
