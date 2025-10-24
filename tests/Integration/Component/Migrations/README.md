# Migration Integration Tests

This directory contains TDD (Test-Driven Development) integration tests that verify Doctrine migrations correctly persist database schema changes.

## Test Files

- **MigrationTestBase.php** - Base class with assertion helpers for migration testing
- **PaymentTransactionMigrationTest.php** - Tests for `osc_stripe_payment_transaction` table
- **PaymentOrderStateMigrationTest.php** - Tests for `osc_stripe_payment_order_state` table
- **PaymentCustomerMigrationTest.php** - Tests for `osc_stripe_payment_customer` table
- **PaymentBasketSnapshotMigrationTest.php** - Tests for `osc_stripe_payment_basket_snapshot` table

## Test Coverage

Each migration test verifies:

✅ **Table Creation** - Migration creates the expected table
✅ **Column Existence** - All required columns are present
✅ **Column Types** - Columns have correct data types (string, float, text, datetime)
✅ **Primary Keys** - Table has primary key on OXID column
✅ **Indexes** - Required indexes and unique constraints exist
✅ **Idempotency** - Migration can be run multiple times safely
✅ **Comments** - Columns have descriptive comments
✅ **Constraints** - Unique constraints and foreign key indexes work correctly

## Total Test Cases

- **30 test methods** across 4 migration test classes
- **~150+ assertions** covering all aspects of schema validation

## Running Tests

### Prerequisites

Tests require a configured OXID eShop environment with:
- Database connection configured in `config.inc.php`
- OXID shop bootstrapped
- Module installed and activated

### Run All Tests

```bash
# From OXID shop root
vendor/bin/runtests

# Or from module directory with proper bootstrap
cd /var/www/extensions/stripe
XDEBUG_MODE=coverage vendor/bin/phpunit \
  -c tests/phpunit.xml \
  --bootstrap /var/www/source/bootstrap.php \
  tests/Integration/Migrations/
```

### Run Specific Migration Test

```bash
# Test transaction table migration
vendor/bin/phpunit tests/Integration/Migrations/PaymentTransactionMigrationTest.php

# Test with verbose output
vendor/bin/phpunit --testdox tests/Integration/Migrations/
```

## Test Structure

### Example Test (Given-When-Then Pattern)

```php
/** @test */
public function migration_creates_payment_transaction_table(): void
{
    // Given: Fresh schema
    $schema = new Schema();
    $migration = new Version20251024140000($this->connection, new NullLogger());

    // When: Running migration
    $migration->up($schema);
    $this->refreshSchema();

    // Then: Table should exist
    $this->assertTableExists('osc_stripe_payment_transaction');
}
```

## Helper Methods (MigrationTestBase)

- `assertTableExists(string $tableName)` - Verify table exists
- `assertColumnExists(string $tableName, string $columnName)` - Verify column exists
- `assertColumnType(string $tableName, string $columnName, string $expectedType)` - Verify column type
- `assertIndexExists(string $tableName, string $indexName)` - Verify index exists
- `assertPrimaryKeyExists(string $tableName)` - Verify primary key
- `getColumnDefinition(string $tableName, string $columnName)` - Get column details
- `refreshSchema()` - Reload schema after changes

## Continuous Integration

These tests should be run:
- ✅ Before committing migration changes
- ✅ In CI/CD pipeline after module installation
- ✅ During module activation testing
- ✅ After database schema updates

## Troubleshooting

### DatabaseNotConfiguredException

**Error**: `The database connection has not been configured in config.inc.php`

**Solution**: Ensure tests run with OXID bootstrap:
```bash
vendor/bin/phpunit --bootstrap /var/www/source/bootstrap.php tests/Integration/Migrations/
```

### oxNew() Undefined Function

**Error**: `Call to undefined function oxNew()`

**Solution**: Tests must run within OXID environment. Use shop's test runner or ensure proper bootstrap.

## Development Notes

- Tests extend `OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase`
- Each test cleans up (drops tables) in `tearDown()`
- Tests are idempotent and can run in any order
- Uses Doctrine DBAL for schema introspection
- Compatible with OXID 7.3+ testing framework
