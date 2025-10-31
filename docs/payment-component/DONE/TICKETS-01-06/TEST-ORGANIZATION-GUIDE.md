# Test Organization Guide

**Version:** 1.0.0
**Date:** 2025-10-30
**Status:** Official Standard

---

## 📋 Overview

This document defines the **official test organization structure** for the OXID Payment Component project. All test files MUST follow this structure.

---

## 🏗️ Directory Structure

```
tests/
├── phpunit.xml                     ← PHPUnit configuration (defines test suites)
├── bootstrap.php                   ← Test bootstrap
├── Support/                        ← Test helpers, fixtures, mocks
│   ├── Fixtures/
│   └── Mocks/
├── Unit/                          ← PHPUnit "Unit" test suite ⚡ FAST
│   ├── Component/                 ← Component layer unit tests
│   │   ├── EventSystem/
│   │   │   ├── Event/
│   │   │   ├── Handler/
│   │   │   └── Subscriber/
│   │   ├── Model/
│   │   ├── Repository/
│   │   ├── Service/
│   │   └── Contract/
│   └── Stripe/                    ← Stripe provider unit tests
│       ├── Adapter/
│       ├── Handler/
│       └── Service/
└── Integration/                   ← PHPUnit "Integration" test suite 🐌 SLOWER
    ├── Component/                 ← Component integration tests
    │   ├── EventSystem/
    │   ├── Service/
    │   └── Repository/
    └── Stripe/                    ← Stripe provider integration tests
        ├── Adapter/
        └── Webhook/
```

---

## 📐 Test Organization Principles

### 1. Suite-First Organization

```
tests/
├── Unit/              ← Test SUITE (defined in phpunit.xml)
│   ├── Component/     ← Code LAYER
│   └── Stripe/        ← Code LAYER
└── Integration/       ← Test SUITE (defined in phpunit.xml)
    ├── Component/     ← Code LAYER
    └── Stripe/        ← Code LAYER
```

**✅ CORRECT:** `tests/Unit/Component/EventSystem/Event/`
**❌ WRONG:** `tests/Component/Unit/EventSystem/Event/`

### 2. Mirror Source Structure

Test directory structure mirrors `src/` structure:

```
src/Component/EventSystem/Event/Contract/ContractCreatedEvent.php
    ↓ mirrors ↓
tests/Unit/Component/EventSystem/Event/Contract/ContractCreatedEventTest.php
```

### 3. Separate Component and Provider Tests

- **Component tests** in `tests/{Suite}/Component/`
- **Provider tests** in `tests/{Suite}/{Provider}/`

---

## 🎯 Test Path Patterns

### Unit Tests (Fast, Isolated)

| Source Path | Test Path |
|------------|-----------|
| `src/Component/EventSystem/Event/EventContext.php` | `tests/Unit/Component/EventSystem/Event/EventContextTest.php` |
| `src/Component/Model/PaymentContract.php` | `tests/Unit/Component/Model/PaymentContractTest.php` |
| `src/Component/Service/PaymentService.php` | `tests/Unit/Component/Service/PaymentServiceTest.php` |
| `src/Stripe/Adapter/StripeAdapter.php` | `tests/Unit/Stripe/Adapter/StripeAdapterTest.php` |

### Integration Tests (Slower, Real Dependencies)

| Test Type | Test Path |
|----------|-----------|
| Event flow | `tests/Integration/Component/EventSystem/Event/ContractEventFlowTest.php` |
| Service integration | `tests/Integration/Component/Service/PaymentServiceIntegrationTest.php` |
| Repository | `tests/Integration/Component/Repository/ContractRepositoryTest.php` |
| Stripe SDK | `tests/Integration/Stripe/Adapter/StripeAdapterIntegrationTest.php` |

---

## 📦 Namespace Mapping

### Unit Test Namespace

```php
// Source file
// Path: src/Component/EventSystem/Event/Contract/ContractCreatedEvent.php
namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

class ContractCreatedEvent { }

// Unit test file
// Path: tests/Unit/Component/EventSystem/Event/Contract/ContractCreatedEventTest.php
namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Event\Contract;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractCreatedEvent;

class ContractCreatedEventTest extends TestCase { }
```

### Integration Test Namespace

```php
// Integration test file
// Path: tests/Integration/Component/EventSystem/Event/ContractEventFlowTest.php
namespace OxidSolutionCatalysts\Payments\Tests\Integration\Component\EventSystem\Event;

class ContractEventFlowTest extends TestCase { }
```

### Provider Test Namespace

```php
// Stripe unit test
// Path: tests/Unit/Stripe/Adapter/StripeAdapterTest.php
namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Adapter;

class StripeAdapterTest extends TestCase { }

// Stripe integration test
// Path: tests/Integration/Stripe/Adapter/StripeAdapterIntegrationTest.php
namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Adapter;

class StripeAdapterIntegrationTest extends TestCase { }
```

---

## 🔀 Multi-Provider Support

When adding new payment providers (Unzer, PayPal, etc.), follow the same pattern:

