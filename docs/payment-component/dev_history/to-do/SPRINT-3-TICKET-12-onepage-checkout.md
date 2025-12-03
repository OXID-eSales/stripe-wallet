# SPRINT-3 TICKET-12: One-Page Checkout Implementation

**Priority:** 🟡 MEDIUM
**Estimated Effort:** 16-20 hours
**Sprint:** Sprint 3 (Frontend & Operations)
**Depends On:** TICKET-08, TICKET-09, TICKET-11
**Blocks:** Complete user checkout experience

---

## 📋 Overview

Implement a modern, single-page checkout experience with embedded Stripe payment form. Users complete address, shipping, and payment in one seamless flow without page reloads.

**Why This Matters:**
- Reduces cart abandonment (fewer steps = higher conversion)
- Modern UX expected by customers (Amazon-like checkout)
- Real-time validation improves user experience
- Mobile-responsive design essential for mobile commerce

---

## 🎯 Goals

### Primary Objectives
1. Create one-page checkout controller (backend)
2. Implement Twig templates (frontend structure)
3. Build JavaScript frontend (navigation, validation, AJAX)
4. Integrate Stripe Elements (payment form)
5. Implement real-time validation
6. Add mobile-responsive design
7. Handle payment processing and errors

### Success Criteria
- ✅ Checkout displays all sections on one page
- ✅ Navigation between sections works smoothly
- ✅ Stripe payment form embedded and functional
- ✅ Real-time validation shows errors immediately
- ✅ Mobile-responsive (< 768px width)
- ✅ Successful payment redirects to order confirmation
- ✅ Error handling shows user-friendly messages

---

## 🏗️ Architecture

### Checkout Flow

```
One-Page Checkout
│
├── Section 1: Basket Review
│   • Display items, quantities, prices
│   • Edit quantities (AJAX update)
│   • Remove items
│   • Show subtotal, tax, shipping estimate
│
├── Section 2: Shipping Address
│   • Address form fields
│   • Real-time validation
│   • Address autocomplete (optional)
│   • Save to address book checkbox
│
├── Section 3: Shipping Method
│   • Radio buttons for available methods
│   • Show cost and delivery time
│   • Auto-calculate shipping based on address
│
├── Section 4: Payment Method
│   • Stripe payment form (Stripe Elements)
│   • Embedded card input
│   • Payment method icons
│   • Save card checkbox (if enabled)
│
├── Section 5: Order Review
│   • Summary of all selections
│   • Terms & conditions checkbox
│   • Total amount (prominent)
│   • "Place Order" button
│
└── Processing
    • Create PaymentContract
    • Authorize payment with Stripe
    • Create order
    • Redirect to confirmation page
```

---

## 📝 Implementation Phases

### Phase 1: OnePageCheckoutController (TDD)

**Goal:** Backend controller handling checkout flow

**Test File:** `tests/Unit/Controller/OnePageCheckoutControllerTest.php`

**Test Specifications:**
```php
class OnePageCheckoutControllerTest extends TestCase
{
    // 1. Render checkout page
    public function testRendersCheckoutPage(): void
    {
        // Given: User with items in basket
        // When: render() called
        // Then: Returns checkout template with basket data
    }

    // 2. Update basket quantity (AJAX)
    public function testUpdatesBasketQuantity(): void
    {
        // Given: Item in basket, new quantity
        // When: updateQuantity() called via AJAX
        // Then: Returns updated basket JSON
    }

    // 3. Validate shipping address
    public function testValidatesShippingAddress(): void
    {
        // Given: Address form data
        // When: validateAddress() called
        // Then: Returns validation errors if any
    }

    // 4. Calculate shipping costs
    public function testCalculatesShippingCosts(): void
    {
        // Given: Shipping address and selected method
        // When: calculateShipping() called
        // Then: Returns shipping cost
    }

    // 5. Process payment submission
    public function testProcessesPaymentSubmission(): void
    {
        // Given: Complete checkout data + Stripe token
        // When: processPayment() called
        // Then: Creates contract, authorizes payment, creates order
    }

    // 6. Handle payment failure
    public function testHandlesPaymentFailure(): void
    {
        // Given: Invalid Stripe token
        // When: processPayment() called
        // Then: Returns error JSON
    }

    // 7. Prevent empty basket checkout
    public function testPreventsEmptyBasketCheckout(): void
    {
        // Given: Empty basket
        // When: render() called
        // Then: Redirects to basket page
    }
}
```

