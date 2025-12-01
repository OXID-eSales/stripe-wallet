# Stripe Checkout Return Flow Fix

**Date:** 2025-12-01
**Status:** Implemented
**Issue:** After successful Stripe checkout, user lands on payment selection page instead of thank you page

## Problem Description

When returning from Stripe Checkout:
1. User completes payment on Stripe hosted page
2. Stripe redirects user back to shop (`checkoutSuccess` action)
3. **Expected:** User lands on thank you page with order created
4. **Actual:** User lands on payment selection page (PaymentController::render())

## Root Cause Analysis

### Flow Analysis

The `StripeOrderController::checkoutSuccess()` method at `src/Stripe/Controller/StripeOrderController.php:47`:

```php
public function checkoutSuccess(): string
{
    $sessionId = $this->getCheckoutSessionIdFromRequest();
    // ...
    $event = new StripeCheckoutReturnEvent($context);
    $this->getEventDispatcher()->dispatch($event);
    // ...
    return $context->get('redirectTarget') ?? 'payment';  // <-- defaults to 'payment'
}
```

The problem: `redirectTarget` is never set because **no handler processes the event**.

### Root Cause: Missing Handler Registrations

In `services.yaml`, only these handlers were registered:

```yaml
# Previously registered handlers (checkout initiation only):
OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeContractCreationHandler:
  tags:
    - { name: payment.event_handler, priority: 100 }

OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutSessionHandler:
  tags:
    - { name: payment.event_handler, priority: 0 }
```

**Missing registrations:**
1. `StripeCheckoutReturnHandler` - handles `StripeCheckoutReturnEvent`
2. Handler for `PaymentAuthorizedEvent` - transitions contract state
3. Order creation handler - creates OXID order from contract

### Expected Event Chain

When user returns from Stripe, the following chain should execute:

```
StripeCheckoutReturnEvent
    ↓
StripeCheckoutReturnHandler (priority 100)
    - Retrieves Checkout Session from Stripe API
    - Verifies payment_status === 'paid'
    - Loads contract from session metadata
    - Dispatches PaymentAuthorizedEvent
    ↓
PaymentAuthorizedEvent
    ↓
PaymentAuthorizedEventHandler (priority 90) [NEW]
    - Transitions contract from DRAFT to PENDING
    - Marks 'payment_authorized' condition as fulfilled
    - If all conditions met → contract auto-transitions to READY_TO_COMMIT
    - Dispatches ContractReadyToCommitEvent
    ↓
ContractReadyToCommitEvent
    ↓
StripeOrderCreationHandler (priority 80) [NEW]
    - Creates order via OxidShopOrderService (OXID's Order::finalizeOrder())
    - Sets orderId in context
    - Transitions contract to COMMITTED
    ↓
Back to StripeCheckoutReturnHandler
    - Checks if orderId is set
    - Sets redirectTarget = 'thankyou'
    ↓
Back to checkoutSuccess()
    - Returns 'thankyou' → user redirected to thank you page
```

## Implementation (Option A: Full Smart-Contract Flow)

### Files Created

1. **`src/Component/EventSystem/Handler/PaymentAuthorizedEventHandler.php`**
   - Listens for `PaymentAuthorizedEvent`
   - Transitions contract from DRAFT → PENDING
   - Fulfills `payment_authorized` condition
   - Dispatches `ContractReadyToCommitEvent` if all conditions met

2. **`src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php`**
   - Listens for `ContractReadyToCommitEvent`
   - Uses `OxidShopOrderService` for proper OXID integration
   - Creates order via OXID's standard `Order::finalizeOrder()` flow
   - Sets `orderId` in context

### Files Modified

**`services.yaml`** - Added handler registrations:

