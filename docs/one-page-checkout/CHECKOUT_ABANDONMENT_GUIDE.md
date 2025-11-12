# Checkout Abandonment Tracking Guide

## Overview

This guide covers the checkout abandonment tracking system that monitors when customers leave the checkout process before completing their purchase.

## Why Track Abandonment?

**Business Impact:**
- Average cart abandonment rate: 69.80% (Baymard Institute)
- Cart recovery emails can recover 10-15% of abandoned carts
- Understanding abandonment reasons helps optimize checkout flow

**Use Cases:**
- Send cart recovery emails
- Release reserved inventory
- Track conversion funnel metrics
- A/B test checkout improvements
- Identify UX issues

## Abandonment Scenarios

### 1. Timeout (Inactivity)
Customer stops interacting with checkout for 15+ minutes.

**Triggers:**
- No mouse movement
- No keyboard input
- No scrolling
- No touch events

**Use Case:** Customer got distracted, send reminder email

### 2. Navigation Away
Customer leaves the checkout page.

**Triggers:**
- Click external link
- Browser back button
- URL change via History API
- Tab close
- Browser close

**Use Case:** Customer comparing prices, track competitor analysis

### 3. Payment Failed
Payment attempts repeatedly fail.

**Triggers:**
- Card declined multiple times
- Insufficient funds
- Technical payment errors
- 3D Secure failures

**Use Case:** Suggest alternative payment methods

### 4. User Cancelled
Customer explicitly cancels checkout.

**Triggers:**
- Click "Cancel" button
- Click "Return to Cart"
- Close modal/drawer

**Use Case:** Survey reason for cancellation

### 5. Session Expired
Server session times out.

**Triggers:**
- Session cookie expires
- Backend timeout
- Security logout

**Use Case:** Allow session recovery, save cart

## Implementation

### Backend Components

#### 1. CheckoutAbandonedEvent

```php
$event = new CheckoutAbandonedEvent(
    sessionId: 'checkout_123',
    customerId: 'customer_456',
    reason: CheckoutAbandonedEvent::REASON_TIMEOUT,
    checkoutState: [
        'currentStage' => 'payment',
        'addressCompleted' => true,
        'paymentAttempted' => false,
        'cartItems' => [...],
        'cartTotal' => 109.97,
        'currency' => 'EUR',
        'timeSpent' => 180, // seconds
        'email' => 'customer@example.com'
    ],
    contractId: 'contract_789',
    cartTotal: 109.97,
    currency: 'EUR'
);
```

#### 2. CheckoutAbandonedEventHandler

Handles the event by:
- Logging to analytics
- Releasing inventory
- Scheduling recovery emails
- Canceling pending payments

```php
class CheckoutAbandonedEventHandler
{
    public function handle(CheckoutAbandonedEvent $event): void
    {
        // Track metrics
        $this->trackAbandonmentMetrics($event);

        // Release inventory
        $this->releaseInventory($event);

        // Send recovery email (if has email)
        if ($event->getCustomerEmail()) {
            $this->scheduleRecoveryEmail($event);
        }

        // Cancel pending payments
        if ($event->getContractId()) {
            $this->cancelPendingPayments($event);
        }
    }
}
```

#### 3. GraphQL API

```graphql
mutation AbandonCheckout($input: AbandonCheckoutInput!) {
    abandonCheckout(input: $input) {
        success
        message
    }
}
```

### Frontend Tracking

#### 1. Initialize Tracker

```javascript
const abandonmentTracker = new CheckoutAbandonmentTracker(checkout, {
    timeoutMinutes: 15,           // Inactivity timeout
    trackNavigation: true,        // Track navigation away
    trackPageUnload: true,        // Track browser close
    trackPaymentFailure: true     // Track payment failures
});
```

#### 2. Set Initial State

```javascript
abandonmentTracker.updateState({
    cartItems: [
        {
            productId: 'P123',
            productName: 'Product A',
            quantity: 2,
            price: 29.99
        }
    ],
    cartTotal: 59.98,
    currency: 'EUR',
    stage: 'address'
});
```

#### 3. Update as Customer Progresses