**Implementation:** `src/Controller/OnePageCheckoutController.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Service\CheckoutService;
use OxidSolutionCatalysts\Payments\Service\ModuleConfigurationService;

class OnePageCheckoutController extends FrontendController
{
    protected $_sThisTemplate = 'osc_stripe_onepage_checkout.tpl';

    public function __construct(
        private CheckoutService $checkoutService,
        private ModuleConfigurationService $configService
    ) {
        parent::__construct();
    }

    public function render(): string
    {
        parent::render();

        $basket = $this->getSession()->getBasket();
        if ($basket->getProductsCount() === 0) {
            Registry::getUtils()->redirect('index.php?cl=basket');
            return '';
        }

        $this->addTplParam('basket', $basket);
        $this->addTplParam('user', $this->getUser());
        $this->addTplParam('stripePublishableKey', $this->configService->getPublishableKey());
        $this->addTplParam('isTestMode', $this->configService->isTestMode());

        return $this->_sThisTemplate;
    }

    public function updateQuantity(): void
    {
        $itemKey = Registry::getRequest()->getRequestParameter('itemKey');
        $quantity = (int) Registry::getRequest()->getRequestParameter('quantity');

        $basket = $this->getSession()->getBasket();
        $basket->setItemAmount($itemKey, $quantity);

        Registry::getUtils()->setHeader('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'basket' => $this->formatBasketForJson($basket),
        ]);
        exit;
    }

    public function calculateShipping(): void
    {
        $addressData = Registry::getRequest()->getRequestParameter('address');
        $shippingMethod = Registry::getRequest()->getRequestParameter('shippingMethod');

        $shippingCost = $this->checkoutService->calculateShippingCost(
            $addressData,
            $shippingMethod
        );

        Registry::getUtils()->setHeader('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'shippingCost' => $shippingCost,
        ]);
        exit;
    }

    public function processPayment(): void
    {
        try {
            $checkoutData = $this->getCheckoutDataFromRequest();

            $result = $this->checkoutService->processCheckout($checkoutData);

            Registry::getUtils()->setHeader('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'orderId' => $result['orderId'],
                'redirectUrl' => 'index.php?cl=order&fnc=thankyou&ord=' . $result['orderId'],
            ]);
        } catch (\Exception $e) {
            Registry::getUtils()->setHeader('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
        exit;
    }

    private function formatBasketForJson($basket): array
    {
        return [
            'items' => $basket->getContents(),
            'totalGross' => $basket->getPrice()->getBruttoPrice(),
            'totalNet' => $basket->getPrice()->getNettoPrice(),
            'totalVat' => $basket->getPrice()->getVatValue(),
        ];
    }

    private function getCheckoutDataFromRequest(): array
    {
        $request = Registry::getRequest();

        return [
            'address' => $request->getRequestParameter('address'),
            'shippingMethod' => $request->getRequestParameter('shippingMethod'),
            'paymentToken' => $request->getRequestParameter('paymentToken'),
            'termsAccepted' => (bool) $request->getRequestParameter('termsAccepted'),
        ];
    }
}
```

---

### Phase 2: Twig Templates (Frontend Structure)

**Goal:** HTML structure for one-page checkout

**File:** `views/tpl/osc_stripe_onepage_checkout.tpl`

