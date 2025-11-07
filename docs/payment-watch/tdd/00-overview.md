# PaymentWatch - TDD Overview

**Test-Driven Development Strategy & Architecture**

Version: 1.0.0
Date: 2025-11-11

---

## Navigation

📖 **TDD Documentation:**
- **[00-overview.md](00-overview.md)** ← You are here
- [01-phase1-domain.md](01-phase1-domain.md) - Domain Layer (Value Objects)
- [02-phase2-infrastructure.md](02-phase2-infrastructure.md) - Infrastructure Layer (Strategies)
- [03-phase3-application.md](03-phase3-application.md) - Application Layer (Services)
- [04-best-practices.md](04-best-practices.md) - TDD Best Practices & Clean Code
- [05-phase5-6-integration.md](05-phase5-6-integration.md) - Controller & E2E Integration Tests

---

## Overview

This documentation provides a comprehensive Test-Driven Development (TDD) strategy for implementing the PaymentWatch module. We follow the **Red-Green-Refactor** cycle, apply **SOLID principles**, and maintain **Clean Code** practices throughout.

**Visual Architecture:** See [../puml/02-class-diagram-solid.puml](../puml/02-class-diagram-solid.puml)

---

## TDD Philosophy

### The Red-Green-Refactor Cycle

```
┌─────────────────────────────────────────────────────┐
│                                                     │
│  RED → Write failing test                          │
│   ↓                                                 │
│  GREEN → Make test pass (simplest code)            │
│   ↓                                                 │
│  REFACTOR → Improve code while tests stay green    │
│   ↓                                                 │
│  (Repeat)                                           │
│                                                     │
└─────────────────────────────────────────────────────┘
```

### Benefits for PaymentWatch

1. **Security**: Tests catch injection vulnerabilities
2. **Reliability**: All code paths tested
3. **Refactoring**: Safe to improve without breaking
4. **Documentation**: Tests serve as living documentation
5. **Design**: Forces good architecture (loose coupling)

---

## SOLID Principles Applied

### 1. Single Responsibility Principle (SRP)

**Each class has ONE reason to change:**

- `AssumptionController` - HTTP request/response only
- `AuthenticationService` - Authentication logic only
- `AssumptionParser` - Request parsing only
- `QueryBuilder` - Query construction/execution only

**Visual:** See [../puml/06-component-dependencies.puml](../puml/06-component-dependencies.puml)

### 2. Open/Closed Principle (OCP)

**Open for extension, closed for modification:**

- **Operator Strategy Pattern**: Add new operators without modifying QueryBuilder
- **Interface-based design**: Extend via new implementations

```php
// Adding new operator: just create new class implementing interface
class RegexOperator implements OperatorStrategyInterface {
    public function compare($actual, $expected): bool {
        return preg_match($expected, $actual);
    }
}

// Register in factory - no existing code modified!
$factory->register('regex', new RegexOperator());
```

### 3. Liskov Substitution Principle (LSP)

**Subtypes must be substitutable for their base types:**

- All `OperatorStrategyInterface` implementations can replace each other
- Mock implementations work seamlessly in tests

```php
// Production code uses real implementation
$queryBuilder = new QueryBuilder($connection, $sanitizer, $factory);

// Test code uses mock - same interface!
$mockQueryBuilder = $this->createMock(QueryExecutorInterface::class);
```

### 4. Interface Segregation Principle (ISP)

**Clients should not depend on interfaces they don't use:**

- Small, focused interfaces (not one big "IPaymentWatch" interface)
- `AuthenticatorInterface` - only auth methods
- `ParserInterface` - only parsing methods
- `QueryExecutorInterface` - only query methods

### 5. Dependency Inversion Principle (DIP)

**Depend on abstractions, not concretions:**

- Controller depends on `AuthenticatorInterface`, not `AuthenticationService`
- Easy to swap implementations (testing, different auth methods)

