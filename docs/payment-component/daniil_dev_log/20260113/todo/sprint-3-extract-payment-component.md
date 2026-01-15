# Sprint 3: Extract Payment Component to Separate Package

**Ticket:** STRP-77
**Date:** January 13, 2026
**Status:** TODO

---

## Decisions Made

| Question | Decision |
|----------|----------|
| Namespace | `OxidEsales\PaymentComponent\` |
| Doctrine repositories | Keep in payment-component |
| services.yaml | Not needed (library, not OXID module) |

---

## Objective

Extract the provider-agnostic payment component (`src/Component`) to a separate composer package at `/home/dtkachev/osc/strpwt7-nov26/source/extensions/payment-component`.

This package will be:
- **Package Name:** `oxid-esales/payment-component`
- **Namespace:** `OxidEsales\PaymentComponent\`
- **Type:** Composer library (NOT an OXID extension)
- **Purpose:** Reusable payment abstraction layer for any payment provider

---

## Source Files to Extract

### From: `extensions/stripe/src/Component/`
### To: `extensions/payment-component/src/`

**Directory Structure:**
```
src/Component/
├── Adapter/           # Payment adapter interfaces and DTOs
│   ├── Exception/
│   ├── Request/
│   └── Response/
├── Contract/          # PaymentContract domain model
├── Controller/        # Base webhook controllers
│   ├── Core/
│   └── Webhook/
├── EventSystem/       # Event dispatcher and handlers
│   ├── Event/
│   │   ├── Contract/
│   │   └── Payment/
│   ├── Handler/
│   └── Subscriber/
├── GraphQL/           # GraphQL schema definitions
│   └── Schema/
├── Middleware/        # HTTP middleware
├── Model/             # Domain models
├── Order/             # Order-related classes
├── Repository/        # Repository interfaces
├── Service/           # Core services
│   ├── Factory/
│   └── Result/
├── Traits/            # Reusable traits
├── Transaction/       # Transaction handling
└── Webhook/           # Webhook processing
```

### Tests to Extract

**Unit Tests:**
- From: `extensions/stripe/tests/Unit/Component/`
- To: `extensions/payment-component/tests/Unit/`
- Count: ~81 files

**Integration Tests:**
- From: `extensions/stripe/tests/Integration/Component/`
- To: `extensions/payment-component/tests/Integration/`
- Count: ~12 files

---

## New Package Structure

```
extensions/payment-component/
├── composer.json
├── phpunit.xml
├── phpstan.neon
├── src/
│   ├── Adapter/
│   ├── Contract/
│   ├── Controller/
│   ├── EventSystem/
│   ├── GraphQL/
│   ├── Middleware/
│   ├── Model/
│   ├── Order/
│   ├── Repository/
│   ├── Service/
│   ├── Traits/
│   ├── Transaction/
│   └── Webhook/
└── tests/
    ├── Unit/
    └── Integration/
