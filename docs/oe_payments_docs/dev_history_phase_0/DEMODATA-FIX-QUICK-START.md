# 🔧 Quick Fix: Demodata Installation Issue

## The Problem

Your GitHub Actions failed with this error:
```
Cannot truncate a table referenced in a foreign key constraint
(oe_payments_contract → oxorder)
```

This happens because our payment module adds foreign key constraints that prevent OXID's demodata installer from truncating tables.

## The Solution ✅

Use our helper script to temporarily drop FK constraints during demodata installation.

### For GitHub Actions (RECOMMENDED)

Update your workflow to include these steps:

```yaml
# BEFORE demodata installation
- name: Drop FK constraints
  run: docker compose exec -T php bash -c "cd /var/www/extensions/stripe/migration/scripts && ./setup-helper.sh drop-fk"

# Your existing demodata step
- name: Install demodata
  run: docker compose exec -T php bash -c "cd /var/www/source && vendor/bin/oe-console oe:setup:demodata"

# AFTER demodata installation
- name: Re-add FK constraints
  run: docker compose exec -T php bash -c "cd /var/www/extensions/stripe/migration/scripts && ./setup-helper.sh add-fk"
```

### For Local Development

```bash
# From your project root
docker compose exec php bash -c "cd /var/www/extensions/stripe/migration/scripts && ./setup-helper.sh drop-fk"
docker compose exec php bash -c "cd /var/www/source && vendor/bin/oe-console oe:setup:demodata"
docker compose exec php bash -c "cd /var/www/extensions/stripe/migration/scripts && ./setup-helper.sh add-fk"
```

### Manual SQL (Alternative)

If you prefer SQL commands:

```sql
-- DROP before demodata
ALTER TABLE oe_payments_contract DROP FOREIGN KEY FK_CONTRACT_ORDER;
ALTER TABLE oe_payments_order_state DROP FOREIGN KEY FK_ORDER_STATE;
ALTER TABLE oe_payments_transaction DROP FOREIGN KEY FK_ORDER;

-- Install demodata here

-- ADD BACK after demodata
ALTER TABLE oe_payments_contract ADD CONSTRAINT FK_CONTRACT_ORDER
  FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE SET NULL;
ALTER TABLE oe_payments_order_state ADD CONSTRAINT FK_ORDER_STATE
  FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE;
ALTER TABLE oe_payments_transaction ADD CONSTRAINT FK_ORDER
  FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE;
```

## Important Notes

✅ **This is ONLY needed for:**
- Initial development setup with demodata
- Test environments that reset with demodata
- CI/CD pipelines that install demodata

❌ **NOT needed for:**
- Production deployments (no demodata in production)
- Running migrations (migrations work fine with FK constraints)
- Normal module installation (only affects demodata)

## Files Created

1. **Helper Script:** `migration/scripts/setup-helper.sh`
2. **Documentation:** `migration/scripts/README.md`
3. **GitHub Actions Example:** `migration/scripts/github-actions-example.yml`

## Complete Documentation

For detailed explanation and troubleshooting:
- See: `migration/scripts/README.md`
- See: `migration/README.md` (Troubleshooting section)

## Why This Happens

MySQL's `TRUNCATE` command (used by demodata installer):
- Cannot be used when foreign keys reference the table
- Is blocked even with `ON DELETE CASCADE` or `SET NULL`
- Requires temporarily dropping FK constraints

Our solution:
- Temporarily removes FK constraints
- Allows TRUNCATE to proceed
- Re-adds constraints after demodata loads
- Maintains referential integrity in production ✅

---

**Status:** ✅ Fixed
**Date:** 2025-10-31
**Impact:** CI/CD pipelines and development setup only