```twig
{# One-Page Checkout Template #}

<div class="osc-onepage-checkout" data-test-mode="{{ isTestMode ? 'true' : 'false' }}">

    {# Test Mode Banner #}
    {% if isTestMode %}
    <div class="test-mode-banner">
        <strong>TEST MODE:</strong> No real charges will be made.
        Use test card: 4242 4242 4242 4242
    </div>
    {% endif %}

    {# Progress Indicator #}
    <div class="checkout-progress">
        <div class="progress-step active" data-step="basket">
            <span class="step-number">1</span>
            <span class="step-label">Basket</span>
        </div>
        <div class="progress-step" data-step="address">
            <span class="step-number">2</span>
            <span class="step-label">Address</span>
        </div>
        <div class="progress-step" data-step="shipping">
            <span class="step-number">3</span>
            <span class="step-label">Shipping</span>
        </div>
        <div class="progress-step" data-step="payment">
            <span class="step-number">4</span>
            <span class="step-label">Payment</span>
        </div>
        <div class="progress-step" data-step="review">
            <span class="step-number">5</span>
            <span class="step-label">Review</span>
        </div>
    </div>

    {# Section 1: Basket #}
    <div class="checkout-section" id="section-basket">
        <h2>Your Basket</h2>
        <div class="basket-items">
            {% for item in basket.getContents() %}
            <div class="basket-item" data-item-key="{{ item.getKey() }}">
                <img src="{{ item.getIcon() }}" alt="{{ item.getTitle() }}">
                <div class="item-details">
                    <h3>{{ item.getTitle() }}</h3>
                    <p class="item-price">{{ item.getUnitPrice().getBruttoPrice() }} €</p>
                </div>
                <div class="item-quantity">
                    <button class="qty-minus">-</button>
                    <input type="number" value="{{ item.getAmount() }}" min="1" class="qty-input">
                    <button class="qty-plus">+</button>
                </div>
                <div class="item-total">
                    {{ item.getPrice().getBruttoPrice() }} €
                </div>
            </div>
            {% endfor %}
        </div>
        <div class="basket-summary">
            <div class="summary-row">
                <span>Subtotal:</span>
                <span class="subtotal">{{ basket.getPrice().getNettoPrice() }} €</span>
            </div>
            <div class="summary-row">
                <span>VAT:</span>
                <span class="vat">{{ basket.getPrice().getVatValue() }} €</span>
            </div>
            <div class="summary-row shipping-row" style="display:none;">
                <span>Shipping:</span>
                <span class="shipping-cost">0.00 €</span>
            </div>
            <div class="summary-row total-row">
                <span><strong>Total:</strong></span>
                <span class="total"><strong>{{ basket.getPrice().getBruttoPrice() }} €</strong></span>
            </div>
        </div>
        <button class="btn-next" data-next="address">Continue to Shipping Address</button>
    </div>

    {# Section 2: Shipping Address #}
    <div class="checkout-section hidden" id="section-address">
        <h2>Shipping Address</h2>
        <form id="address-form" class="address-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="firstName">First Name *</label>
                    <input type="text" id="firstName" name="firstName" required>
                    <span class="error-message"></span>
                </div>
                <div class="form-group">
                    <label for="lastName">Last Name *</label>
                    <input type="text" id="lastName" name="lastName" required>
                    <span class="error-message"></span>
                </div>
            </div>
            <div class="form-group">
                <label for="street">Street Address *</label>
                <input type="text" id="street" name="street" required>
                <span class="error-message"></span>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="zip">Postal Code *</label>
                    <input type="text" id="zip" name="zip" required>
                    <span class="error-message"></span>
                </div>
                <div class="form-group">
                    <label for="city">City *</label>
                    <input type="text" id="city" name="city" required>
                    <span class="error-message"></span>
                </div>
            </div>
            <div class="form-group">
                <label for="country">Country *</label>
                <select id="country" name="country" required>
                    <option value="">Select country...</option>
                    <option value="DE">Germany</option>
                    <option value="AT">Austria</option>
                    <option value="CH">Switzerland</option>
                </select>
                <span class="error-message"></span>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-back" data-back="basket">Back</button>
                <button type="button" class="btn-next" data-next="shipping">Continue to Shipping</button>
            </div>
        </form>
    </div>

    {# Section 3: Shipping Method #}
    <div class="checkout-section hidden" id="section-shipping">
        <h2>Shipping Method</h2>
        <div class="shipping-methods">
            <label class="shipping-method">
                <input type="radio" name="shippingMethod" value="standard" checked>
                <div class="method-details">
                    <strong>Standard Shipping</strong>
                    <p>Delivery in 3-5 business days</p>
                    <span class="method-cost">€ 4.90</span>
                </div>
            </label>
            <label class="shipping-method">
                <input type="radio" name="shippingMethod" value="express">
                <div class="method-details">
                    <strong>Express Shipping</strong>
                    <p>Delivery in 1-2 business days</p>
                    <span class="method-cost">€ 9.90</span>
                </div>
            </label>
        </div>
        <div class="form-actions">
            <button type="button" class="btn-back" data-back="address">Back</button>
            <button type="button" class="btn-next" data-next="payment">Continue to Payment</button>
        </div>
    </div>

    {# Section 4: Payment Method #}
    <div class="checkout-section hidden" id="section-payment">
        <h2>Payment Information</h2>
        <div class="payment-form">
            <div id="stripe-card-element" class="stripe-element">
                <!-- Stripe Elements will inject the card form here -->
            </div>
            <div id="stripe-errors" class="error-message"></div>
        </div>
        <div class="form-actions">
            <button type="button" class="btn-back" data-back="shipping">Back</button>
            <button type="button" class="btn-next" data-next="review">Continue to Review</button>
        </div>
    </div>

    {# Section 5: Order Review #}
    <div class="checkout-section hidden" id="section-review">
        <h2>Review Your Order</h2>

        <div class="review-section">
            <h3>Shipping Address</h3>
            <div id="review-address"></div>
        </div>

        <div class="review-section">
            <h3>Shipping Method</h3>
            <div id="review-shipping"></div>
        </div>

        <div class="review-section">
            <h3>Order Summary</h3>
            <div id="review-basket"></div>
        </div>

        <div class="review-section">
            <div class="form-group">
                <label>
                    <input type="checkbox" id="terms-checkbox" required>
                    I have read and accept the <a href="#" target="_blank">Terms & Conditions</a>
                </label>
            </div>
        </div>

        <div class="order-total">
            <span>Total Amount:</span>
            <span class="total-amount">{{ basket.getPrice().getBruttoPrice() }} €</span>
        </div>

        <div class="form-actions">
            <button type="button" class="btn-back" data-back="payment">Back</button>
            <button type="button" id="btn-place-order" class="btn-place-order" disabled>
                Place Order
            </button>
        </div>

        <div id="processing-overlay" class="processing-overlay hidden">
            <div class="spinner"></div>
            <p>Processing your payment...</p>
        </div>
    </div>

</div>

<script src="https://js.stripe.com/v3/"></script>
<script>
    window.stripePublishableKey = '{{ stripePublishableKey }}';
</script>
```