```yaml
# ==========================================
# Stripe Checkout Return Handlers
# ==========================================
# These handlers process the return flow when user comes back from Stripe.
# Event chain: StripeCheckoutReturnEvent → PaymentAuthorizedEvent → ContractReadyToCommitEvent

# Stripe Checkout Return Handler - Handles return from Stripe Checkout page
# Priority 100: Entry point for checkout return flow
OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler:
  arguments:
    $contractRepository: '@OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface'
    $adapterFactory: '@OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface'
    $eventDispatcher: '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
  tags:
    - { name: payment.event_handler, priority: 100 }
  public: false

# Payment Authorized Event Handler - Processes PaymentAuthorizedEvent
# Priority 90: Runs after StripeCheckoutReturnHandler
OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\PaymentAuthorizedEventHandler:
  arguments:
    $contractRepository: '@OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface'
    $eventDispatcher: '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
  tags:
    - { name: payment.event_handler, priority: 90 }
  public: false

# Stripe Order Creation Handler - Creates OXID order from contract
# Priority 80: Runs after PaymentAuthorizedEventHandler
OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeOrderCreationHandler:
  arguments:
    $contractRepository: '@OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface'
    $shopOrderService: '@OxidSolutionCatalysts\Payments\Component\Adapter\ShopOrderServiceInterface'
    $eventDispatcher: '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
  tags:
    - { name: payment.event_handler, priority: 80 }
  public: false
```

## Handler Priority Chain

| Priority | Handler | Event | Action |
|----------|---------|-------|--------|
| 100 | StripeCheckoutReturnHandler | StripeCheckoutReturnEvent | Verify payment, dispatch PaymentAuthorizedEvent |
| 90 | PaymentAuthorizedEventHandler | PaymentAuthorizedEvent | Fulfill conditions, dispatch ContractReadyToCommitEvent |
| 80 | StripeOrderCreationHandler | ContractReadyToCommitEvent | Create OXID order, set orderId |

## Testing

### Pre-commit Checks
```bash
./bin/pre-commit-check.sh
# Result: ALL CHECKS PASSED (869 tests, 1882 assertions)
```

### PHP Syntax Validation
```bash
docker compose exec -T php php -l /var/www/extensions/stripe/src/Component/EventSystem/Handler/PaymentAuthorizedEventHandler.php
# No syntax errors detected

docker compose exec -T php php -l /var/www/extensions/stripe/src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php
# No syntax errors detected
```

### Manual Testing Steps
1. Clear browser session and OXID cache
2. Add products to cart
3. Proceed to checkout
4. Select Stripe payment method
5. Complete payment on Stripe (use test card 4242 4242 4242 4242)
6. **Expected:** Redirect to thank you page
7. **Verify:** Order exists in admin panel

## Architecture Notes

### Why Not Use Component-Level OrderCreationHandler?

The existing `Component\EventSystem\Handler\OrderCreationHandler` imports a test stub `Order` class:
```php
use OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler\Support\Order;
```

This is designed for unit testing, not production use. The new `StripeOrderCreationHandler` uses `OxidShopOrderService` which properly integrates with OXID's order processing pipeline.

### Contract State Machine

The contract follows this state machine:
```
DRAFT → transitionToPending() → PENDING
PENDING → fulfillCondition() (all conditions) → READY_TO_COMMIT
READY_TO_COMMIT → commitToOrder() → COMMITTED
COMMITTED → fulfill() → FULFILLED
```

### Key Design Decisions

1. **Using PaymentAuthorizedEventHandler instead of PaymentAuthorizationHandler**
   - `PaymentAuthorizationHandler` listens for `ContractTransitionedToPendingEvent`
   - But `StripeCheckoutReturnHandler` dispatches `PaymentAuthorizedEvent`
   - Created new `PaymentAuthorizedEventHandler` to bridge this gap

2. **Stripe-specific order creation handler**
   - Uses OXID's `OxidShopOrderService` for proper integration
   - Gets basket from session (not from contract snapshot)
   - Supports all OXID order features (emails, stock, etc.)

## Next Steps

1. [ ] Manual end-to-end testing
2. [ ] Create unit tests for new handlers
3. [ ] Test error scenarios (payment failed, expired session, etc.)
4. [ ] Document webhook integration for async payment confirmation
