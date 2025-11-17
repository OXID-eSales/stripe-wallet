# OXID Compatibility & Standard Methods

**Ensuring Full Compatibility with OXID Core and Other Modules**
**Version:** 1.0.0
**Date:** 2025-11-13

---

## Overview

This document explains how the event-driven payment architecture integrates with OXID's standard order creation flow using the `Order::finalizeOrder()` method. This ensures **full compatibility** with other OXID modules.

**Critical Requirement:** We MUST use `Order::finalizeOrder()` to allow other modules to hook into the order creation process.

---

## Why finalizeOrder() is Critical

### OXID's Standard Order Creation Flow

```php
// OXID Core Standard Method
class Order extends BaseModel
{
    /**
     * Standard OXID order creation method
     * ALL modules hook into this method
     *
     * @param Basket $basket
     * @param User $user
     * @param bool $recalculateBasket
     * @return int Order state constant
     */
    public function finalizeOrder(
        Basket $basket,
        $user,
        $recalculateBasket = false
    ): int
    {
        // 1. Validate basket
        // 2. Check stock
        // 3. Reserve stock
        // 4. Create order record
        // 5. Trigger events (beforeFinalizeOrder, afterOrderFinalize)
        // 6. Clear basket
        // 7. Send emails
        // 8. Execute payment
        // 9. Return order state (ORDER_STATE_OK, etc.)
    }
}
```

### Other Modules Hook Into This

**Example modules that depend on `finalizeOrder()`:**

```php
// ERP Integration Module
class ErpOrderExtension extends Order
{
    public function finalizeOrder($basket, $user, $recalculateBasket = false)
    {
        $result = parent::finalizeOrder($basket, $user, $recalculateBasket);

        // Send order to ERP system
        if ($result === self::ORDER_STATE_OK) {
            $this->sendToErp();
        }

        return $result;
    }
}

// Custom Email Module
class CustomEmailOrder extends Order
{
    public function finalizeOrder($basket, $user, $recalculateBasket = false)
    {
        $result = parent::finalizeOrder($basket, $user, $recalculateBasket);

        // Send custom notifications
        $this->sendCustomEmail();

        return $result;
    }
}

// Inventory Management Module
class InventoryOrder extends Order
{
    public function finalizeOrder($basket, $user, $recalculateBasket = false)
    {
        $result = parent::finalizeOrder($basket, $user, $recalculateBasket);

        // Update external inventory system
        $this->syncInventory();

        return $result;
    }
}
```

**If we bypass `finalizeOrder()`, ALL these modules break!** ❌

---

## Our Event-Driven Architecture USES finalizeOrder()

### ✅ We DO Use the Standard Method

**The event-driven architecture wraps AROUND `finalizeOrder()`, not replaces it:**

```
Event-Driven Flow:
┌─────────────────────────────────────────────────────────┐
│  PaymentInitiatedEvent                                  │
│  (User clicks "Place Order")                            │
└────────────────┬────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────┐
│  PaymentInitiatedEventHandler                           │
│  - Creates Stripe PaymentIntent                         │
│  - Stores transaction record                            │
│  - Returns client_secret                                │
└────────────────┬────────────────────────────────────────┘
                 │
                 ▼
         [User completes payment in browser]
                 │
                 ▼
┌─────────────────────────────────────────────────────────┐
│  PaymentConfirmedEvent                                  │
│  (Payment confirmed by Stripe)                          │
└────────────────┬────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────┐
│  PaymentConfirmedEventHandler                           │
│  ┌───────────────────────────────────────────────────┐ │
│  │  CALLS STANDARD OXID METHOD:                      │ │
│  │                                                    │ │
│  │  $order = oxNew(Order::class);                    │ │
│  │  $orderState = $order->finalizeOrder(             │ │
│  │      $basket,                                      │ │
│  │      $user                                         │ │
│  │  );                                                │ │
│  │                                                    │ │
│  │  ✅ ALL OTHER MODULES RUN HERE!                   │ │
│  └───────────────────────────────────────────────────┘ │
└────────────────┬────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────┐
│  OrderCreatedEvent                                      │
│  (Order successfully created)                           │
│  - Triggers email, analytics, etc.                      │
└─────────────────────────────────────────────────────────┘
```

**Key Point:** Events happen BEFORE and AFTER `finalizeOrder()`, but the actual order creation uses the standard OXID method.

---

## Complete Implementation

### OrderController - Uses finalizeOrder()

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\OrderController as CoreOrderController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Application\Model\Order;
use OxidSolutionCatalysts\Stripe\Service\StripePaymentService;