---

### Phase 3: JavaScript Frontend (Navigation & Validation)

**Goal:** Interactive checkout experience

**File:** `views/js/onepage-checkout.js`

```javascript
/**
 * One-Page Checkout JavaScript
 */

class OnePageCheckout {
    constructor() {
        this.currentStep = 'basket';
        this.checkoutData = {
            address: {},
            shippingMethod: 'standard',
            paymentToken: null,
        };

        this.stripe = Stripe(window.stripePublishableKey);
        this.cardElement = null;

        this.init();
    }

    init() {
        this.bindNavigationButtons();
        this.bindQuantityButtons();
        this.bindAddressForm();
        this.bindShippingMethods();
        this.initializeStripe();
        this.bindPlaceOrderButton();
        this.bindTermsCheckbox();
    }

    bindNavigationButtons() {
        document.querySelectorAll('.btn-next').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const nextStep = e.target.dataset.next;
                this.validateAndProceed(nextStep);
            });
        });

        document.querySelectorAll('.btn-back').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const backStep = e.target.dataset.back;
                this.navigateToStep(backStep);
            });
        });
    }

    bindQuantityButtons() {
        document.querySelectorAll('.qty-minus, .qty-plus').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const itemDiv = e.target.closest('.basket-item');
                const input = itemDiv.querySelector('.qty-input');
                const currentQty = parseInt(input.value);

                if (btn.classList.contains('qty-minus') && currentQty > 1) {
                    input.value = currentQty - 1;
                } else if (btn.classList.contains('qty-plus')) {
                    input.value = currentQty + 1;
                }

                this.updateBasketQuantity(itemDiv.dataset.itemKey, input.value);
            });
        });
    }

    async updateBasketQuantity(itemKey, quantity) {
        try {
            const response = await fetch('?cl=osc_stripe_onepage_checkout&fnc=updateQuantity', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ itemKey, quantity }),
            });

            const result = await response.json();
            if (result.success) {
                this.updateBasketSummary(result.basket);
            }
        } catch (error) {
            console.error('Failed to update quantity:', error);
        }
    }

    updateBasketSummary(basket) {
        document.querySelector('.subtotal').textContent = basket.totalNet + ' €';
        document.querySelector('.vat').textContent = basket.totalVat + ' €';
        document.querySelector('.total').textContent = basket.totalGross + ' €';
    }

    bindAddressForm() {
        const form = document.getElementById('address-form');
        form.querySelectorAll('input, select').forEach(field => {
            field.addEventListener('blur', () => this.validateAddressField(field));
            field.addEventListener('input', () => this.clearFieldError(field));
        });
    }

    validateAddressField(field) {
        const value = field.value.trim();
        const errorSpan = field.nextElementSibling;

        if (field.required && !value) {
            errorSpan.textContent = 'This field is required';
            field.classList.add('error');
            return false;
        }

        if (field.name === 'zip' && !/^\d{5}$/.test(value)) {
            errorSpan.textContent = 'Invalid postal code (5 digits required)';
            field.classList.add('error');
            return false;
        }

        field.classList.remove('error');
        errorSpan.textContent = '';
        return true;
    }

    clearFieldError(field) {
        field.classList.remove('error');
        field.nextElementSibling.textContent = '';
    }

    bindShippingMethods() {
        document.querySelectorAll('input[name="shippingMethod"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                this.checkoutData.shippingMethod = e.target.value;
                this.updateShippingCost();
            });
        });
    }

    async updateShippingCost() {
        const selectedMethod = document.querySelector('input[name="shippingMethod"]:checked');
        const cost = selectedMethod.closest('.shipping-method').querySelector('.method-cost').textContent;

        document.querySelector('.shipping-row').style.display = 'flex';
        document.querySelector('.shipping-cost').textContent = cost;

        // Recalculate total
        const subtotal = parseFloat(document.querySelector('.subtotal').textContent);
        const vat = parseFloat(document.querySelector('.vat').textContent);
        const shipping = parseFloat(cost.replace('€', '').trim());
        const total = subtotal + vat + shipping;

        document.querySelector('.total').textContent = total.toFixed(2) + ' €';
    }

    initializeStripe() {
        const elements = this.stripe.elements();
        this.cardElement = elements.create('card', {
            style: {
                base: {
                    fontSize: '16px',
                    color: '#32325d',
                    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                    '::placeholder': { color: '#aab7c4' },
                },
                invalid: { color: '#fa755a' },
            },
        });

        this.cardElement.mount('#stripe-card-element');

        this.cardElement.on('change', (event) => {
            const errorDiv = document.getElementById('stripe-errors');
            if (event.error) {
                errorDiv.textContent = event.error.message;
            } else {
                errorDiv.textContent = '';
            }
        });
    }

    bindPlaceOrderButton() {
        document.getElementById('btn-place-order').addEventListener('click', () => {
            this.processOrder();
        });
    }

    bindTermsCheckbox() {
        document.getElementById('terms-checkbox').addEventListener('change', (e) => {
            document.getElementById('btn-place-order').disabled = !e.target.checked;
        });
    }

    async validateAndProceed(nextStep) {
        let isValid = true;

        if (this.currentStep === 'address') {
            const form = document.getElementById('address-form');
            isValid = Array.from(form.querySelectorAll('input, select'))
                .every(field => this.validateAddressField(field));

            if (isValid) {
                this.checkoutData.address = this.collectAddressData();
            }
        }

        if (this.currentStep === 'payment') {
            // Stripe Elements validation happens on submission
            isValid = true;
        }

        if (isValid) {
            this.navigateToStep(nextStep);
            if (nextStep === 'review') {
                this.populateReviewSection();
            }
        }
    }

    navigateToStep(step) {
        // Hide all sections
        document.querySelectorAll('.checkout-section').forEach(section => {
            section.classList.add('hidden');
        });

        // Show target section
        document.getElementById(`section-${step}`).classList.remove('hidden');

        // Update progress indicator
        document.querySelectorAll('.progress-step').forEach(progressStep => {
            progressStep.classList.remove('active');
        });
        document.querySelector(`.progress-step[data-step="${step}"]`).classList.add('active');

        this.currentStep = step;
        window.scrollTo(0, 0);
    }

    collectAddressData() {
        const form = document.getElementById('address-form');
        return {
            firstName: form.firstName.value,
            lastName: form.lastName.value,
            street: form.street.value,
            zip: form.zip.value,
            city: form.city.value,
            country: form.country.value,
        };
    }

    populateReviewSection() {
        // Address
        const addr = this.checkoutData.address;
        document.getElementById('review-address').innerHTML = `
            ${addr.firstName} ${addr.lastName}<br>
            ${addr.street}<br>
            ${addr.zip} ${addr.city}<br>
            ${addr.country}
        `;

        // Shipping
        const shippingLabel = document.querySelector(`input[name="shippingMethod"]:checked`)
            .closest('.shipping-method').querySelector('strong').textContent;
        document.getElementById('review-shipping').textContent = shippingLabel;

        // Basket (already visible)
    }

    async processOrder() {
        const overlay = document.getElementById('processing-overlay');
        overlay.classList.remove('hidden');

        try {
            // Create Stripe payment method
            const { paymentMethod, error } = await this.stripe.createPaymentMethod({
                type: 'card',
                card: this.cardElement,
                billing_details: {
                    name: `${this.checkoutData.address.firstName} ${this.checkoutData.address.lastName}`,
                    address: {
                        line1: this.checkoutData.address.street,
                        postal_code: this.checkoutData.address.zip,
                        city: this.checkoutData.address.city,
                        country: this.checkoutData.address.country,
                    },
                },
            });

            if (error) {
                throw new Error(error.message);
            }

            this.checkoutData.paymentToken = paymentMethod.id;

            // Submit to backend
            const response = await fetch('?cl=osc_stripe_onepage_checkout&fnc=processPayment', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    address: this.checkoutData.address,
                    shippingMethod: this.checkoutData.shippingMethod,
                    paymentToken: this.checkoutData.paymentToken,
                    termsAccepted: true,
                }),
            });

            const result = await response.json();

            if (result.success) {
                window.location.href = result.redirectUrl;
            } else {
                throw new Error(result.error || 'Payment failed');
            }

        } catch (error) {
            overlay.classList.add('hidden');
            alert('Payment failed: ' + error.message);
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    new OnePageCheckout();
});
```