```php
class AssumptionController {
    public function __construct(
        private AuthenticatorInterface $authenticator,  // ← Interface!
        private ParserInterface $parser,
        private QueryExecutorInterface $queryExecutor
    ) {}
}
```

**Visual:** See [../puml/02-class-diagram-solid.puml](../puml/02-class-diagram-solid.puml)

---

## Test Organization

### Directory Structure

```
/home/dtkachev/osc/strpwt7-oct21/source/extensions/stripe/tests/
├── Unit/
│   └── Watch/
│       ├── ValueObject/
│       │   ├── AssumptionRequestTest.php
│       │   ├── AssumptionResponseTest.php
│       │   └── AuthConfigTest.php
│       ├── Service/
│       │   ├── AuthenticationServiceTest.php
│       │   ├── IpValidatorTest.php
│       │   ├── ApiKeyValidatorTest.php
│       │   ├── AssumptionParserTest.php
│       │   ├── RequestValidatorTest.php
│       │   └── SqlSanitizerTest.php
│       ├── Strategy/
│       │   ├── EqualityOperatorTest.php
│       │   ├── ComparisonOperatorTest.php
│       │   ├── LikeOperatorTest.php
│       │   └── NullCheckOperatorTest.php
│       └── Infrastructure/
│           ├── QueryBuilderTest.php
│           └── OperatorStrategyFactoryTest.php
├── Integration/
│   └── Watch/
│       ├── Controller/
│       │   └── AssumptionControllerIntegrationTest.php
│       ├── Database/
│       │   └── QueryExecutionIntegrationTest.php
│       └── EndToEnd/
│           └── CompleteFlowTest.php
└── Acceptance/
    └── Watch/
        ├── HappyPathTest.php
        ├── SecurityTest.php
        └── ErrorHandlingTest.php
```

### Test Pyramid

```
         /\
        /  \  Acceptance Tests (5%)
       /────\
      /      \  Integration Tests (15%)
     /────────\
    /          \  Unit Tests (80%)
   /────────────\
```

---

## Implementation Roadmap

### Phase 1: Domain Layer (Inside-Out)
**Goal:** Build immutable value objects with zero dependencies

📄 **Documentation:** [01-phase1-domain.md](01-phase1-domain.md)

**Components:**
- AssumptionRequest (Value Object)
- AssumptionResponse (Value Object)
- AuthConfig (Value Object)

### Phase 2: Infrastructure Layer
**Goal:** Database interaction and operator strategies

📄 **Documentation:** [02-phase2-infrastructure.md](02-phase2-infrastructure.md)

**Components:**
- OperatorStrategyInterface
- EqualityOperator, ComparisonOperator, LikeOperator, NullCheckOperator
- OperatorStrategyFactory

### Phase 3: Application Layer
**Goal:** Parsing, validation, authentication services

📄 **Documentation:** [03-phase3-application.md](03-phase3-application.md)

**Components:**
- RequestValidator (Security-critical!)
- IpValidator
- ApiKeyValidator
- AssumptionParser
- AuthenticationService

### Phase 4: Infrastructure Completion
**Goal:** Query building and execution

**Components:**
- QueryBuilder
- SqlSanitizer
- AuditLogger

### Phase 5 & 6: Controller & Integration Tests
**Goal:** HTTP endpoint with real E2E integration tests

📄 **Documentation:** [05-phase5-6-integration.md](05-phase5-6-integration.md)

**Components:**
- AssumptionController - HTTP endpoint handler
- **Real cURL integration tests** - Actual HTTP requests
- E2E payment flow tests - Complete scenario simulation
- Database integration tests - Real database queries
- Security validation tests - SQL injection, auth failures
- Performance tests - Response time verification

---

## Quick Start

### 1. Set Up Test Environment

```bash
cd /home/dtkachev/osc/strpwt7-oct21/source/extensions/stripe

# Create test directory structure
mkdir -p tests/Unit/Watch/{ValueObject,Service,Strategy,Infrastructure}
mkdir -p tests/Integration/Watch/{Controller,Database,EndToEnd}
mkdir -p tests/Acceptance/Watch
```