```
tests/
├── Unit/
│   ├── Component/         ← Shared component tests
│   ├── Stripe/            ← Stripe-specific tests
│   ├── Unzer/             ← Unzer-specific tests
│   └── PayPal/            ← PayPal-specific tests
└── Integration/
    ├── Component/         ← Shared component integration
    ├── Stripe/            ← Stripe integration
    ├── Unzer/             ← Unzer integration
    └── PayPal/            ← PayPal integration
```

---

## 📝 Naming Conventions

### Test Class Names

| Type | Pattern | Example |
|------|---------|---------|
| Unit test | `{ClassName}Test` | `EventContextTest` |
| Integration test | `{Feature}IntegrationTest` | `ContractEventFlowTest` |
| Integration test (provider) | `{ClassName}IntegrationTest` | `StripeAdapterIntegrationTest` |

### Test Method Names

```php
// ✅ GOOD: Descriptive, explains scenario and expectation
public function testGetContract_ReturnsContract(): void
public function testConstruct_WithInvalidAmount_ThrowsException(): void
public function testDispatch_CallsAllRegisteredListeners(): void

// ❌ BAD: Vague, doesn't explain scenario
public function testGetContract(): void
public function testConstructor(): void
public function testDispatch(): void
```

---

## 🎪 PHPUnit Configuration

```xml
<!-- tests/phpunit.xml -->
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>Unit/</directory>     ← All unit tests
        </testsuite>
        <testsuite name="Integration">
            <directory>Integration/</directory>  ← All integration tests
        </testsuite>
    </testsuites>
</phpunit>
```

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit -c tests/phpunit.xml

# Run only unit tests (fast)
vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit

# Run only integration tests (slower)
vendor/bin/phpunit -c tests/phpunit.xml --testsuite Integration

# Run specific test file
vendor/bin/phpunit tests/Unit/Component/EventSystem/Event/EventContextTest.php

# Run tests for Component layer only
vendor/bin/phpunit tests/Unit/Component/

# Run tests for Stripe provider only
vendor/bin/phpunit tests/Unit/Stripe/
```

---

## ✅ Quick Reference

### Where Should My Test Go?

1. **Is it a unit test or integration test?**
   - Unit → `tests/Unit/`
   - Integration → `tests/Integration/`

2. **Is it Component or Provider code?**
   - Component → `tests/{Suite}/Component/`
   - Stripe → `tests/{Suite}/Stripe/`
   - Unzer → `tests/{Suite}/Unzer/`

3. **What layer is it testing?**
   - EventSystem → `tests/{Suite}/{Layer}/EventSystem/`
   - Model → `tests/{Suite}/{Layer}/Model/`
   - Service → `tests/{Suite}/{Layer}/Service/`
   - Repository → `tests/{Suite}/{Layer}/Repository/`

### Example Decision Tree

```
I'm writing a test for: src/Component/EventSystem/Event/EventContext.php

Q: Unit or Integration?
A: Unit (no external dependencies)
   → tests/Unit/

Q: Component or Provider?
A: Component
   → tests/Unit/Component/

Q: What layer/structure?
A: EventSystem/Event/
   → tests/Unit/Component/EventSystem/Event/

Q: What's the class name?
A: EventContext
   → tests/Unit/Component/EventSystem/Event/EventContextTest.php ✅
```

---

## 🚫 Common Mistakes

### ❌ Wrong Structure (Old Pattern)

```
tests/
├── Component/
│   ├── Unit/              ← WRONG: Layer before suite
│   └── Integration/
└── Stripe/
    ├── Unit/
    └── Integration/
```

### ✅ Correct Structure

```
tests/
├── Unit/                  ← CORRECT: Suite before layer
│   ├── Component/
│   └── Stripe/
└── Integration/
    ├── Component/
    └── Stripe/
```

---

## 📚 Related Documentation

- [09-01-tdd-overview.md](09-01-tdd-overview.md) - TDD strategy overview
- [09-03-tdd-event-system.md](09-03-tdd-event-system.md) - Event system testing
- [10-test-organization.md](10-test-organization.md) - Detailed test organization
- [SPRINT-1-TICKET-02-event-layer.md](SPRINT-1-TICKET-02-event-layer.md) - Event layer implementation

---

## 🔄 Migration Guide

If you have tests in the old structure, migrate them:

```bash
# Old structure
tests/Component/Unit/Event/EventContextTest.php

# New structure (move to)
tests/Unit/Component/EventSystem/Event/EventContextTest.php

# Migration command
mkdir -p tests/Unit/Component/EventSystem/Event/
mv tests/Component/Unit/Event/EventContextTest.php \
   tests/Unit/Component/EventSystem/Event/EventContextTest.php
```

Update namespace in the test file:

```php
// OLD
namespace OxidSolutionCatalysts\Payments\Tests\Component\Unit\Event;

// NEW
namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Event;
```

---

**Document Status:** ✅ **Official Standard**
**Effective Date:** 2025-10-30
**Version:** 1.0.0

All new tests MUST follow this structure. Existing tests should be migrated progressively.