class OrderController extends CoreOrderController
{
    private StripePaymentService $paymentService;

    public function init(): void
    {
        parent::init();
        $this->paymentService = Registry::get(StripePaymentService::class);
    }

    /**
     * Execute order - uses OXID's standard finalizeOrder()
     */
    public function execute()
    {
        // Check if Stripe payment
        if (!$this->isStripePayment()) {
            // Standard OXID flow for other payment methods
            return parent::execute();
        }

        // Stripe-specific flow (still uses finalizeOrder!)
        return $this->executeStripePayment();
    }

    /**
     * Execute Stripe payment
     * ✅ USES STANDARD finalizeOrder() METHOD
     */
    private function executeStripePayment(): string
    {
        $session = Registry::getSession();
        $basket = $session->getBasket();
        $user = $basket->getBasketUser();

        // Get PaymentIntent ID
        $paymentIntentId = Registry::getRequest()->getRequestParameter('payment_intent_id');

        if (!$paymentIntentId) {
            $paymentIntentId = $session->getVariable('stripe_payment_intent_id');
        }

        if (!$paymentIntentId) {
            Registry::getUtilsView()->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        try {
            // 1. Verify payment with Stripe
            $paymentIntent = $this->paymentService->getPaymentIntent($paymentIntentId);

            if ($paymentIntent['status'] !== 'succeeded') {
                throw new \RuntimeException('Payment not successful');
            }

            // 2. ✅ USE STANDARD OXID METHOD - Critical for compatibility!
            $order = oxNew(Order::class);

            // Set payment method
            $basket->setPayment('osc_stripe_card');

            // Call standard OXID order creation method
            // This is where ALL other modules hook in!
            $orderState = $order->finalizeOrder($basket, $user);

            // 3. Check if order was created successfully
            if ($orderState === Order::ORDER_STATE_OK) {

                // 4. Store Stripe transaction data
                $this->paymentService->storeTransaction($order, $paymentIntent);

                // 5. Set order ID in session for thank you page
                $session->setVariable('sess_challenge', $order->getId());

                // 6. Clear Stripe session variables
                $session->deleteVariable('stripe_payment_intent_id');
                $session->deleteVariable('stripe_client_secret');

                Registry::getLogger()->info('Stripe order created successfully', [
                    'order_id' => $order->getId(),
                    'order_number' => $order->getFieldData('oxordernr'),
                    'payment_intent_id' => $paymentIntentId,
                ]);

                // 7. Redirect to thank you page
                return 'thankyou';
            }

            // Order creation failed
            throw new \RuntimeException('Order creation failed: ' . $orderState);

        } catch (\Exception $e) {
            Registry::getLogger()->error('Stripe order creation failed', [
                'error' => $e->getMessage(),
                'payment_intent_id' => $paymentIntentId,
            ]);

            Registry::getUtilsView()->addErrorToDisplay(
                'Order could not be created. Please contact support.'
            );

            return 'payment';
        }
    }

    /**
     * Check if Stripe payment method is selected
     */
    private function isStripePayment(): bool
    {
        $paymentId = Registry::getSession()->getBasket()->getPaymentId();
        return $paymentId === 'osc_stripe_card';
    }
}
```

**Key Points:**

1. ✅ **Uses `$order->finalizeOrder($basket, $user)`**
2. ✅ **Other modules can extend Order class**
3. ✅ **OXID events fire normally (beforeFinalizeOrder, afterOrderFinalize)**
4. ✅ **Standard OXID order creation flow maintained**
5. ✅ **Payment verification happens BEFORE order creation**
6. ✅ **Transaction data stored AFTER order creation**

---

## Event-Driven Architecture WITH finalizeOrder()

### Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│  1. PaymentInitiatedEvent                                       │
│     (Controller emits event)                                    │
└────────────────┬────────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────────┐
│  2. PaymentInitiatedEventHandler                                │
│     - Creates Stripe PaymentIntent                              │
│     - Does NOT create order yet!                                │
└────────────────┬────────────────────────────────────────────────┘
                 │
                 ▼
         [User completes payment]
                 │
                 ▼
┌─────────────────────────────────────────────────────────────────┐
│  3. Controller validates payment                                │
│     - Checks PaymentIntent status = "succeeded"                 │
│     - Proceeds to order creation                                │
└────────────────┬────────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────────┐
│  4. ✅ STANDARD OXID ORDER CREATION                             │
│                                                                  │
│  $order = oxNew(Order::class);                                  │
│  $orderState = $order->finalizeOrder($basket, $user);           │
│                                                                  │
│  ┌────────────────────────────────────────────────────────┐   │
│  │  OXID CORE EXECUTES:                                   │   │
│  │  1. Validates basket                                    │   │
│  │  2. Checks stock availability                           │   │
│  │  3. Reserves stock                                      │   │
│  │  4. Creates oxorder record                              │   │
│  │  5. Creates oxorderarticles records                     │   │
│  │  6. Fires: beforeFinalizeOrder event                    │   │
│  │     ↓                                                    │   │
│  │     OTHER MODULES EXECUTE HERE                          │   │
│  │     - ERP modules send to ERP                           │   │
│  │     - Email modules send custom emails                  │   │
│  │     - Inventory modules update external systems         │   │
│  │     - Tax modules calculate special taxes               │   │
│  │     ↓                                                    │   │
│  │  7. Fires: afterOrderFinalize event                     │   │
│  │  8. Returns order state (ORDER_STATE_OK)                │   │
│  └────────────────────────────────────────────────────────┘   │
│                                                                  │
│  ✅ ALL OTHER MODULES WORK NORMALLY!                            │
└────────────────┬────────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────────┐
│  5. Store Stripe transaction data                               │
│     - Links PaymentIntent to order                              │
│     - Stores transaction record                                 │
└────────────────┬────────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────────┐
│  6. OrderCreatedEvent (optional)                                │
│     - Triggers our custom subscribers                           │
│     - Email, analytics, etc.                                    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Compatibility with Other Payment Modules

### Multiple Payment Methods Work Together

```php
// Your shop can have multiple payment modules:

// Standard OXID invoice
if ($paymentId === 'oxidinvoice') {
    return parent::execute(); // Uses finalizeOrder()
}

// PayPal module
if ($paymentId === 'paypal') {
    return parent::execute(); // Uses finalizeOrder()
}

// Stripe module (ours)
if ($paymentId === 'osc_stripe_card') {
    // Verify payment first
    $verified = $this->verifyStripePayment();

    if ($verified) {
        // Then use standard method
        $order = oxNew(Order::class);
        $order->finalizeOrder($basket, $user); // ✅ Same as others!
    }
}

// Amazon Pay module
if ($paymentId === 'amazonpay') {
    return parent::execute(); // Uses finalizeOrder()
}
```

**All payment modules use the same `finalizeOrder()` method!**

---

## Payment Service Layer

### StripePaymentService - Helper Methods

```php
<?php

namespace OxidSolutionCatalysts\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;

class StripePaymentService
{
    /**
     * Helper method: Create order with payment verification
     * ✅ USES STANDARD finalizeOrder() METHOD
     *
     * @param Basket $basket
     * @param User $user
     * @param string $paymentIntentId
     * @return Order
     * @throws \RuntimeException
     */
    public function createOrderAfterPayment(
        Basket $basket,
        User $user,
        string $paymentIntentId
    ): Order {
        // 1. Verify payment succeeded
        $paymentIntent = $this->getPaymentIntent($paymentIntentId);

        if ($paymentIntent['status'] !== 'succeeded') {
            throw new \RuntimeException(
                'Payment not successful: ' . $paymentIntent['status']
            );
        }

        // 2. Set payment method on basket
        $basket->setPayment('osc_stripe_card');

        // 3. ✅ USE STANDARD OXID METHOD
        $order = oxNew(Order::class);
        $orderState = $order->finalizeOrder($basket, $user);

        // 4. Check order creation result
        if ($orderState !== Order::ORDER_STATE_OK) {
            throw new \RuntimeException(
                'Order creation failed with state: ' . $orderState
            );
        }

        // 5. Store transaction data
        $this->storeTransaction($order, $paymentIntent);

        return $order;
    }

