# Sprint 22: Resolve ContainerFactory Usage

**Date:** 2025-12-09
**Priority:** MEDIUM
**Status:** PENDING
**Branch:** TBD (b-7.4.x-STRP-XX)
**Est. Effort:** 3 hours
**Depends On:** Sprint 21 (DI cleanup)

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Write tests for proper DI first |
| **SOLID-DIP** | All dependencies via constructor injection |
| **DI** | No service locator pattern |
| **Clean Code** | Explicit dependencies, no hidden coupling |
| **Containerization** | All tests via `docker compose exec` |

---

## Problem Statement

**Multiple handlers use ContainerFactory for lazy service retrieval:**

| File | Lines | Pattern |
|------|-------|---------|
| `PaymentAuthorizedEventHandler.php` | 37-41 | `ContainerFactory::getInstance()->get(...)` |
| `StripeOrderCreationHandler.php` | 45-50 | `ContainerFactory::getInstance()->get(...)` |
| `StripeCheckoutReturnHandler.php` | 50-57 | `ContainerFactory::getInstance()->get(...)` |
| `OrderCreationHandler.php` | Various | `ContainerFactory::getInstance()->get(...)` |

**Impact:**
1. **Service locator anti-pattern** - Hidden dependencies
2. **Indicates circular dependency** - DI container can't resolve
3. **Breaks testability** - Can't easily mock resolved services
4. **Implicit coupling** - Dependencies not visible in constructor

---

## Root Cause Analysis

ContainerFactory usage typically indicates:
1. **Circular dependencies** - A → B → A
2. **Missing interface** - Handler depends on concrete class
3. **Lazy loading workaround** - Service too expensive to create

**Common circular dependency patterns:**
```
Handler → Service → Handler (via event dispatch)
Handler → Repository → Service → Handler
```

---

## Solution Design

### Step 1: Identify All ContainerFactory Usage

```bash
grep -rn "ContainerFactory" src/
```

### Step 2: For Each Usage, Determine Root Cause

| Handler | Service Retrieved | Root Cause Analysis |
|---------|------------------|---------------------|
| `PaymentAuthorizedEventHandler` | TBD | Investigate |
| `StripeOrderCreationHandler` | TBD | Investigate |
| `StripeCheckoutReturnHandler` | TBD | Investigate |
| `OrderCreationHandler` | TBD | Investigate |

### Step 3: Apply Appropriate Solution

#### Solution A: Break Circular Dependency with Interface

```php
// BEFORE (circular):
// Handler → Service → Handler
class HandlerA {
    public function __construct(ServiceB $service) {}
}
class ServiceB {
    public function __construct(HandlerA $handler) {} // Circular!
}

// AFTER (broken with interface):
interface HandlerInterface {}
class HandlerA implements HandlerInterface {
    public function __construct(ServiceB $service) {}
}
class ServiceB {
    public function __construct(HandlerInterface $handler) {} // OK
}
```

#### Solution B: Use Event Dispatch Instead of Direct Call

```php
// BEFORE (circular via callback):
class Handler {
    public function __construct(Service $service) {}
}
class Service {
    public function doWork(Handler $handler) {
        $handler->handle($result);
    }
}

// AFTER (via events):
class Handler {
    public function __construct(
        EventDispatcherInterface $dispatcher
    ) {}
    public function handle(): void {
        // ... work
        $this->dispatcher->dispatch(new ResultEvent($result));
    }
}
class Service {
    public function doWork(): void {
        // Just dispatch event, handler listens
    }
}
```

#### Solution C: Split Service into Multiple Smaller Services

```php
// BEFORE (large service with circular):
class BigService {
    public function __construct(
        Handler $handler,
        Repository $repo
    ) {}
}

// AFTER (split):
class ServiceA {
    public function __construct(Repository $repo) {}
}
class ServiceB {
    public function __construct(ServiceA $serviceA) {}
}
class Handler {
    public function __construct(ServiceB $serviceB) {}
}
```