```javascript
// After address completion
abandonmentTracker.updateState({
    stage: 'payment',
    addressCompleted: true,
    email: addressData.email,
    billingAddress: addressData.billingAddress
});

// After payment attempt
abandonmentTracker.updateState({
    paymentAttempted: true,
    contractId: paymentResponse.contractId
});
```

#### 4. Mark Complete

```javascript
// When payment succeeds
if (payment.status === 'SUCCEEDED') {
    abandonmentTracker.markComplete();
}
```

#### 5. Track Payment Failure

```javascript
// When payment fails
if (!payment.success) {
    abandonmentTracker.trackPaymentFailure(payment.message);
}
```

## Tracking Mechanisms

### 1. Inactivity Timer

Resets on user activity:
- Mouse movement
- Keyboard input
- Scroll events
- Touch events

Triggers after configured timeout (default: 15 minutes)

### 2. Navigation Detection

**History API Monitoring:**
```javascript
history.pushState = function(...args) {
    const result = originalPushState.apply(this, args);
    abandonmentTracker.handleNavigation('NAVIGATION');
    return result;
};
```

**Link Click Monitoring:**
```javascript
document.addEventListener('click', (e) => {
    const link = e.target.closest('a');
    if (link && !link.href.includes('/checkout')) {
        abandonmentTracker.handleNavigation('NAVIGATION');
    }
});
```

### 3. Page Unload (Browser Close)

Uses `navigator.sendBeacon` for reliable tracking:

```javascript
window.addEventListener('beforeunload', () => {
    abandonmentTracker.sendAbandonmentBeacon('NAVIGATION');
});
```

