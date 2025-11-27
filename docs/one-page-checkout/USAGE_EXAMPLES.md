# Usage Examples - One-Page Checkout

## Table of Contents

1. [Basic Setup](#basic-setup)
2. [Template Customization](#template-customization)
3. [JavaScript Integration](#javascript-integration)
4. [Backend Integration](#backend-integration)
5. [Event Handling](#event-handling)
6. [Error Handling](#error-handling)
7. [Abandonment Tracking](#abandonment-tracking)
8. [Payment Scenarios](#payment-scenarios)
9. [Testing Scenarios](#testing-scenarios)
10. [Real-World Examples](#real-world-examples)

---

## Basic Setup

### Example 1: Install and Activate

```bash
# 1. Navigate to OXID root
cd /path/to/oxid

# 2. Verify files are in place
ls -la source/extensions/stripe/views/twig/page/checkout/

# 3. Clear all caches
rm -rf source/tmp/*

# 4. Activate module (if not already active)
php bin/oe-console oe:module:activate stripe

# 5. Regenerate views
php bin/oe-console oe:views:generate

# 6. Test access
curl -I https://your-shop.com/checkout-onepage
```

### Example 2: Environment Configuration

```bash
# .env file
PAYMENT_ENCRYPTION_KEY=base64:YourGeneratedKeyHere
STRIPE_PUBLIC_KEY=pk_test_51H1234567890abcdef
STRIPE_SECRET_KEY=sk_test_51H1234567890abcdef
STRIPE_WEBHOOK_SECRET=whsec_1234567890abcdef
```

Generate encryption key:
```bash
# Generate a secure 32-byte key
php -r "echo 'PAYMENT_ENCRYPTION_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

---

## Template Customization

### Example 3: Override Main Template

Create in your theme:
```
application/views/twig/page/checkout/my-custom-checkout.html.twig
```

```twig
{# Extend the original template #}
{% extends "@stripe/page/checkout/onepage.html.twig" %}

{# Add custom header #}
{% block head_css %}
    {{ parent() }}
    <link rel="stylesheet" href="/custom/checkout-theme.css">
{% endblock %}

{# Add custom content before checkout #}
{% block content %}
    <div class="custom-banner">
        <h2>🎉 Special Offer: Free Shipping Today!</h2>
    </div>

    {{ parent() }}
{% endblock %}

{# Add custom JavaScript #}
{% block footer_js %}
    {{ parent() }}
    <script>
        // Custom analytics
        gtag('event', 'checkout_started', {
            'value': {{ oxcmp_basket.getPrice().getBruttoPrice() }},
            'currency': '{{ oxcmp_basket.getBasketCurrency().name }}'
        });
    </script>
{% endblock %}
```

### Example 4: Customize Address Form

```twig
{# application/views/twig/page/checkout/inc/address_form.html.twig #}
{% extends "@stripe/page/checkout/inc/address_form.html.twig" %}

{# Add company field #}
{% block billing_address_fields %}
    {{ parent() }}

    <div class="form-group">
        <label for="billingCompany" class="form-label">
            {{ "CHECKOUT_COMPANY"|translate }}
        </label>
        <input
            type="text"
            id="billingCompany"
            name="billingAddress[company]"
            class="form-input"
            value="{{ deliveryAddress.oxaddress__oxcompany.value|default('') }}"
        >
    </div>
{% endblock %}
```

### Example 5: Add Custom Trust Badges

```twig
{# Override trust badges section #}
{% block trust_badges %}
    <div class="trust-badges">
        <div class="trust-badge">
            <img src="/images/badges/ssl.svg" alt="SSL Secure">
            <span>256-bit SSL Encryption</span>
        </div>
        <div class="trust-badge">
            <img src="/images/badges/money-back.svg" alt="Money Back">
            <span>30-Day Money Back Guarantee</span>
        </div>
        <div class="trust-badge">
            <img src="/images/badges/certified.svg" alt="Certified">
            <span>PCI DSS Level 1 Certified</span>
        </div>
    </div>
{% endblock %}
```

---

## JavaScript Integration

### Example 6: Add Custom Validation

```javascript
// custom-checkout-validation.js

// Extend CheckoutFlow
class CustomCheckoutFlow extends CheckoutFlow {
    constructor(client, tracker, config) {
        super(client, tracker, config);
        this.addCustomValidation();
    }

    addCustomValidation() {
        // Validate phone number format
        document.getElementById('billingPhone')?.addEventListener('blur', (e) => {
            const phone = e.target.value;
            const phoneRegex = /^[\d\s\-\+\(\)]+$/;

            if (phone && !phoneRegex.test(phone)) {
                this.client.errorHandler.showInlineError(
                    'billingAddress[phone]',
                    'Please enter a valid phone number'
                );
            }
        });

        // Validate email domain
        document.getElementById('billingEmail')?.addEventListener('blur', (e) => {
            const email = e.target.value;
            const blockedDomains = ['tempmail.com', 'throwaway.email'];

            const domain = email.split('@')[1];
            if (blockedDomains.includes(domain)) {
                this.client.errorHandler.showInlineError(
                    'billingAddress[email]',
                    'Please use a permanent email address'
                );
            }
        });
    }

    // Override address submission with additional checks
    async handleAddressSubmit(e) {
        e.preventDefault();

        // Custom pre-validation
        if (!this.validateBusinessRules()) {
            return;
        }

        // Call parent implementation
        await super.handleAddressSubmit(e);
    }

    validateBusinessRules() {
        // Example: Minimum age requirement
        const birthdate = document.getElementById('birthdate')?.value;
        if (birthdate) {
            const age = this.calculateAge(birthdate);
            if (age < 18) {
                alert('You must be 18 or older to place an order');
                return false;
            }
        }
        return true;
    }

    calculateAge(birthdate) {
        const today = new Date();
        const birth = new Date(birthdate);
        let age = today.getFullYear() - birth.getFullYear();
        const monthDiff = today.getMonth() - birth.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
            age--;
        }
        return age;
    }
}

// Use custom flow
window.addEventListener('DOMContentLoaded', () => {
    if (typeof checkoutConfig !== 'undefined') {
        const checkout = new EnhancedCheckoutClient(
            checkoutConfig.graphqlEndpoint,
            checkoutConfig.encryptionKey
        );
        const tracker = new CheckoutAbandonmentTracker(checkout);

        // Use custom flow instead of default
        window.oxCheckout = new CustomCheckoutFlow(checkout, tracker, checkoutConfig);
    }
});
```

### Example 7: Add Address Autocomplete (Google Places)

```javascript
// address-autocomplete.js

class AddressAutocomplete {
    constructor() {
        this.initializeAutocomplete();
    }

    initializeAutocomplete() {
        // Load Google Places API
        if (typeof google === 'undefined') {
            this.loadGooglePlacesAPI();
            return;
        }

        const streetInput = document.getElementById('billingStreet');
        if (!streetInput) return;

        const autocomplete = new google.maps.places.Autocomplete(streetInput, {
            types: ['address'],
            componentRestrictions: { country: ['de', 'at', 'ch'] }
        });

        autocomplete.addListener('place_changed', () => {
            const place = autocomplete.getPlace();
            this.fillInAddress(place);
        });
    }

    fillInAddress(place) {
        const addressComponents = place.address_components;

        const mapping = {
            street_number: 'billingStreetNo',
            route: 'billingStreet',
            locality: 'billingCity',
            postal_code: 'billingZip',
            country: 'billingCountry'
        };

        for (const component of addressComponents) {
            const type = component.types[0];
            const inputId = mapping[type];

            if (inputId) {
                const input = document.getElementById(inputId);
                if (input) {
                    input.value = component.long_name;
                }
            }
        }
    }

    loadGooglePlacesAPI() {
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&libraries=places`;
        script.async = true;
        script.onload = () => this.initializeAutocomplete();
        document.head.appendChild(script);
    }
}

// Initialize
new AddressAutocomplete();
```

### Example 8: Real-Time Shipping Calculator

```javascript
// shipping-calculator.js

class ShippingCalculator {
    constructor() {
        this.setupListeners();
    }

    setupListeners() {
        // Recalculate on country change
        document.getElementById('billingCountry')?.addEventListener('change', (e) => {
            this.calculateShipping(e.target.value);
        });

        // Recalculate on ZIP change
        document.getElementById('billingZip')?.addEventListener('blur', (e) => {
            const country = document.getElementById('billingCountry')?.value;
            if (country) {
                this.calculateShipping(country, e.target.value);
            }
        });
    }

    async calculateShipping(countryCode, zipCode = null) {
        try {
            const response = await fetch('/api/calculate-shipping', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    country: countryCode,
                    zip: zipCode,
                    cartTotal: checkoutConfig.cartTotal
                })
            });

            const data = await response.json();
            this.updateShippingDisplay(data);
        } catch (error) {
            console.error('Shipping calculation failed:', error);
        }
    }

    updateShippingDisplay(data) {
        const shippingElement = document.querySelector('.shipping-cost');
        if (!shippingElement) return;

        if (data.shippingCost === 0) {
            shippingElement.innerHTML = `
                <span class="free-badge">FREE</span>
                <small>Free shipping to ${data.countryName}</small>
            `;
        } else {
            shippingElement.innerHTML = `
                ${data.shippingCost.toFixed(2)} ${data.currency}
                <small>${data.deliveryTime}</small>
            `;
        }

        // Update total
        const total = checkoutConfig.cartTotal + data.shippingCost;
        document.querySelector('.total-amount').textContent =
            `${total.toFixed(2)} ${data.currency}`;
    }
}

new ShippingCalculator();
```

---

## Backend Integration

### Example 9: Custom Event Subscriber

```php
<?php

namespace MyShop\CustomCheckout;

use OxidEsales\StripeWallet\Component\EventSystem\Event\PaymentCompletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Send SMS notification when payment is completed
 */
class SmsNotificationSubscriber
{
    public function __construct(
        private readonly SmsService $smsService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(PaymentCompletedEvent $event): void
    {
        if (!$event->isSuccessful()) {
            return;
        }

        try {
            $phoneNumber = $this->getCustomerPhone($event->getCustomerId());

            if ($phoneNumber) {
                $this->smsService->send(
                    to: $phoneNumber,
                    message: sprintf(
                        'Your order %s has been confirmed! Total: %s %s',
                        $event->getOrderId(),
                        number_format($event->getAmount(), 2),
                        $event->getCurrency()
                    )
                );

                $this->logger->info('SMS notification sent', [
                    'orderId' => $event->getOrderId(),
                    'phone' => $phoneNumber
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('SMS notification failed', [
                'error' => $e->getMessage(),
                'orderId' => $event->getOrderId()
            ]);
        }
    }

    private function getCustomerPhone(string $customerId): ?string
    {
        // Retrieve phone from database
        // Implementation depends on your setup
        return null;
    }
}
```

Register in `services.yaml`:
```yaml
services:
    MyShop\CustomCheckout\SmsNotificationSubscriber:
        arguments:
            $smsService: '@sms.service'
            $logger: '@logger'
        tags:
            - { name: 'event.subscriber', event: 'PaymentCompletedEvent', priority: 50 }
```

### Example 10: Loyalty Points Integration

```php
<?php

namespace MyShop\CustomCheckout;

use OxidEsales\StripeWallet\Component\EventSystem\Event\PaymentCompletedEvent;

class LoyaltyPointsHandler
{
    public function __construct(
        private readonly LoyaltyService $loyaltyService
    ) {
    }

    public function handle(PaymentCompletedEvent $event): void
    {
        if (!$event->isSuccessful()) {
            return;
        }

        $customerId = $event->getCustomerId();
        $orderAmount = $event->getAmount();

        // Award points: 1 point per euro spent
        $points = (int) floor($orderAmount);

        $this->loyaltyService->awardPoints(
            customerId: $customerId,
            points: $points,
            reason: "Order {$event->getOrderId()}",
            orderId: $event->getOrderId()
        );

        // Check for milestone rewards
        $totalPoints = $this->loyaltyService->getTotalPoints($customerId);

        if ($totalPoints >= 1000 && !$this->loyaltyService->hasRedeemed($customerId, 'gold_status')) {
            $this->loyaltyService->grantReward($customerId, 'gold_status');
            $this->sendGoldStatusEmail($customerId);
        }
    }

    private function sendGoldStatusEmail(string $customerId): void
    {
        // Send congratulations email
    }
}
```

### Example 11: Inventory Management

```php
<?php

namespace MyShop\CustomCheckout;

use OxidEsales\StripeWallet\Component\EventSystem\Event\CheckoutAbandonedEvent;
use OxidEsales\StripeWallet\Component\EventSystem\Event\PaymentInitiatedEvent;

class InventoryManagementHandler
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {
    }

    /**
     * Reserve inventory when payment is initiated
     */
    public function handlePaymentInitiated(PaymentInitiatedEvent $event): void
    {
        $cartItems = $event->getCartItems();
        $sessionId = $event->getContractId();

        foreach ($cartItems as $item) {
            $this->inventoryService->reserveStock(
                productId: $item['productId'],
                quantity: $item['quantity'],
                sessionId: $sessionId,
                expiresAt: time() + 1800 // 30 minutes
            );
        }
    }

    /**
     * Release inventory when checkout is abandoned
     */
    public function handleCheckoutAbandoned(CheckoutAbandonedEvent $event): void
    {
        $cartItems = $event->getCartItems();
        $sessionId = $event->getSessionId();

        foreach ($cartItems as $item) {
            $this->inventoryService->releaseReservation(
                productId: $item['productId'],
                sessionId: $sessionId
            );
        }
    }
}
```

---

## Event Handling

### Example 12: Multi-Channel Order Notification

```php
<?php

namespace MyShop\CustomCheckout;

use OxidEsales\StripeWallet\Component\EventSystem\Event\PaymentCompletedEvent;

class MultiChannelNotificationHandler
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly SlackService $slackService,
        private readonly WebhookService $webhookService
    ) {
    }

    public function handle(PaymentCompletedEvent $event): void
    {
        if (!$event->isSuccessful()) {
            return;
        }

        // Send email to customer
        $this->sendCustomerEmail($event);

        // Notify team via Slack
        $this->notifyTeam($event);

        // Trigger external webhooks
        $this->triggerWebhooks($event);
    }

    private function sendCustomerEmail(PaymentCompletedEvent $event): void
    {
        $this->emailService->send([
            'to' => $event->getCustomerEmail(),
            'template' => 'order-confirmation',
            'data' => [
                'orderId' => $event->getOrderId(),
                'amount' => $event->getAmount(),
                'currency' => $event->getCurrency(),
                'items' => $event->getCartItems()
            ]
        ]);
    }

    private function notifyTeam(PaymentCompletedEvent $event): void
    {
        // High-value order alert
        if ($event->getAmount() > 1000) {
            $this->slackService->send(
                channel: '#high-value-orders',
                message: sprintf(
                    '💰 New high-value order! Order #%s - %s %s',
                    $event->getOrderId(),
                    number_format($event->getAmount(), 2),
                    $event->getCurrency()
                )
            );
        }
    }

    private function triggerWebhooks(PaymentCompletedEvent $event): void
    {
        $this->webhookService->trigger('order.completed', [
            'orderId' => $event->getOrderId(),
            'customerId' => $event->getCustomerId(),
            'amount' => $event->getAmount(),
            'currency' => $event->getCurrency(),
            'timestamp' => time()
        ]);
    }
}
```

### Example 13: Fraud Detection Integration

```php
<?php

namespace MyShop\CustomCheckout;

use OxidEsales\StripeWallet\Component\EventSystem\Event\PaymentInitiatedEvent;

class FraudDetectionHandler
{
    public function __construct(
        private readonly FraudService $fraudService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(PaymentInitiatedEvent $event): void
    {
        $riskScore = $this->calculateRiskScore($event);

        if ($riskScore > 80) {
            // High risk - flag for manual review
            $this->flagForReview($event, $riskScore);

            // Optionally block the payment
            // throw new FraudException('Transaction blocked due to high risk');
        } elseif ($riskScore > 50) {
            // Medium risk - require additional verification
            $this->requireAdditionalVerification($event);
        }

        $this->logger->info('Fraud check completed', [
            'contractId' => $event->getContractId(),
            'riskScore' => $riskScore
        ]);
    }

    private function calculateRiskScore(PaymentInitiatedEvent $event): int
    {
        $score = 0;

        // Check velocity (multiple orders in short time)
        $recentOrders = $this->fraudService->getRecentOrders(
            $event->getCustomerId(),
            3600 // last hour
        );
        if (count($recentOrders) > 3) {
            $score += 30;
        }

        // Check if card matches billing address country
        $cardCountry = $this->fraudService->getCardCountry($event->getPaymentMethod());
        $billingCountry = $event->getBillingAddress()['countryCode'];
        if ($cardCountry !== $billingCountry) {
            $score += 20;
        }

        // Check amount
        if ($event->getAmount() > 5000) {
            $score += 15;
        }

        // Check if new customer
        if ($this->fraudService->isNewCustomer($event->getCustomerId())) {
            $score += 10;
        }

        return min($score, 100);
    }

    private function flagForReview(PaymentInitiatedEvent $event, int $score): void
    {
        $this->fraudService->createReviewCase([
            'contractId' => $event->getContractId(),
            'customerId' => $event->getCustomerId(),
            'riskScore' => $score,
            'reason' => 'Automated fraud detection',
            'status' => 'pending_review'
        ]);
    }

    private function requireAdditionalVerification(PaymentInitiatedEvent $event): void
    {
        // Force 3D Secure even if not required by Stripe
        $this->fraudService->force3DSecure($event->getContractId());
    }
}
```

---

## Error Handling

### Example 14: Custom Error Messages

```javascript
// custom-error-messages.js

// Override error messages
const customErrorMessages = {
    'card_declined': 'Your card was declined. Please contact your bank or try a different card.',
    'insufficient_funds': 'Insufficient funds. Please use a different card or payment method.',
    'expired_card': 'This card has expired. Please use a different card.',
    'incorrect_cvc': 'The security code is incorrect. Please check the 3-digit code on the back of your card.',
    'processing_error': 'We encountered an issue processing your payment. Please try again in a moment.',
    'rate_limit': 'Too many payment attempts. Please wait 5 minutes before trying again.',
};

// Extend error handler
const originalHandlePaymentError = CheckoutErrorHandler.prototype.handlePaymentError;

CheckoutErrorHandler.prototype.handlePaymentError = function(error, context) {
    // Use custom message if available
    if (customErrorMessages[error.code]) {
        error.message = customErrorMessages[error.code];
    }

    // Add custom context
    if (error.code === 'card_declined') {
        error.message += '\n\nAlternative payment methods:\n• PayPal\n• Bank Transfer\n• SEPA Direct Debit';
    }

    // Call original handler
    originalHandlePaymentError.call(this, error, context);

    // Track in analytics
    if (typeof gtag !== 'undefined') {
        gtag('event', 'payment_error', {
            'error_code': error.code,
            'error_message': error.message,
            'payment_amount': context.amount
        });
    }
};
```

### Example 15: Retry with Exponential Backoff

```javascript
// advanced-retry.js

class AdvancedRetryHandler {
    constructor(errorHandler) {
        this.errorHandler = errorHandler;
        this.retryAttempts = new Map();
    }

    async retryWithBackoff(operation, key, maxRetries = 5) {
        const attempts = this.retryAttempts.get(key) || 0;

        if (attempts >= maxRetries) {
            throw new Error('Maximum retry attempts exceeded');
        }

        try {
            return await operation();
        } catch (error) {
            if (!this.isRetryable(error)) {
                throw error;
            }

            this.retryAttempts.set(key, attempts + 1);

            // Exponential backoff: 1s, 2s, 4s, 8s, 16s
            const delay = Math.pow(2, attempts) * 1000;

            this.errorHandler.showToast({
                type: 'info',
                title: 'Retrying...',
                message: `Attempt ${attempts + 2} of ${maxRetries + 1} in ${delay / 1000}s`,
                duration: delay
            });

            await this.sleep(delay);

            return this.retryWithBackoff(operation, key, maxRetries);
        }
    }

    isRetryable(error) {
        const retryableCodes = [
            'processing_error',
            'rate_limit',
            'network_error',
            'timeout'
        ];
        return retryableCodes.includes(error.code);
    }

    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    reset(key) {
        this.retryAttempts.delete(key);
    }
}

// Usage
const retryHandler = new AdvancedRetryHandler(errorHandler);

async function submitPaymentWithRetry() {
    try {
        const result = await retryHandler.retryWithBackoff(
            () => checkout.processPayment(cardData, amount, currency),
            'payment-submission'
        );

        // Success
        retryHandler.reset('payment-submission');
        handleSuccess(result);
    } catch (error) {
        // All retries failed
        handleFailure(error);
    }
}
```

---

## Abandonment Tracking

### Example 16: Custom Abandonment Reasons

```javascript
// custom-abandonment-tracking.js

class CustomAbandonmentTracker extends CheckoutAbandonmentTracker {
    constructor(client, options) {
        super(client, options);
        this.trackCustomReasons();
    }

    trackCustomReasons() {
        // Track when user clicks competitor price comparison
        document.querySelectorAll('a[href*="competitor"]').forEach(link => {
            link.addEventListener('click', () => {
                this.trackAbandonment('COMPETITOR_COMPARISON', {
                    competitor: link.dataset.competitor,
                    referrer: link.href
                });
            });
        });

        // Track high shipping cost abandonment
        const shippingCost = parseFloat(document.querySelector('.shipping-cost')?.textContent || '0');
        if (shippingCost > 10) {
            this.metadata.highShippingCost = true;
            this.metadata.shippingAmount = shippingCost;
        }

        // Track form complexity abandonment
        const formFields = document.querySelectorAll('input, select, textarea').length;
        if (formFields > 15) {
            this.metadata.complexForm = true;
            this.metadata.fieldCount = formFields;
        }

        // Track if user opened help/FAQ
        document.querySelectorAll('a[href*="help"], a[href*="faq"]').forEach(link => {
            link.addEventListener('click', () => {
                this.metadata.seekedHelp = true;
                this.metadata.helpTopic = link.dataset.topic;
            });
        });
    }

    // Override to add custom metadata
    buildCheckoutState() {
        const state = super.buildCheckoutState();

        // Add custom abandonment data
        state.customData = {
            ...this.metadata,
            browserInfo: {
                userAgent: navigator.userAgent,
                language: navigator.language,
                screenResolution: `${screen.width}x${screen.height}`
            },
            performanceMetrics: {
                pageLoadTime: performance.timing.loadEventEnd - performance.timing.navigationStart,
                timeOnPage: Date.now() - this.checkoutStartTime
            }
        };

        return state;
    }
}
```

### Example 17: Cart Recovery Email Integration

```php
<?php

namespace MyShop\CustomCheckout;

use OxidEsales\StripeWallet\Component\EventSystem\Event\CheckoutAbandonedEvent;

class CartRecoveryEmailHandler
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly QueueService $queueService
    ) {
    }

    public function handle(CheckoutAbandonedEvent $event): void
    {
        $email = $event->getCustomerEmail();

        if (!$email || $event->getCartTotal() < 20) {
            return; // Skip low-value carts
        }

        // Schedule recovery email sequence
        $this->scheduleRecoverySequence($event);
    }

    private function scheduleRecoverySequence(CheckoutAbandonedEvent $event): void
    {
        $cartData = [
            'sessionId' => $event->getSessionId(),
            'email' => $event->getCustomerEmail(),
            'cartItems' => $event->getCartItems(),
            'cartTotal' => $event->getCartTotal(),
            'currency' => $event->getCurrency(),
            'abandonedAt' => date('Y-m-d H:i:s')
        ];

        // Email 1: After 1 hour - Reminder
        $this->queueService->schedule(
            job: 'send-cart-recovery-email',
            data: array_merge($cartData, [
                'template' => 'cart-recovery-1-hour',
                'subject' => 'Did you forget something?',
                'incentive' => null
            ]),
            delay: 3600 // 1 hour
        );

        // Email 2: After 24 hours - With free shipping
        $this->queueService->schedule(
            job: 'send-cart-recovery-email',
            data: array_merge($cartData, [
                'template' => 'cart-recovery-24-hours',
                'subject' => 'Complete your order - Free shipping!',
                'incentive' => 'free_shipping',
                'couponCode' => $this->generateCoupon('FREE_SHIP')
            ]),
            delay: 86400 // 24 hours
        );

        // Email 3: After 3 days - With 10% discount
        $this->queueService->schedule(
            job: 'send-cart-recovery-email',
            data: array_merge($cartData, [
                'template' => 'cart-recovery-3-days',
                'subject' => 'Last chance - 10% off your order!',
                'incentive' => 'discount_10',
                'couponCode' => $this->generateCoupon('SAVE10')
            ]),
            delay: 259200 // 3 days
        );
    }

    private function generateCoupon(string $prefix): string
    {
        return $prefix . '_' . strtoupper(substr(md5(uniqid()), 0, 8));
    }
}
```

---

## Payment Scenarios

### Example 18: Handling 3D Secure

```javascript
// 3ds-handler.js

class ThreeDSecureHandler {
    constructor(checkout) {
        this.checkout = checkout;
        this.handle3DSReturn();
    }

    async processPaymentWith3DS(cardData, amount, currency) {
        const result = await this.checkout.processPayment(cardData, amount, currency, {
            returnUrl: window.location.origin + '/checkout-onepage?fnc=handleReturn'
        });

        const payment = result.processPayment;

        if (payment.status === 'REQUIRES_ACTION' && payment.redirectUrl) {
            // Save current state before redirect
            this.saveCheckoutState();

            // Redirect to 3D Secure
            window.location.href = payment.redirectUrl;
        }

        return payment;
    }

    handle3DSReturn() {
        const urlParams = new URLSearchParams(window.location.search);
        const paymentIntent = urlParams.get('payment_intent');
        const paymentIntentClientSecret = urlParams.get('payment_intent_client_secret');

        if (paymentIntent && paymentIntentClientSecret) {
            this.verify3DSResult(paymentIntent, paymentIntentClientSecret);
        }
    }

    async verify3DSResult(paymentIntentId, clientSecret) {
        try {
            // Show loading
            this.showProcessing();

            // Verify with backend
            const response = await fetch('/api/verify-3ds', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    payment_intent: paymentIntentId,
                    client_secret: clientSecret
                })
            });

            const data = await response.json();

            if (data.success) {
                // Restore checkout state
                this.restoreCheckoutState();

                // Show success
                window.oxCheckout.showSuccess(data.orderId);
            } else {
                this.showError('3D Secure authentication failed');
            }
        } catch (error) {
            this.showError('Failed to verify 3D Secure authentication');
        }
    }

    saveCheckoutState() {
        sessionStorage.setItem('checkout_state', JSON.stringify({
            address: window.oxCheckout.stepData.address,
            timestamp: Date.now()
        }));
    }

    restoreCheckoutState() {
        const saved = sessionStorage.getItem('checkout_state');
        if (saved) {
            const state = JSON.parse(saved);
            window.oxCheckout.stepData.address = state.address;
            sessionStorage.removeItem('checkout_state');
        }
    }

    showProcessing() {
        document.body.innerHTML = `
            <div class="processing-3ds">
                <div class="spinner-large"></div>
                <h2>Verifying your payment...</h2>
                <p>Please wait while we confirm your authentication.</p>
            </div>
        `;
    }

    showError(message) {
        window.oxCheckout.client.errorHandler.showToast({
            type: 'error',
            title: '3D Secure Failed',
            message: message
        });
    }
}

// Initialize
new ThreeDSecureHandler(checkout);
```

### Example 19: Payment Method Switching

```javascript
// payment-method-switcher.js

class PaymentMethodSwitcher {
    constructor() {
        this.setupSwitching();
    }

    setupSwitching() {
        document.querySelectorAll('input[name="paymentMethod"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                this.switchPaymentMethod(e.target.value);
            });
        });
    }

    switchPaymentMethod(methodId) {
        // Hide all payment forms
        document.querySelectorAll('.payment-method-form').forEach(form => {
            form.style.display = 'none';
        });

        // Show selected payment form
        const selectedForm = document.getElementById(`payment-form-${methodId}`);
        if (selectedForm) {
            selectedForm.style.display = 'block';
        }

        // Update submit button text
        const submitBtn = document.getElementById('payment-submit-btn');
        const methodName = this.getMethodName(methodId);
        submitBtn.textContent = `Pay with ${methodName}`;

        // Track analytics
        if (typeof gtag !== 'undefined') {
            gtag('event', 'payment_method_selected', {
                'method': methodId,
                'method_name': methodName
            });
        }
    }

    getMethodName(methodId) {
        const names = {
            'stripe_card': 'Credit Card',
            'stripe_sepa': 'SEPA Direct Debit',
            'stripe_ideal': 'iDEAL',
            'paypal': 'PayPal',
            'invoice': 'Invoice'
        };
        return names[methodId] || methodId;
    }
}

new PaymentMethodSwitcher();
```

---

## Testing Scenarios

### Example 20: Automated Testing with Stripe Test Cards

```javascript
// test-payment-scenarios.js

const testScenarios = {
    success: {
        card: '4242424242424242',
        exp_month: 12,
        exp_year: 2025,
        cvc: '123',
        expected: 'SUCCEEDED'
    },
    declined: {
        card: '4000000000000002',
        exp_month: 12,
        exp_year: 2025,
        cvc: '123',
        expected: 'FAILED',
        error: 'card_declined'
    },
    insufficient_funds: {
        card: '4000000000009995',
        exp_month: 12,
        exp_year: 2025,
        cvc: '123',
        expected: 'FAILED',
        error: 'insufficient_funds'
    },
    requires_3ds: {
        card: '4000002500003155',
        exp_month: 12,
        exp_year: 2025,
        cvc: '123',
        expected: 'REQUIRES_ACTION'
    }
};

async function runTestScenario(scenarioName) {
    console.log(`Testing scenario: ${scenarioName}`);

    const scenario = testScenarios[scenarioName];

    const cardData = {
        card: {
            number: scenario.card,
            exp_month: scenario.exp_month,
            exp_year: scenario.exp_year,
            cvc: scenario.cvc,
            name: 'Test User'
        }
    };

    try {
        const result = await checkout.processPayment(cardData, 2999, 'EUR');
        const payment = result.processPayment;

        console.log('Result:', payment);

        // Verify expected outcome
        if (payment.status === scenario.expected) {
            console.log(`✅ Test passed: ${scenarioName}`);
            return true;
        } else {
            console.error(`❌ Test failed: ${scenarioName}`);
            console.error(`Expected: ${scenario.expected}, Got: ${payment.status}`);
            return false;
        }
    } catch (error) {
        console.error(`❌ Test errored: ${scenarioName}`, error);
        return false;
    }
}

// Run all test scenarios
async function runAllTests() {
    const results = {};

    for (const [name, scenario] of Object.entries(testScenarios)) {
        results[name] = await runTestScenario(name);
        await new Promise(resolve => setTimeout(resolve, 1000)); // Wait between tests
    }

    console.log('\nTest Results:');
    console.table(results);
}

// Expose for manual testing
window.testCheckout = {
    runScenario: runTestScenario,
    runAllTests: runAllTests,
    scenarios: testScenarios
};
```

---

## Real-World Examples

### Example 21: Complete Checkout Flow

```javascript
// complete-checkout-example.js

async function completeCheckoutFlow() {
    // Step 1: Fill and submit address
    console.log('Step 1: Submitting address...');

    document.getElementById('billingFirstName').value = 'John';
    document.getElementById('billingLastName').value = 'Doe';
    document.getElementById('billingEmail').value = 'john.doe@example.com';
    document.getElementById('billingStreet').value = 'Hauptstraße';
    document.getElementById('billingStreetNo').value = '123';
    document.getElementById('billingCity').value = 'Berlin';
    document.getElementById('billingZip').value = '10115';
    document.getElementById('billingCountry').value = 'DE';

    document.getElementById('address-form').dispatchEvent(new Event('submit'));

    // Wait for address to be processed
    await waitForElement('#payment-section:not(.section-disabled)');

    console.log('✅ Address submitted successfully');

    // Step 2: Fill and submit payment
    console.log('Step 2: Submitting payment...');

    document.getElementById('cardholderName').value = 'John Doe';
    document.getElementById('cardNumber').value = '4242424242424242';
    document.getElementById('expMonth').value = '12';
    document.getElementById('expYear').value = '2025';
    document.getElementById('cvc').value = '123';
    document.getElementById('agreeTerms').checked = true;

    document.getElementById('payment-form').dispatchEvent(new Event('submit'));

    // Wait for success
    await waitForElement('#success-section[style*="display: block"]');

    console.log('✅ Payment completed successfully');
    console.log('Order ID:', document.getElementById('success-order-number').textContent);
}

function waitForElement(selector, timeout = 10000) {
    return new Promise((resolve, reject) => {
        const element = document.querySelector(selector);
        if (element) {
            resolve(element);
            return;
        }

        const observer = new MutationObserver((mutations, obs) => {
            const element = document.querySelector(selector);
            if (element) {
                obs.disconnect();
                resolve(element);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true
        });

        setTimeout(() => {
            observer.disconnect();
            reject(new Error(`Timeout waiting for ${selector}`));
        }, timeout);
    });
}

// Run the complete flow
window.testCompleteCheckout = completeCheckoutFlow;
```

### Example 22: High-Value Order Workflow

```php
<?php

namespace MyShop\CustomCheckout;

use OxidEsales\StripeWallet\Component\EventSystem\Event\PaymentInitiatedEvent;
use OxidEsales\StripeWallet\Component\EventSystem\Event\PaymentCompletedEvent;

/**
 * Special handling for high-value orders
 */
class HighValueOrderHandler
{
    private const HIGH_VALUE_THRESHOLD = 5000;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly FraudService $fraudService,
        private readonly ManagerService $managerService
    ) {
    }

    public function handlePaymentInitiated(PaymentInitiatedEvent $event): void
    {
        if ($event->getAmount() < self::HIGH_VALUE_THRESHOLD) {
            return;
        }

        // Additional fraud checks for high-value orders
        $this->fraudService->performEnhancedChecks($event);

        // Notify manager
        $this->managerService->notify([
            'type' => 'high_value_order_initiated',
            'amount' => $event->getAmount(),
            'customerId' => $event->getCustomerId(),
            'contractId' => $event->getContractId()
        ]);

        // Force 3D Secure
        $this->fraudService->force3DSecure($event->getContractId());
    }

    public function handlePaymentCompleted(PaymentCompletedEvent $event): void
    {
        if ($event->getAmount() < self::HIGH_VALUE_THRESHOLD || !$event->isSuccessful()) {
            return;
        }

        // Immediate manager approval required
        $this->managerService->createApprovalTask([
            'orderId' => $event->getOrderId(),
            'amount' => $event->getAmount(),
            'priority' => 'high',
            'reason' => 'High-value order requires approval'
        ]);

        // Send VIP confirmation email
        $this->notificationService->sendVIPConfirmation($event);

        // Assign dedicated account manager
        $this->managerService->assignAccountManager($event->getCustomerId());
    }
}
```

This comprehensive usage examples document covers all major scenarios! Would you like me to continue with more specific examples or update the main documentation files?