---

## Phase 4: CSS Styling (Responsive Design)

**File:** `views/css/onepage-checkout.css`

```css
/* One-Page Checkout Styles */

.osc-onepage-checkout {
    max-width: 900px;
    margin: 0 auto;
    padding: 20px;
}

.test-mode-banner {
    background: #fff3cd;
    border: 1px solid #ffc107;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
    text-align: center;
}

/* Progress Indicator */
.checkout-progress {
    display: flex;
    justify-content: space-between;
    margin-bottom: 40px;
    position: relative;
}

.checkout-progress::before {
    content: '';
    position: absolute;
    top: 20px;
    left: 10%;
    right: 10%;
    height: 2px;
    background: #e0e0e0;
    z-index: -1;
}

.progress-step {
    text-align: center;
    flex: 1;
}

.progress-step .step-number {
    display: inline-block;
    width: 40px;
    height: 40px;
    line-height: 40px;
    border-radius: 50%;
    background: #e0e0e0;
    color: #666;
    margin-bottom: 8px;
}

.progress-step.active .step-number {
    background: #4caf50;
    color: white;
}

.progress-step .step-label {
    display: block;
    font-size: 12px;
    color: #666;
}

/* Checkout Sections */
.checkout-section {
    background: white;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.checkout-section.hidden {
    display: none;
}

.checkout-section h2 {
    margin-top: 0;
    margin-bottom: 24px;
    font-size: 24px;
    color: #333;
}

/* Basket Items */
.basket-item {
    display: flex;
    align-items: center;
    padding: 15px 0;
    border-bottom: 1px solid #e0e0e0;
}

.basket-item img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    margin-right: 15px;
}

.item-details {
    flex: 1;
}

.item-quantity {
    display: flex;
    align-items: center;
    margin: 0 20px;
}

.qty-minus, .qty-plus {
    width: 30px;
    height: 30px;
    border: 1px solid #ddd;
    background: white;
    cursor: pointer;
}

.qty-input {
    width: 50px;
    height: 30px;
    text-align: center;
    border: 1px solid #ddd;
    margin: 0 5px;
}

.basket-summary {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #e0e0e0;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
}

.total-row {
    font-size: 20px;
    border-top: 2px solid #333;
    padding-top: 15px;
    margin-top: 10px;
}

/* Form Styles */
.address-form .form-row {
    display: flex;
    gap: 15px;
}

.form-group {
    margin-bottom: 20px;
    flex: 1;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-group input.error {
    border-color: #f44336;
}

.error-message {
    display: block;
    color: #f44336;
    font-size: 12px;
    margin-top: 5px;
    min-height: 18px;
}

/* Shipping Methods */
.shipping-method {
    display: block;
    padding: 15px;
    border: 2px solid #e0e0e0;
    border-radius: 4px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: border-color 0.2s;
}

.shipping-method:hover {
    border-color: #4caf50;
}

.shipping-method input[type="radio"] {
    margin-right: 10px;
}

.shipping-method input[type="radio"]:checked ~ .method-details {
    color: #4caf50;
}

.method-details {
    display: inline-block;
    width: calc(100% - 30px);
}

.method-cost {
    float: right;
    font-weight: bold;
}

/* Stripe Elements */
.stripe-element {
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: white;
}

/* Buttons */
.form-actions {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
}

.btn-back,
.btn-next,
.btn-place-order {
    padding: 12px 30px;
    border: none;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-back {
    background: #f5f5f5;
    color: #333;
}

.btn-next,
.btn-place-order {
    background: #4caf50;
    color: white;
}

.btn-place-order:disabled {
    background: #ccc;
    cursor: not-allowed;
}

/* Processing Overlay */
.processing-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.7);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: white;
    z-index: 9999;
}

.processing-overlay.hidden {
    display: none;
}

.spinner {
    border: 4px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .osc-onepage-checkout {
        padding: 10px;
    }

    .checkout-section {
        padding: 20px 15px;
    }

    .address-form .form-row {
        flex-direction: column;
        gap: 0;
    }

    .basket-item {
        flex-wrap: wrap;
    }

    .basket-item img {
        width: 60px;
        height: 60px;
    }

    .item-quantity {
        margin: 10px 0;
    }

    .progress-step .step-label {
        display: none;
    }

    .form-actions {
        flex-direction: column;
        gap: 10px;
    }

    .btn-back,
    .btn-next,
    .btn-place-order {
        width: 100%;
    }
}
```