**Why sendBeacon?**
- Guaranteed delivery even during page unload
- Non-blocking (doesn't delay navigation)
- Works on mobile browsers

### 4. Visibility API

Tracks tab switches and prolonged absences:

```javascript
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        // User left
        lastHiddenTime = Date.now();
    } else {
        // User returned
        const timeAway = Date.now() - lastHiddenTime;
        if (timeAway > 5 * 60 * 1000) { // 5 minutes
            abandonmentTracker.trackAbandonment('TIMEOUT');
        }
    }
});
```

## Cart Recovery

### Email Sequence

**1 Hour Later:**
```
Subject: Complete your purchase
Body: You left items in your cart. Complete your order now!
CTA: Return to Checkout
```

**24 Hours Later:**
```
Subject: Still interested in these items?
Body: Your cart is waiting. Items may sell out soon.
CTA: Complete Order
Incentive: Free shipping
```

**3 Days Later:**
```
Subject: Last chance - 10% off your order!
Body: We saved your cart. Get 10% off if you complete today.
CTA: Claim Discount
Incentive: 10% discount code
```

### Implementation

```php
private function scheduleRecoveryEmail(CheckoutAbandonedEvent $event): void
{
    $email = $event->getCustomerEmail();
    $cartTotal = $event->getCartTotal();

    // Only for high-value carts
    if ($cartTotal < 20.00) {
        return;
    }

    // Schedule 3 emails
    $this->emailService->scheduleRecoveryEmail(
        email: $email,
        cartData: $event->getCartItems(),
        cartTotal: $cartTotal,
        sessionId: $event->getSessionId(),
        delays: [
            3600,      // 1 hour
            86400,     // 24 hours
            259200     // 3 days
        ],
        incentives: [
            null,                    // No incentive
            'free_shipping',        // Free shipping
            'discount_10_percent'   // 10% discount
        ]
    );
}
```

## Analytics Integration

### Google Analytics 4

```javascript
abandonmentTracker.onAbandonment = (reason, state) => {
    gtag('event', 'checkout_abandonment', {
        event_category: 'Checkout',
        event_label: reason,
        value: state.cartTotal,
        currency: state.currency,
        checkout_stage: state.currentStage,
        address_completed: state.addressCompleted,
        payment_attempted: state.paymentAttempted,
        time_spent: state.timeSpent
    });
};
```

### Custom Analytics

```javascript
abandonmentTracker.onAbandonment = async (reason, state) => {
    await fetch('/api/analytics/abandonment', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            session_id: tracker.sessionId,
            reason: reason,
            stage: state.currentStage,
            cart_total: state.cartTotal,
            time_spent: state.timeSpent,
            items_count: state.cartItems.length
        })
    });
};
```

## Metrics Dashboard

### Key Metrics

**Abandonment Rate:**
```
Abandonment Rate = (Abandoned Carts / Total Carts Started) × 100
```

**Recovery Rate:**
```
Recovery Rate = (Recovered Carts / Abandoned Carts) × 100
```

**Revenue Lost:**
```
Revenue Lost = Σ(Abandoned Cart Values)
```

**Revenue Recovered:**
```
Revenue Recovered = Σ(Recovered Cart Values)
```

### Segmentation

**By Stage:**
- Address stage: 35%
- Payment stage: 45%
- Review stage: 20%

**By Reason:**
- Navigation: 40%
- Timeout: 30%
- Payment failed: 20%
- User cancelled: 10%

**By Cart Value:**
- $0-$50: 50% abandonment
- $50-$100: 60% abandonment
- $100+: 70% abandonment

## Inventory Management

### Release Reserved Stock

```php
private function releaseInventory(CheckoutAbandonedEvent $event): void
{
    foreach ($event->getCartItems() as $item) {
        $this->inventoryService->releaseReservation(
            productId: $item['productId'],
            quantity: $item['quantity'],
            sessionId: $event->getSessionId()
        );

        $this->logger->info('Inventory released', [
            'productId' => $item['productId'],
            'quantity' => $item['quantity'],
            'reason' => 'checkout_abandoned'
        ]);
    }
}
```

### Time-Based Release

```php
// Release after 30 minutes
$this->inventoryService->scheduleRelease(
    sessionId: $event->getSessionId(),
    delayMinutes: 30
);
```

## Best Practices

### 1. Don't Track Too Aggressively

❌ **Bad:** Track every mouse movement
✅ **Good:** Track after 15 minutes of inactivity

### 2. Respect Privacy

❌ **Bad:** Track personal browsing habits
✅ **Good:** Only track checkout-specific behavior

### 3. Provide Value in Recovery

❌ **Bad:** Generic "You left items" email
✅ **Good:** Personalized email with incentive

### 4. Limit Email Frequency

❌ **Bad:** Daily reminder emails
✅ **Good:** 3 emails over 3 days, then stop

### 5. Test Recovery Strategies

- A/B test email timing
- Test different incentives
- Optimize subject lines
- Test CTA placement

## Troubleshooting

### Abandonment Not Tracked

**Check:**
1. JavaScript loaded properly
2. GraphQL endpoint accessible
3. Event handler registered
4. Tracker initialized

**Debug:**
```javascript
console.log('Tracker initialized:', abandonmentTracker.sessionId);
abandonmentTracker.onAbandonment = (reason, state) => {
    console.log('Abandonment tracked:', reason, state);
};
```

### sendBeacon Not Working

**Fallback:**
```javascript
if (!navigator.sendBeacon) {
    // Fallback for old browsers
    fetch(url, {
        method: 'POST',
        body: data,
        keepalive: true  // Important!
    });
}
```

### Recovery Emails Not Sending

**Check:**
1. Email service configured
2. Cart value threshold met
3. Customer email captured
4. Email scheduler running

## ROI Calculation

### Example Store

**Monthly Stats:**
- 1,000 checkouts started
- 700 abandoned (70% rate)
- Average cart value: $75
- Recovery rate: 12%

**Revenue Impact:**
```
Lost Revenue = 700 × $75 = $52,500
Recovered Revenue = 700 × 12% × $75 = $6,300

Monthly ROI from cart recovery = $6,300
Annual ROI = $75,600
```

**Implementation Cost:**
- Development: 1 week
- Maintenance: 2 hours/month

**Payback Period:** Immediate

## Further Reading

- [Baymard Institute - Cart Abandonment Statistics](https://baymard.com/lists/cart-abandonment-rate)
- [Optimizely - Cart Abandonment Strategies](https://www.optimizely.com/optimization-glossary/cart-abandonment/)
- [Shopify - Abandoned Cart Recovery](https://www.shopify.com/blog/reduce-shopping-cart-abandonment)
- [Navigator.sendBeacon API](https://developer.mozilla.org/en-US/docs/Web/API/Navigator/sendBeacon)
