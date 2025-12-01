# Circular Dependency Fix - Event Handler Registration

**Date:** 2025-12-01
**Status:** Resolved
**Issue:** Infinite recursion error when loading Stripe checkout page

## Problem Description

When clicking the Stripe checkout button, the application crashed with:
```
Error: Maximum call stack size of 8339456 bytes reached. Infinite recursion?
```

The error occurred during DI container initialization when trying to create checkout session.

## Root Cause Analysis

### Stack Trace Analysis

The log showed a repeating pattern:
```
#29195 container_cache_shop_1.php(1090): getStripeCheckoutReturnHandlerService()
#29196 EventListenerProvider.php(30): __construct(RewindableGenerator)
#29197 container_cache_shop_1.php(1088): getEventDispatcherInterfaceService()
#29198 container_cache_shop_1.php(2201): getStripeCheckoutReturnHandlerService()
... (repeating infinitely)
```

### Circular Dependency Chain

```
EventListenerProvider
    ↓ needs tagged handlers
StripeCheckoutReturnHandler (tagged: payment.event_handler)
    ↓ constructor argument
EventDispatcherInterface
    ↓ constructor argument
EventListenerProviderInterface
    ↓ needs tagged handlers (LOOP!)
```

When Symfony DI tried to create `EventListenerProvider`:
1. It collected all tagged `payment.event_handler` services
2. To instantiate each handler, it resolved their dependencies
3. `StripeCheckoutReturnHandler` needed `EventDispatcherInterface`
4. `EventDispatcher` needed `EventListenerProvider`
5. But `EventListenerProvider` was still being constructed → infinite loop

## Solution: Lazy Loading

Changed handlers to **NOT** inject `EventDispatcher` via constructor. Instead, they fetch it lazily at runtime when `handle()` is called.

### Before (Circular Dependency)

```php
// Handler constructor - causes circular dependency
class StripeCheckoutReturnHandler implements HandlerInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private StripeAdapterFactoryInterface $adapterFactory,
        private EventDispatcherInterface $eventDispatcher  // ← PROBLEM!
    ) {}

    public function handle(object $event): void
    {
        // ...
        $this->eventDispatcher->dispatch($paymentAuthorizedEvent);
    }
}
```

```yaml
# services.yaml - injecting dispatcher causes loop
OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler:
  arguments:
    $eventDispatcher: '@...EventDispatcherInterface'  # ← PROBLEM!
  tags:
    - { name: payment.event_handler }
```

### After (Lazy Loading)

```php
// Handler with lazy loading - no circular dependency
class StripeCheckoutReturnHandler implements HandlerInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private StripeAdapterFactoryInterface $adapterFactory
        // NO EventDispatcher here!
    ) {}

    protected function getEventDispatcher(): EventDispatcherInterface
    {
        // Lazy loading - only called during handle(), not construction
        return ContainerFactory::getInstance()
            ->getContainer()
            ->get(EventDispatcherInterface::class);
    }

    public function handle(object $event): void
    {
        // ...
        $this->getEventDispatcher()->dispatch($paymentAuthorizedEvent);
    }
}
```

```yaml
# services.yaml - no dispatcher argument
OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler:
  arguments:
    $contractRepository: '@...ContractRepositoryInterface'
    $adapterFactory: '@...StripeAdapterFactoryInterface'
    # NO $eventDispatcher!
  tags:
    - { name: payment.event_handler }
```

## Files Modified

### Handlers Updated

1. **`src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`**
   - Removed `$eventDispatcher` constructor parameter
   - Added `protected getEventDispatcher()` method with lazy loading
   - Changed `$this->eventDispatcher->dispatch()` to `$this->getEventDispatcher()->dispatch()`

2. **`src/Component/EventSystem/Handler/PaymentAuthorizedEventHandler.php`**
   - Same pattern: removed constructor injection, added lazy loading method

3. **`src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php`**
   - Same pattern: removed constructor injection, added lazy loading method

### Services Configuration Updated

**`services.yaml`** - Removed `$eventDispatcher` arguments from all three handlers:
```yaml
# NOTE: EventDispatcher is fetched lazily to avoid circular dependency
OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler:
  arguments:
    $contractRepository: '@...ContractRepositoryInterface'
    $adapterFactory: '@...StripeAdapterFactoryInterface'
    # $eventDispatcher removed!
  tags:
    - { name: payment.event_handler, priority: 100 }
```

### Tests Updated

**`tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php`**
- Created `TestableStripeCheckoutReturnHandler` subclass
- Overrides `getEventDispatcher()` to return mock for testing
- All 10 tests in the file continue to pass

## Why Lazy Loading Works

The key insight is **when** the dependency is resolved:

| Approach | Resolution Time | Result |
|----------|-----------------|--------|
| Constructor Injection | Container build time | Circular dependency |
| Lazy Loading | Runtime (during `handle()`) | Works fine |

During container initialization:
- `EventListenerProvider` is created
- Tagged handlers are instantiated (without needing EventDispatcher)
- `EventDispatcher` is created with fully-built `EventListenerProvider`
- Later, when `handle()` is called, handlers can safely get the dispatcher

## Testing

### Unit Tests
```bash
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --testsuite Unit \
  --bootstrap=/var/www/source/bootstrap.php

# Result: 869 tests, 1882 assertions - ALL PASS
```

### Manual Testing
1. Clear cache: `docker compose exec -T php php /var/www/bin/oe-console oe:cache:clear`
2. Navigate to shop checkout
3. Click Stripe payment button
4. **Expected:** Redirects to Stripe checkout page (no recursion error)

## Alternative Solutions Considered

1. **Symfony Lazy Services** (`lazy: true`) - More complex configuration
2. **Service Locator Pattern** - What we implemented, simpler
3. **Restructuring Event System** - Too invasive for this fix

## Lessons Learned

1. **Avoid circular dependencies in tagged services** - When using `!tagged_iterator`, be careful about what dependencies the tagged services have
2. **Lazy loading is a valid pattern** - For breaking circular dependencies without restructuring
3. **Test both unit and integration** - Unit tests passed before fix, but integration (actual container) failed

## Related Files

- `src/Component/EventSystem/EventListenerProvider.php` - Collects tagged handlers
- `src/Component/EventSystem/EventDispatcher.php` - Dispatches events to handlers
- `services.yaml` - DI configuration with tagged services
