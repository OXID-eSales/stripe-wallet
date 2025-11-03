# Migration Helper Scripts

## Problem: Demodata Installation with Foreign Keys

When installing OXID demodata, the installation process uses `TRUNCATE` commands which are blocked by foreign key constraints. Our payment module adds FK constraints from `osc_payment_contract` to `oxorder`, which prevents the demodata installer from truncating the `oxorder` table.

**Error Message:**
```
SQLSTATE[42000]: Syntax error or access violation: 1701 Cannot truncate a table
referenced in a foreign key constraint (`example`.`osc_payment_contract`,
CONSTRAINT `FK_CONTRACT_ORDER` FOREIGN KEY (`OXORDERID`) REFERENCES
`example`.`oxorder` (`OXID`))
```

## Solution: setup-helper.sh

The `setup-helper.sh` script temporarily drops and re-adds FK constraints during demodata installation.

### Usage

#### Option 1: Manual Workflow (Development)

```bash
cd /path/to/extensions/stripe/migration/scripts

# Step 1: Drop FK constraints BEFORE demodata installation
./setup-helper.sh drop-fk

# Step 2: Install demodata (from OXID source directory)
cd /path/to/source
vendor/bin/oe-console oe:setup:demodata

# Step 3: Add FK constraints back
cd /path/to/extensions/stripe/migration/scripts
./setup-helper.sh add-fk
```

#### Option 2: Environment Variables

Set custom database credentials if not using defaults:

```bash
export DB_HOST=localhost
export DB_USER=myuser
export DB_PASS=mypassword
export DB_NAME=mydatabase

./setup-helper.sh drop-fk
# ... install demodata ...
./setup-helper.sh add-fk
```

#### Option 3: Docker Environment

```bash
# From project root
docker compose exec php bash -c "cd /var/www/extensions/stripe/migration/scripts && ./setup-helper.sh drop-fk"
docker compose exec php bash -c "cd /var/www/source && vendor/bin/oe-console oe:setup:demodata"
docker compose exec php bash -c "cd /var/www/extensions/stripe/migration/scripts && ./setup-helper.sh add-fk"
```

### GitHub Actions / CI/CD

For automated setups, use this workflow:

```yaml
- name: Drop FK constraints before demodata
  run: |
    docker compose exec -T php bash -c "cd /var/www/extensions/stripe/migration/scripts && ./setup-helper.sh drop-fk"

- name: Install demodata
  run: |
    docker compose exec -T php bash -c "cd /var/www/source && vendor/bin/oe-console oe:setup:demodata"

- name: Re-add FK constraints
  run: |
    docker compose exec -T php bash -c "cd /var/www/extensions/stripe/migration/scripts && ./setup-helper.sh add-fk"
```

## Alternative: SQL Commands

If you prefer to run SQL directly:

### Drop FK Constraints
```sql
ALTER TABLE osc_payment_contract DROP FOREIGN KEY FK_CONTRACT_ORDER;
ALTER TABLE osc_payment_order_state DROP FOREIGN KEY FK_ORDER_STATE;
ALTER TABLE osc_payment_order_state DROP FOREIGN KEY FK_ORDER_STATE_CONTRACT;
ALTER TABLE osc_payment_transaction DROP FOREIGN KEY FK_ORDER;
ALTER TABLE osc_payment_transaction DROP FOREIGN KEY FK_CONTRACT;
```

### Add FK Constraints Back
```sql
ALTER TABLE osc_payment_contract
    ADD CONSTRAINT FK_CONTRACT_ORDER
    FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE SET NULL;

ALTER TABLE osc_payment_order_state
    ADD CONSTRAINT FK_ORDER_STATE
    FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE;

ALTER TABLE osc_payment_order_state
    ADD CONSTRAINT FK_ORDER_STATE_CONTRACT
    FOREIGN KEY (OXCONTRACTID) REFERENCES osc_payment_contract(OXID) ON DELETE SET NULL;

ALTER TABLE osc_payment_transaction
    ADD CONSTRAINT FK_ORDER
    FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE;

ALTER TABLE osc_payment_transaction
    ADD CONSTRAINT FK_CONTRACT
    FOREIGN KEY (OXCONTRACTID) REFERENCES osc_payment_contract(OXID) ON DELETE SET NULL;
```

## Production Deployment

**IMPORTANT:** This workaround is ONLY needed for:
- Initial development setup with demodata
- Test environments that reset with demodata
- CI/CD pipelines that install demodata

**Production deployments** do NOT need this workaround because:
- Production doesn't use demodata
- Migrations run normally with FK constraints
- Real customer data is never truncated

## Troubleshooting

### FK constraint already exists
```bash
# Error: Duplicate foreign key constraint
# Solution: Constraints already added, no action needed
```

### FK constraint doesn't exist
```bash
# Error: Can't drop constraint that doesn't exist
# Solution: Constraints already dropped, no action needed
```

### Permission denied
```bash
# Error: Permission denied: ./setup-helper.sh
# Solution: Make script executable
chmod +x ./setup-helper.sh
```

### Connection refused
```bash
# Error: Can't connect to MySQL server
# Solution: Check DB_HOST, DB_USER, DB_PASS environment variables
export DB_HOST=your_mysql_host
```

## Why This Happens

MySQL's `TRUNCATE` command:
- Is a DDL operation (not DML like DELETE)
- Cannot trigger ON DELETE CASCADE or SET NULL
- Is blocked by ANY foreign key constraint referencing the table
- Cannot be used when FK constraints exist

OXID's demodata installer:
- Uses `TRUNCATE` for performance (faster than DELETE)
- Doesn't handle FK constraints from external modules
- Assumes tables can be truncated freely

Our solution:
- Temporarily removes FK constraints
- Allows TRUNCATE to proceed
- Re-adds constraints after demodata loads
- Maintains referential integrity in production

## See Also

- [Doctrine Migrations README](../README.md) - Main migration documentation
- [OXID Database Documentation](https://docs.oxid-esales.com/developer/en/latest/development/modules_components_themes/module/using_database.html)
- [MySQL TRUNCATE Documentation](https://dev.mysql.com/doc/refman/8.0/en/truncate-table.html)