    /**
     * Store transaction after order creation
     *
     * @param Order $order
     * @param array $paymentIntent
     */
    public function storeTransaction(Order $order, array $paymentIntent): void
    {
        // Implementation from SERVICE_LAYER.md
        // ...
    }
}
```

---

## Testing Compatibility

### Test That Other Modules Work

```php
<?php

use PHPUnit\Framework\TestCase;

class OrderCompatibilityTest extends TestCase
{
    /**
     * Test that finalizeOrder() is called
     */
    public function testStripeOrderUsesStandardFinalizeOrder(): void
    {
        // Create mock order that tracks method calls
        $order = $this->getMockBuilder(Order::class)
            ->onlyMethods(['finalizeOrder'])
            ->getMock();

        // Assert finalizeOrder() is called exactly once
        $order->expects($this->once())
            ->method('finalizeOrder')
            ->willReturn(Order::ORDER_STATE_OK);

        // Execute Stripe payment flow
        $controller = new OrderController();
        $controller->setOrder($order); // Inject mock
        $controller->execute();
    }

    /**
     * Test that other module extensions work
     */
    public function testOtherModulesCanExtendOrder(): void
    {
        // Create custom order extension (simulating another module)
        $customOrder = new class extends Order {
            public $customMethodCalled = false;

            public function finalizeOrder($basket, $user, $recalc = false)
            {
                $result = parent::finalizeOrder($basket, $user, $recalc);
                $this->customMethodCalled = true;
                return $result;
            }
        };

        // Execute order creation
        $result = $customOrder->finalizeOrder($basket, $user);

        // Verify custom extension was executed
        $this->assertTrue($customOrder->customMethodCalled);
        $this->assertEquals(Order::ORDER_STATE_OK, $result);
    }
}
```

---

## OXID Events Still Fire

### Standard OXID Events Work Normally

```php
// Other modules can listen to OXID events:

// In another module's metadata.php:
$aModule = [
    'events' => [
        'beforeFinalizeOrder' => 'MyModule\Events::beforeOrderCreated',
        'afterOrderFinalize' => 'MyModule\Events::afterOrderCreated',
    ],
];

// These events fire when Stripe orders are created too!
class Events
{
    public static function beforeOrderCreated($params)
    {
        // This runs even for Stripe orders
        // because we use finalizeOrder()
        $basket = $params['basket'];
        $user = $params['user'];

        // Custom logic here
    }