---

## 📊 Test Summary

### Controller Tests (7 tests)
1. Renders checkout page
2. Updates basket quantity
3. Validates shipping address
4. Calculates shipping costs
5. Processes payment submission
6. Handles payment failure
7. Prevents empty basket checkout

### JavaScript Tests (Codeception/Cypress - Optional)
1. Navigation between sections
2. Quantity increment/decrement
3. Address validation
4. Shipping method selection
5. Stripe Elements initialization
6. Order submission flow

**Total: 7+ unit tests, 6+ E2E tests**

---

## ✅ Acceptance Criteria

### Functional Requirements
- [ ] All 5 sections displayed on one page
- [ ] Navigation between sections works
- [ ] Basket quantity updates via AJAX
- [ ] Address validation shows errors
- [ ] Stripe payment form embedded
- [ ] Order submission creates contract
- [ ] Success redirects to confirmation

### Non-Functional Requirements
- [ ] Mobile-responsive (< 768px)
- [ ] Accessible (keyboard navigation)
- [ ] Fast page load (< 2s)
- [ ] Smooth animations between sections
- [ ] Clear error messages

---

## 📁 Files to Create

### Source Files (2)
```
src/Controller/
└── OnePageCheckoutController.php              (200 lines)

src/Service/
└── CheckoutService.php                        (150 lines)
```

