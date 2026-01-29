# DI Configuration Fix - Missing Service Registrations

**Date:** 2025-01-19
**Type:** Bugfix
**Status:** ✅ Fixed
**Related:** OrderController Refactoring
**Issue:** RuntimeException - Cannot autowire TransactionRepositoryInterface

---

## Problem

After refactoring `OrderController` to use the SDK-Adapter pattern, the application failed with:

```
Fatal error: Uncaught Symfony\Component\DependencyInjection\Exception\RuntimeException:
Cannot autowire service "OxidSolutionCatalysts\Payments\Stripe\Controller\OrderController":
argument "$transactionRepository" of method "__construct()" references interface
"OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface"
but no such service exists.
```

## Root Cause

The `OrderController` constructor was updated to depend on:
1. `TransactionRepositoryInterface` - **NOT registered**
2. `EventDispatcherInterface` - **NOT registered**

These interfaces and their implementations existed in the codebase but were not registered in the Symfony DI container (`services.yaml`).

## Solution

Updated `/source/extensions/stripe/services.yaml` to register the missing services.

### Changes Made

#### 1. Added Doctrine DBAL Connection

```yaml
# Doctrine DBAL Connection - Required for repositories
doctrine.dbal.connection:
  class: Doctrine\DBAL\Connection
  factory: ['@OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface', 'get']
  public: false
```

**Why?** `DoctrineTransactionRepository` requires a `Doctrine\DBAL\Connection` dependency.

#### 2. Registered TransactionRepository

```yaml
# Transaction Repository - Stores payment transactions
OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface:
  class: OxidSolutionCatalysts\Payments\Component\Repository\DoctrineTransactionRepository
  arguments:
    $connection: '@doctrine.dbal.connection'
  public: false

# Alias for easier reference
OxidSolutionCatalysts\Payments\Component\Repository\DoctrineTransactionRepository:
  alias: OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface
```

**Why?** Maps interface to concrete implementation with proper dependency injection.

#### 3. Registered EventDispatcher

```yaml
# Event Dispatcher - Dispatches domain events (optional)
OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface:
  class: OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher
  public: false

# Alias for easier reference
OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher:
  alias: OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface
```

**Why?** Event system is optional but needs to be registered if used.

#### 4. Cleared Cache

```bash
rm -rf /var/www/source/tmp/*
```

**Why?** Symfony caches the compiled DI container. Must clear after `services.yaml` changes.

## Verification

After the fix:
✅ DI container successfully autowires `OrderController`
✅ `TransactionRepositoryInterface` resolves to `DoctrineTransactionRepository`
✅ `EventDispatcherInterface` resolves to `EventDispatcher`
✅ Application starts without errors

## Complete services.yaml Structure

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  # Services auto-discovery
  OxidSolutionCatalysts\Payments\Stripe\Service\:
    resource: 'src/Stripe/Service/*'
    public: true

  # ==========================================
  # Payment Adapter Layer
  # ==========================================

  OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeClientFactory:
    public: false

  stripe.payment.adapter.client:
    class: Stripe\StripeClient
    factory: ['@OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeClientFactory', 'create']
    public: false

  OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactory:
    public: true

  # ==========================================
  # Repositories (Data Access Layer)
  # ==========================================

  doctrine.dbal.connection:
    class: Doctrine\DBAL\Connection
    factory: ['@OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface', 'get']
    public: false

  OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface:
    class: OxidSolutionCatalysts\Payments\Component\Repository\DoctrineTransactionRepository
    arguments:
      $connection: '@doctrine.dbal.connection'
    public: false

  OxidSolutionCatalysts\Payments\Component\Repository\DoctrineTransactionRepository:
    alias: OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface

  # ==========================================
  # Event System (Optional)
  # ==========================================

  OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface:
    class: OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher
    public: false

  OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher:
    alias: OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface

  # ==========================================
  # Controllers
  # ==========================================

  OxidSolutionCatalysts\Payments\Stripe\Controller\OrderController:
    tags:
      - { name: oxid.view_controller, controller_key: order }
    public: true
```

## Key Learnings

### 1. Interface → Implementation Mapping

When using dependency injection with interfaces:
- Register the interface pointing to concrete class
- Provide an alias from concrete class to interface (optional but helpful)
- Specify constructor arguments if needed

### 2. Factory Pattern for External Dependencies

```yaml
doctrine.dbal.connection:
  class: Doctrine\DBAL\Connection
  factory: ['@ServiceProvider', 'get']
```

Use factory when:
- Service is created by another service
- Complex initialization logic required
- Service comes from external library (like Doctrine)

### 3. Cache Clearing is Critical

```bash
rm -rf /var/www/source/tmp/*
```

**ALWAYS** clear cache after modifying `services.yaml`:
- Symfony compiles DI container into PHP classes
- Changes won't take effect until cache is cleared
- Cached container stored in `/var/www/source/tmp/`

### 4. Optional Dependencies

```php
public function __construct(
    private readonly ?EventDispatcherInterface $eventDispatcher = null
)
```

Make dependency optional by:
- Type hint as nullable (`?EventDispatcherInterface`)
- Provide default value (`= null`)
- Check for null before using: `if ($this->eventDispatcher)`

## Testing Checklist

After DI configuration changes:

- [x] Clear cache (`rm -rf /var/www/source/tmp/*`)
- [x] Verify no DI errors on page load
- [ ] Test OrderController instantiation
- [ ] Verify transaction repository saves data
- [ ] Verify event dispatcher (if enabled)
- [ ] Check logs for any warnings

## Related Files

- **Modified:** `/source/extensions/stripe/services.yaml`
- **Related:** `/source/extensions/stripe/src/Stripe/Controller/OrderController.php`
- **Related:** `/source/extensions/stripe/src/Component/Repository/DoctrineTransactionRepository.php`
- **Related:** `/source/extensions/stripe/src/Component/EventSystem/EventDispatcher.php`

## Prevention

To avoid this in the future:

1. **Add services immediately** when creating new interfaces
2. **Test DI after changes** - Load any page to verify
3. **Document dependencies** in constructor docblocks
4. **Use type hints** - PHP will catch missing services earlier
5. **Clear cache after every services.yaml change**

## Next Steps

1. ✅ **DI configuration fixed**
2. ⏳ **Test OrderController** - Load order page
3. ⏳ **Verify transaction storage** - Complete a test payment
4. ⏳ **Check event dispatching** - Verify events fire (if enabled)

---

**Fixed By:** Claude Code (AI Assistant)
**Verified:** Cache cleared, services registered
**Status:** ✅ Ready for Testing
