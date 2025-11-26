<h1 style="text-align: center">Stripe-Wallet for OXID eShop</h1>

## Developer Installation

```
git clone git@github.com:OXID-eSales/docker-eshop-sdk.git oxidshop --branch=b-8.0.x
cd oxidshop
git clone git@github.com:OXID-eSales/stripe-wallet.git stripe-install --recursive
./stripe-install/recipe/setup-twig-dev.sh
```

After install is finished you may need to add
```
error_reporting = E_ALL & ~E_WARNING & ~E_DEPRECATED
```

into ./containers/php/custom.ini

then do

```
make up
```

## Running Tests

### Pre-Commit Checks

Run the pre-commit check script before committing:

```bash
# Fast check (Unit tests only) - recommended for quick validation
./bin/pre-commit-check.sh

# Full check (Unit + Integration tests) - comprehensive validation
./bin/pre-commit-check.sh --full

# Skip PHPUnit tests (style checks only)
./bin/pre-commit-check.sh --no-phpunit
```

### Running Specific Test Suites

```bash
# Unit tests only
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Integration tests only
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration

# All tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml
```

### E2E Data Persistence Tests

The E2E tests use real database connections and leave data for inspection:

```bash
# Run data persistence tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
    extensions/stripe/tests/Integration/Component/Checkout/FullDataPersistenceFlowTest.php

# Query test data after running
docker compose exec mysql mysql -uroot -proot example -e \
    "SELECT * FROM osc_payment_contract WHERE OXID LIKE 'e2e_%';"
```

Test data uses `e2e_` prefix for easy identification and cleanup.