    public static function afterOrderCreated($params)
    {
        // This runs even for Stripe orders
        $order = $params['order'];

        // Send to ERP, update inventory, etc.
    }
}
```

**✅ All OXID events work because we use the standard method!**

---

## Migration from Other Payment Modules

### Easy Migration Path

```php
// Old module (direct database writes - BAD)
class OldPaymentModule
{
    public function createOrder()
    {
        // Insert directly into database ❌
        $db->execute("INSERT INTO oxorder ...");
    }
}

// Our module (uses standard method - GOOD)
class StripeOrderController
{
    public function execute()
    {
        // Uses standard OXID method ✅
        $order = oxNew(Order::class);
        $order->finalizeOrder($basket, $user);
    }
}
```

**Switching from old module to ours:** All other modules keep working!

---

## Summary

### ✅ What We Do RIGHT

1. **Use `Order::finalizeOrder()`** - Standard OXID method
2. **Other modules work** - ERP, email, inventory, tax modules
3. **OXID events fire** - beforeFinalizeOrder, afterOrderFinalize
4. **Standard order flow** - No shortcuts, no hacks
5. **Event architecture wraps around** - Events enhance, don't replace

### ❌ What We DON'T Do (Mistakes to Avoid)

1. ❌ Direct database inserts into oxorder table
2. ❌ Bypassing `finalizeOrder()` method
3. ❌ Creating custom order creation logic
4. ❌ Breaking OXID event chain
5. ❌ Incompatible with other modules

### Architecture Summary

```
Our Event-Driven Architecture:

Events BEFORE order:
  ↓
  PaymentInitiatedEvent
  PaymentConfirmedEvent
  ↓
STANDARD OXID METHOD:
  ↓
  $order->finalizeOrder($basket, $user) ✅
  ↓
  [All other modules hook in here]
  ↓
Events AFTER order:
  ↓
  OrderCreatedEvent
  (Optional - for our custom logic)
```

**The event-driven architecture enhances OXID's standard flow, it doesn't replace it!**

---

## Conclusion

**Yes, we use the standard `finalizeOrder()` method!**

✅ **Full OXID compatibility maintained**
✅ **Other modules work normally**
✅ **Standard OXID events fire**
✅ **Event architecture is a wrapper, not a replacement**
✅ **Future-proof for OXID updates**

The event-driven architecture provides better code organization and extensibility while maintaining 100% compatibility with OXID's standard order creation process.

---

## Next Steps

1. Review [CONTROLLER_INTEGRATION.md](CONTROLLER_INTEGRATION.md) - See finalizeOrder() usage
2. Review [SERVICE_LAYER.md](SERVICE_LAYER.md) - Service helper methods
3. Review [EVENT_DRIVEN_ARCHITECTURE.md](EVENT_DRIVEN_ARCHITECTURE.md) - Event flow