### 2. Install PHPUnit

```bash
composer require --dev phpunit/phpunit
```

### 3. Create phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php"
         colors="true"
         verbose="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="Acceptance">
            <directory>tests/Acceptance</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </coverage>
</phpunit>
```

### 4. Start with Phase 1

Follow the TDD workflow in [01-phase1-domain.md](01-phase1-domain.md):

1. **RED**: Write failing test
2. **GREEN**: Implement minimal code to pass
3. **REFACTOR**: Improve code while tests stay green

---

## Testing Commands

### 🐳 Docker Environment (REQUIRED)

**ALWAYS run tests in Docker container with Xdebug coverage enabled:**

```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php
```

**Why Docker?**
- Consistent environment across machines
- Proper database access for integration tests
- Xdebug coverage support
- Matches production PHP version and extensions

---

### Run All Tests (Docker)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php
```

### Run Specific Suite (Docker)
```bash
# Unit tests only
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --testsuite Unit

# Integration tests only
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --testsuite Integration
```

### Run Specific Test File (Docker)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Unit/Watch/ValueObject/AssumptionRequestTest.php
```

### Run with Coverage (Docker)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --coverage-html coverage/

# View coverage report (from host)
open coverage/index.html
```

### Run with Human-Readable Output (Docker)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --testdox
```

**Example Output:**
```
AssumptionRequest
 ✔ It creates valid assumption request
 ✔ It builds field path
 ✔ It uses default operator when not provided
 ✔ It is immutable
```

---

### Short Alias (Optional)

Add to your shell profile for convenience:

```bash
# Add to ~/.bashrc or ~/.zshrc
alias phpunit-watch='docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit -c /var/www/extensions/stripe/tests/phpunit.xml --bootstrap=/var/www/source/bootstrap.php'

# Usage
phpunit-watch
phpunit-watch --testsuite Unit
phpunit-watch tests/Unit/Watch/ValueObject/AssumptionRequestTest.php
phpunit-watch --testdox
```

---

## Visual Reference Index

| Diagram | Purpose | Phase |
|---------|---------|-------|
| [../puml/01-architecture-overview.puml](../puml/01-architecture-overview.puml) | System architecture | All |
| [../puml/02-class-diagram-solid.puml](../puml/02-class-diagram-solid.puml) | SOLID design | All |
| [../puml/03-sequence-assumption-flow.puml](../puml/03-sequence-assumption-flow.puml) | Happy path flow | Phase 5 |
| [../puml/04-sequence-error-flows.puml](../puml/04-sequence-error-flows.puml) | Error handling | Phase 5 |
| [../puml/05-state-machine-request.puml](../puml/05-state-machine-request.puml) | Request states | Phase 5 |
| [../puml/06-component-dependencies.puml](../puml/06-component-dependencies.puml) | Dependency graph | All |

---

## Key Principles

### Test-Driven Development
- **Write tests first** (RED)
- **Make them pass** (GREEN)
- **Refactor** while tests stay green

### SOLID Principles
- **Single Responsibility**: One reason to change
- **Open/Closed**: Open for extension, closed for modification
- **Liskov Substitution**: Subtypes replaceable
- **Interface Segregation**: Small, focused interfaces
- **Dependency Inversion**: Depend on abstractions

### Clean Code
- Meaningful names
- Small functions
- Early returns
- Immutability
- Explicit over implicit

---

## Next Steps

1. **Read Phase 1**: [01-phase1-domain.md](01-phase1-domain.md)
2. **Set up test environment**: Create directories and phpunit.xml
3. **Start coding**: Follow RED-GREEN-REFACTOR cycle
4. **Review best practices**: [04-best-practices.md](04-best-practices.md)

---

**Let's build PaymentWatch with confidence through TDD!** 🧪