```

---

## Implementation Steps

### Step 1: Create Package Directory Structure
```bash
mkdir -p /home/dtkachev/osc/strpwt7-nov26/source/extensions/payment-component
mkdir -p /home/dtkachev/osc/strpwt7-nov26/source/extensions/payment-component/src
mkdir -p /home/dtkachev/osc/strpwt7-nov26/source/extensions/payment-component/tests/Unit
mkdir -p /home/dtkachev/osc/strpwt7-nov26/source/extensions/payment-component/tests/Integration
```

### Step 2: Create composer.json
```json
{
    "name": "oxid-esales/payment-component",
    "description": "Provider-agnostic payment component with smart-contract architecture",
    "type": "library",
    "license": "GPL-3.0-only",
    "authors": [
        {
            "name": "OXID eSales AG",
            "email": "info@oxid-esales.com"
        }
    ],
    "require": {
        "php": "^8.1",
        "psr/log": "^2.0 || ^3.0",
        "psr/event-dispatcher": "^1.0",
        "doctrine/dbal": "^2.13 || ^3.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^1.10"
    },
    "autoload": {
        "psr-4": {
            "OxidEsales\\PaymentComponent\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "OxidEsales\\PaymentComponent\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable"
}
```

### Step 3: Copy Source Files
```bash
cp -r extensions/stripe/src/Component/* extensions/payment-component/src/
```

### Step 4: Copy Test Files
```bash
cp -r extensions/stripe/tests/Unit/Component/* extensions/payment-component/tests/Unit/
cp -r extensions/stripe/tests/Integration/Component/* extensions/payment-component/tests/Integration/
```

### Step 5: Update Namespaces

**Old namespace:** `OxidSolutionCatalysts\Payments\Component\`
**New namespace:** `OxidEsales\PaymentComponent\`

Update all PHP files in payment-component:
- `src/**/*.php`
- `tests/**/*.php`

**Find and replace command:**
```bash
find extensions/payment-component -name "*.php" -exec sed -i \
  's/OxidSolutionCatalysts\\Payments\\Component/OxidEsales\\PaymentComponent/g' {} \;
```

### Step 6: Create phpunit.xml
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

### Step 7: Create phpstan.neon
```neon
parameters:
    level: 6
    paths:
        - src
    excludePaths:
        - tests
```

### Step 8: Update Stripe Module
- Add dependency to `oxid-esales/payment-component` in stripe's composer.json
- Update imports in all Stripe files to use new namespace
- Remove `src/Component` directory from stripe module

### Step 9: Run Tests
```bash
cd extensions/payment-component
composer install
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpstan analyse
```

---

## Namespace Migration Map

| Old Namespace | New Namespace |
|---------------|---------------|
| `OxidSolutionCatalysts\Payments\Component\Adapter\` | `OxidEsales\PaymentComponent\Adapter\` |
| `OxidSolutionCatalysts\Payments\Component\Contract\` | `OxidEsales\PaymentComponent\Contract\` |
| `OxidSolutionCatalysts\Payments\Component\Controller\` | `OxidEsales\PaymentComponent\Controller\` |
| `OxidSolutionCatalysts\Payments\Component\EventSystem\` | `OxidEsales\PaymentComponent\EventSystem\` |
| `OxidSolutionCatalysts\Payments\Component\Repository\` | `OxidEsales\PaymentComponent\Repository\` |
| `OxidSolutionCatalysts\Payments\Component\Service\` | `OxidEsales\PaymentComponent\Service\` |
| `OxidSolutionCatalysts\Payments\Component\Model\` | `OxidEsales\PaymentComponent\Model\` |
| `OxidSolutionCatalysts\Payments\Component\Webhook\` | `OxidEsales\PaymentComponent\Webhook\` |
| `OxidSolutionCatalysts\Payments\Component\Transaction\` | `OxidEsales\PaymentComponent\Transaction\` |

---

## Files to Update in Stripe Module

After extraction, update imports in:
- `src/Stripe/**/*.php` - All Stripe-specific implementations
- `services.yaml` - Service definitions
- `tests/**/*.php` - All test files (excluding Component tests which are moved)

### Step 10: Update Stripe Module Imports

**Find and replace in stripe module:**
```bash
find extensions/stripe/src/Stripe -name "*.php" -exec sed -i \
  's/OxidSolutionCatalysts\\Payments\\Component/OxidEsales\\PaymentComponent/g' {} \;

find extensions/stripe/tests -name "*.php" -exec sed -i \
  's/OxidSolutionCatalysts\\Payments\\Component/OxidEsales\\PaymentComponent/g' {} \;

sed -i 's/OxidSolutionCatalysts\\Payments\\Component/OxidEsales\\PaymentComponent/g' \
  extensions/stripe/services.yaml
```

### Step 11: Update Stripe composer.json Autoload

Remove Component from stripe's autoload:
```json
{
  "autoload": {
    "psr-4": {
      "OxidSolutionCatalysts\\Payments\\Stripe\\": "./src/Stripe",
      "OxidSolutionCatalysts\\Payments\\Migrations\\": "./migration/data"
    }
  }
}
```

### Step 12: Remove Component from Stripe

```bash
rm -rf extensions/stripe/src/Component
rm -rf extensions/stripe/tests/Unit/Component
rm -rf extensions/stripe/tests/Integration/Component
```

---

## Acceptance Criteria

1. [ ] New package created at `extensions/payment-component`
2. [ ] All Component source files moved with correct namespaces
3. [ ] All Component tests moved and passing
4. [ ] PHPStan passes at level 6
5. [ ] Stripe module updated to use new package
6. [ ] Stripe module tests still passing
7. [ ] No duplicate code between packages

---

## Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| Namespace conflicts | Use distinct namespace `PaymentComponent` |
| Circular dependencies | Component has no dependencies on Stripe |
| Test failures | Run tests after each step |
| Missing files | Use `git status` to verify all files moved |

---

## Services Configuration

### Does payment-component need services.yaml?

**No.** The payment-component is a pure library and should NOT have services.yaml because:

1. **Library vs Module**: It's a composer library, not an OXID module
2. **Interface-based**: Component provides interfaces; consumers wire implementations
3. **Flexibility**: Different shops may wire services differently

### Service Wiring Strategy

The **stripe module** will wire Component services in its `services.yaml`:

```yaml
# Stripe module's services.yaml wires Component interfaces to implementations:

# Repository - Doctrine implementation stays in payment-component
OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface:
  class: OxidEsales\PaymentComponent\Repository\DoctrineContractRepository
  arguments:
    $connection: '@doctrine.dbal.default_connection'

# Adapters - OXID-specific implementations stay in stripe module
OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface:
  class: OxidSolutionCatalysts\Payments\Stripe\Adapter\OxidShopAdapter

OxidEsales\PaymentComponent\Adapter\ShopOrderServiceInterface:
  class: OxidSolutionCatalysts\Payments\Stripe\Adapter\OxidShopOrderService
```

### Stripe composer.json Updated

Added dependency and local repository:
```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../payment-component",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "oxid-esales/payment-component": "^1.0"
  }
}
```

---

## Estimated Effort

- Directory setup: 5 min
- File copy: 5 min
- Namespace updates: 30 min (automated with sed/find-replace)
- Test fixes: 30 min
- Stripe module updates: 60 min
- Verification: 30 min

**Total:** ~2.5 hours

---

## Notes

- The Component code is already provider-agnostic by design
- No OXID-specific code should be in the Component (except adapters)
- The package can be used by any payment provider module (Stripe, PayPal, etc.)