### Phase 1: Analyze PaymentAuthorizedEventHandler

**File:** `src/Component/EventSystem/Handler/PaymentAuthorizedEventHandler.php`

```php
// Current (problematic):
private function getContractService(): ContractServiceInterface
{
    return ContainerFactory::getInstance()
        ->get(ContractServiceInterface::class);
}

// Solution: Inject via constructor
public function __construct(
    private readonly ContractServiceInterface $contractService
) {
}
```

**services.yaml fix:**
```yaml
OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\PaymentAuthorizedEventHandler:
    arguments:
        - '@OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface'
    tags:
        - { name: 'kernel.event_listener', event: 'payment.authorized' }
```

### Phase 2: Analyze StripeOrderCreationHandler

**File:** `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php`

Similar pattern - identify which service is retrieved and why it couldn't be injected.

### Phase 3: Analyze StripeCheckoutReturnHandler

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

Similar pattern - may involve session service or checkout service.

### Phase 4: Analyze OrderCreationHandler

**File:** `src/Component/EventSystem/Handler/OrderCreationHandler.php`

This handler has the test class import issue (Sprint 15) and ContainerFactory usage.

---

## Implementation Steps

### Step 1: Audit All ContainerFactory Usage

```bash
# Find all usages
grep -rn "ContainerFactory" src/ > containerFactory_audit.txt

# Count occurrences
grep -c "ContainerFactory" src/**/**.php
```

### Step 2: For Each File, Identify Retrieved Services

For each ContainerFactory usage:
1. What service is being retrieved?
2. Why wasn't it injected in constructor?
3. Is there a circular dependency?

### Step 3: Create Dependency Graph

```
Handler A
    ├── ServiceA
    │   └── ServiceB
    │       └── Handler A (CIRCULAR!)
    └── Repository
```

### Step 4: Apply Solutions One by One

For each circular dependency:
1. Write test showing proper DI
2. Introduce interface if needed
3. Update services.yaml
4. Remove ContainerFactory call
5. Run tests

### Step 5: Quality Checks

```bash
# PHPStan
composer phpstan

# Verify no ContainerFactory in handlers
grep -rn "ContainerFactory" src/*/EventSystem/Handler/
# Should return: nothing

# Run all tests
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"
```

---

## Files to Modify

| File | Change |
|------|--------|
| `PaymentAuthorizedEventHandler.php` | Constructor injection |
| `StripeOrderCreationHandler.php` | Constructor injection |
| `StripeCheckoutReturnHandler.php` | Constructor injection |
| `OrderCreationHandler.php` | Constructor injection |
| `services.yaml` | Update service definitions |
| Various interfaces | May need new interfaces to break cycles |

---

## Verification Checklist

- [ ] No ContainerFactory in handlers
- [ ] All dependencies via constructor
- [ ] services.yaml has all dependencies
- [ ] No circular dependency warnings
- [ ] All unit tests pass
- [ ] E2E flow works

### Verification Commands

```bash
# Verify no ContainerFactory in handlers
grep -rn "ContainerFactory" src/*/EventSystem/Handler/
grep -rn "ContainerFactory" src/Component/EventSystem/Handler/
# Should return: nothing

# Verify services compile
docker compose exec -T php bin/oe-console cache:clear
# Should show no circular dependency errors
```

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking handler initialization | High | Test each handler after change |
| Circular dependency still exists | Medium | Carefully analyze dependency graph |
| Performance impact | Low | Lazy loading preserved via DI proxy |

---

## Success Criteria

1. ✅ No ContainerFactory usage in handlers
2. ✅ All dependencies via constructor injection
3. ✅ No circular dependency warnings
4. ✅ All unit tests pass
5. ✅ E2E flow works

---

## Related Issues

- CODE_REVIEW.md Section 1.5 (HIGH: ContainerFactory Anti-Pattern)
- CODE_REVIEW.md Section 4.7 (MEDIUM: ContainerFactory Access Pattern)

---

**Last Updated:** 2025-12-09
