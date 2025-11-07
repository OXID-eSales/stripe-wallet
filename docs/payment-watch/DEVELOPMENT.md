# PaymentWatch Development Quick Reference

**For Developers:** Quick commands and workflows for PaymentWatch development.

---

## Table of Contents

1. [Setup](#setup)
2. [Running Tests](#running-tests)
3. [TDD Workflow](#tdd-workflow)
4. [Useful Commands](#useful-commands)
5. [Debugging](#debugging)
6. [Code Structure](#code-structure)
7. [Contributing](#contributing)

---

## Setup

### Initial Setup

```bash
# Clone repository
cd /path/to/oxid-shop/source/extensions/stripe

# Install dependencies
composer install

# Run Docker containers
cd /path/to/oxid-shop
docker compose up -d

# Activate module
docker compose exec php vendor/bin/oe-console oe:module:activate osc_stripe_wallet
```

### IDE Configuration

**PHPStorm:**
- Settings → PHP → CLI Interpreter → Add Docker Compose
- Settings → PHP → Test Frameworks → Add PHPUnit by Remote Interpreter
- Configuration file: `tests/phpunit.xml`
- Bootstrap: `/var/www/source/bootstrap.php`

**VSCode:**
```json
{
  "php.validate.executablePath": "docker compose exec -T php php",
  "phpunit.phpunit": "vendor/bin/phpunit",
  "phpunit.args": [
    "-c", "/var/www/extensions/stripe/tests/phpunit.xml"
  ]
}
```

---

## Running Tests

### Unit Tests

```bash
# All unit tests
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --testsuite Unit

# Specific test file
docker compose exec -T php vendor/bin/phpunit \
  /var/www/extensions/stripe/tests/Unit/Watch/ValueObject/AssumptionRequestTest.php

# With coverage
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --testsuite Unit \
  --coverage-html coverage/
```

### Integration Tests

```bash
# Set up environment
export PAYMENTWATCH_URL=http://localhost
export PAYMENTWATCH_API_KEY=$(openssl rand -hex 32)

# Run integration tests
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --testsuite Integration \
  --group watch

# Specific integration test
docker compose exec -T php vendor/bin/phpunit \
  /var/www/extensions/stripe/tests/Integration/Watch/Controller/AssumptionControllerIntegrationTest.php
```

### Test Groups

```bash
# By component
--group watch          # All PaymentWatch tests
--group value-object   # Value object tests
--group strategy       # Strategy pattern tests
--group security       # Security tests

# By type
--group unit           # Unit tests only
--group integration    # Integration tests only
--group e2e            # End-to-end tests
--group performance    # Performance benchmarks
```

### Coverage Reports

```bash
# HTML coverage report
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --coverage-html coverage/

# Open in browser
open coverage/index.html

# Text coverage summary
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --coverage-text
```

---

## TDD Workflow

### Red-Green-Refactor Cycle

#### 1. RED: Write Failing Test

```php
// tests/Unit/Watch/Service/NewFeatureTest.php
public function test_it_does_something(): void
{
    $service = new NewFeature();
    $result = $service->doSomething('input');

    $this->assertEquals('expected', $result);
}
```

Run test (should FAIL):
```bash
docker compose exec -T php vendor/bin/phpunit \
  tests/Unit/Watch/Service/NewFeatureTest.php
```

Output: ❌ `Class 'NewFeature' not found`

#### 2. GREEN: Make Test Pass

```php
// src/Watch/Service/NewFeature.php
class NewFeature
{
    public function doSomething(string $input): string
    {
        return 'expected'; // Minimal implementation
    }
}
```

Run test (should PASS):
```bash
docker compose exec -T php vendor/bin/phpunit \
  tests/Unit/Watch/Service/NewFeatureTest.php
```

Output: ✅ `OK (1 test, 1 assertion)`

#### 3. REFACTOR: Improve Code

```php
// src/Watch/Service/NewFeature.php
class NewFeature
{
    public function doSomething(string $input): string
    {
        return $this->processInput($input); // Better implementation
    }

    private function processInput(string $input): string
    {
        // Actual logic here
    }
}
```

Run test (should still PASS):
```bash
docker compose exec -T php vendor/bin/phpunit \
  tests/Unit/Watch/Service/NewFeatureTest.php
```

Output: ✅ `OK (1 test, 1 assertion)`

---

## Useful Commands

### Module Management

```bash
# Activate module
docker compose exec php vendor/bin/oe-console oe:module:activate osc_stripe_wallet

# Deactivate module
docker compose exec php vendor/bin/oe-console oe:module:deactivate osc_stripe_wallet

# List modules
docker compose exec php vendor/bin/oe-console oe:module:list

# Module info
docker compose exec php vendor/bin/oe-console oe:module:list --verbose
```

### Database

```bash
# Run migrations
docker compose exec php vendor/bin/oe-console migrations:migrate

# Migration status
docker compose exec php vendor/bin/oe-console migrations:status

# Rollback migration
docker compose exec php vendor/bin/oe-console migrations:migrate prev

# Access MySQL
docker compose exec mysql mysql -uroot -proot oxid_eshop
```

### Cache

```bash
# Clear cache
docker compose exec php vendor/bin/oe-console oe:cache:clear

# Clear Symfony cache
docker compose exec php rm -rf /var/www/var/cache/*
```

### Routes

```bash
# List all routes
docker compose exec php vendor/bin/oe-console debug:router

# Find PaymentWatch routes
docker compose exec php vendor/bin/oe-console debug:router | grep paymentwatch
```

### Code Quality

```bash
# PHP CodeSniffer
docker compose exec php vendor/bin/phpcs \
  --standard=tests/phpcs.xml \
  src/Watch/

# PHP Code Beautifier (auto-fix)
docker compose exec php vendor/bin/phpcbf \
  --standard=tests/phpcs.xml \
  src/Watch/

# PHPStan (static analysis)
docker compose exec php vendor/bin/phpstan analyse \
  -c tests/PhpStan/phpstan.neon \
  --level=max \
  src/Watch/

# PHPMD (mess detector)
docker compose exec php vendor/bin/phpmd \
  src/Watch/ \
  ansi \
  tests/PhpMd/standard.xml
```

---

## Debugging

### Xdebug

```bash
# Enable Xdebug for debugging
export XDEBUG_MODE=debug

docker compose exec -e XDEBUG_MODE=debug php vendor/bin/phpunit \
  tests/Unit/Watch/ValueObject/AssumptionRequestTest.php

# Enable Xdebug for coverage
export XDEBUG_MODE=coverage

docker compose exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  --coverage-html coverage/
```

### Debug Logs

```bash
# Watch OXID logs
tail -f var/log/oxideshop.log

# Filter PaymentWatch logs
tail -f var/log/oxideshop.log | grep PaymentWatch

# Filter by log level
tail -f var/log/oxideshop.log | grep "ERROR\|WARNING"
```

### Manual Testing

```bash
# Test endpoint with cURL
curl -X POST http://localhost/paymentwatch/assume \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $(openssl rand -hex 32)" \
  -d '{
    "assumption": {
      "osc_payment_contract.OXSTATE": "pending",
      "where": {
        "OXID": "test123"
      }
    }
  }' | jq .

# With verbose output
curl -v -X POST http://localhost/paymentwatch/assume \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_key_here" \
  -d @payload.json
```

---

## Code Structure

### Directory Layout

```
src/Watch/
├── Controller/           # HTTP controllers
│   └── AssumptionController.php
├── Service/              # Business logic services
│   ├── ApiKeyValidator.php
│   ├── AssumptionParser.php
│   ├── AuditLogger.php
│   ├── AuthenticationService.php
│   ├── IpValidator.php
│   ├── QueryBuilder.php
│   └── RequestValidator.php
├── Strategy/             # Operator strategies (Strategy Pattern)
│   ├── ComparisonOperator.php
│   ├── EqualityOperator.php
│   ├── LikeOperator.php
│   ├── NullCheckOperator.php
│   ├── OperatorStrategyFactory.php
│   └── OperatorStrategyInterface.php
├── ValueObject/          # Immutable value objects
│   ├── AssumptionRequest.php
│   ├── AssumptionResponse.php
│   └── AuthConfig.php
├── Exception/            # Custom exceptions
│   ├── AuthenticationException.php
│   └── ValidationException.php
└── Config/               # Configuration files
    ├── routes.yaml
    └── services.yaml

tests/
├── Unit/Watch/           # Unit tests (isolated, mocked)
│   ├── ValueObject/
│   ├── Service/
│   └── Strategy/
├── Integration/Watch/    # Integration tests (real DB, HTTP)
│   ├── Controller/
│   ├── EndToEnd/
│   ├── Security/
│   └── Performance/
└── phpunit.xml
```

### Key Classes

| Class | Purpose | Layer |
|-------|---------|-------|
| `AssumptionController` | HTTP endpoint handler | Presentation |
| `QueryBuilder` | Database query execution | Infrastructure |
| `AuthenticationService` | IP + API key auth | Application |
| `RequestValidator` | SQL injection prevention | Application |
| `OperatorStrategyFactory` | Create operator strategies | Infrastructure |
| `AssumptionRequest` | Request data | Domain |
| `AssumptionResponse` | Response data | Domain |

### Design Patterns Used

- **Strategy Pattern:** Operator comparison strategies
- **Factory Pattern:** OperatorStrategyFactory
- **Value Object Pattern:** Immutable request/response objects
- **Dependency Injection:** Service container
- **Repository Pattern:** Database access abstraction

---

## Contributing

### Before Committing

```bash
# Run all checks
composer style           # Code style checks
composer phpunit         # Run tests
composer static          # Static analysis

# Or individually:
composer phpcs           # Code sniffer
composer phpstan         # Static analysis
composer phpmd           # Mess detector
```

### Commit Messages

Format:
```
[Component] Brief description

- Detailed point 1
- Detailed point 2

Fixes #123
```

Example:
```
[PaymentWatch] Add timing attack prevention

- Implement ApiKeyValidator with hash_equals()
- Add constant-time comparison tests
- Update security documentation

Fixes #456
```

### Pull Request Checklist

- [ ] All tests passing
- [ ] Code coverage >= 90%
- [ ] PHPStan level max passes
- [ ] PHPCS style checks pass
- [ ] Documentation updated
- [ ] CHANGELOG.md updated
- [ ] No breaking changes (or documented)

---

## Quick Reference Card

### Most Common Commands

```bash
# Run unit tests
docker compose exec -T php vendor/bin/phpunit -c /var/www/extensions/stripe/tests/phpunit.xml --testsuite Unit --group watch

# Run integration tests
docker compose exec -T php vendor/bin/phpunit -c /var/www/extensions/stripe/tests/phpunit.xml --testsuite Integration --group watch

# Code style check
docker compose exec php vendor/bin/phpcs --standard=tests/phpcs.xml src/Watch/

# Static analysis
docker compose exec php vendor/bin/phpstan analyse -c tests/PhpStan/phpstan.neon src/Watch/

# Clear cache
docker compose exec php vendor/bin/oe-console oe:cache:clear

# Test endpoint
curl -X POST http://localhost/paymentwatch/assume -H "Content-Type: application/json" -H "X-API-Key: test_key" -d '{"assumption":{"osc_payment_contract.OXSTATE":"pending","where":{"OXID":"test123"}}}'
```

---

## Keyboard Shortcuts (PHPStorm)

- `Ctrl+Shift+T` - Go to test
- `Ctrl+Shift+F10` - Run test under cursor
- `Alt+Shift+F10` - Run last test
- `Ctrl+Shift+A` - Find action
- `Ctrl+B` - Go to declaration

---

## Resources

- **Main Documentation:** [README.md](README.md)
- **Implementation Guide:** [01-implementation-guide.md](01-implementation-guide.md)
- **Test Scenarios:** [02-test-scenarios.md](02-test-scenarios.md)
- **Deployment Guide:** [DEPLOYMENT.md](DEPLOYMENT.md)
- **Sprint Plan:** [SPRINT-PLAN.md](SPRINT-PLAN.md)

---

**Happy Coding!** 🚀