### Frontend Files (3)
```
views/tpl/
└── osc_stripe_onepage_checkout.tpl            (350 lines)

views/js/
└── onepage-checkout.js                        (400 lines)

views/css/
└── onepage-checkout.css                       (350 lines)
```

### Test Files (2)
```
tests/Unit/Controller/
└── OnePageCheckoutControllerTest.php          (180 lines)

tests/E2E/
└── CheckoutFlowTest.php                       (150 lines)
```

**Total Lines:** ~1,780 (source: ~350, frontend: ~1,100, tests: ~330)

---

## 🚀 Implementation Order

### Day 1-2 (8 hours)
1. Phase 1: OnePageCheckoutController (4 hours)
2. CheckoutService backend logic (2 hours)
3. Write controller tests (2 hours)

### Day 3-4 (8-12 hours)
1. Phase 2: Twig templates (4 hours)
2. Phase 3: JavaScript frontend (4 hours)
3. Phase 4: CSS styling (2 hours)
4. Mobile responsive testing (2 hours)

---

## 📋 Definition of Done

- [x] OnePageCheckoutController implemented
- [x] Twig templates created
- [x] JavaScript frontend functional
- [x] CSS styling responsive
- [x] All 7+ unit tests passing
- [x] Manual testing complete
- [x] Mobile-responsive verified
- [x] Integration with Stripe tested

---

**Estimated Completion:** 16-20 hours (2-3 days)
**Priority:** 🟡 MEDIUM (User Experience)
**Next Ticket:** TICKET-13 (Capture & Refund Operations)

*Created: 2025-10-30*
*Version: 1.0*
