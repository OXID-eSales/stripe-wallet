# SPRINT-4 TICKET-18: Documentation & Developer Experience

**Priority:** 🟡 MEDIUM
**Estimated Effort:** 6-8 hours
**Sprint:** Sprint 4 (Advanced Features)
**Depends On:** All previous tickets
**Blocks:** Developer onboarding, Support efficiency

---

## 📋 Overview

Create comprehensive user and developer documentation, including installation guides, configuration guides, API reference, troubleshooting guides, and code examples. Improve developer experience with CLI tools and debugging utilities.

**Why This Matters:**
- Reduces support burden
- Accelerates developer onboarding
- Enables self-service problem solving
- Improves module adoption

---

## 🎯 Goals

### Primary Objectives
1. Installation guide (step-by-step)
2. Configuration guide (all settings explained)
3. Developer quick start guide
4. API reference documentation (auto-generated)
5. Troubleshooting guide (common issues)
6. Migration guide (from other payment modules)
7. Code examples and recipes
8. Video tutorials (optional)

### Success Criteria
- ✅ All documentation complete and accurate
- ✅ Quick start guide works from scratch
- ✅ API documentation auto-generated
- ✅ Troubleshooting covers 80% of common issues
- ✅ Code examples for all major features

---

## 📝 Documentation Structure

### 1. Installation Guide

**File:** `docs/01-installation.md`

```markdown
# Installation Guide

## Requirements

- OXID eShop 7.0 or higher
- PHP 8.2 or higher
- MySQL 8.0 or higher
- Composer 2.x
- Stripe account (test mode for development)

## Step 1: Install via Composer

```bash
composer require oxid-solution-catalysts/stripe-payment:^1.0
```

## Step 2: Activate Module

```bash
vendor/bin/oe-console oe:module:activate osc_stripe_payment
```

## Step 3: Run Database Migrations

```bash
vendor/bin/oe-console oe:module:apply-configuration
```

## Step 4: Configure Stripe API Keys

1. Log in to OXID admin panel
2. Navigate to Extensions > Modules > Stripe Payment
3. Enter your Stripe test keys:
   - Test Publishable Key: `pk_test_...`
   - Test Secret Key: `sk_test_...`
   - Test Webhook Secret: `whsec_...`
4. Enable "Test Mode"
5. Save configuration

## Step 5: Configure Webhook

1. In Stripe Dashboard, go to Developers > Webhooks
2. Click "Add endpoint"
3. Enter URL: `https://your-shop.com/index.php?cl=osc_stripe_webhook`
4. Select events:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `charge.succeeded`
   - `charge.refunded`
5. Copy webhook signing secret and add to module configuration

## Step 6: Test Installation

```bash
# Run tests
vendor/bin/phpunit --testsuite unit

# Test payment in shop frontend
# Use Stripe test card: 4242 4242 4242 4242
```

## Troubleshooting Installation

### Module not appearing in admin

```bash
# Clear cache
rm -rf tmp/*
# Regenerate views
vendor/bin/oe-console oe:views:update
```

### Database migration failed

```bash
# Check migration status
vendor/bin/oe-console oe:migrations:status

# Rollback and retry
vendor/bin/oe-console oe:migrations:rollback
vendor/bin/oe-console oe:migrations:migrate
```
```

---

### 2. Configuration Guide

**File:** `docs/02-configuration.md`

```markdown
# Configuration Guide

## Module Settings

### API Configuration

| Setting | Description | Default | Required |
|---------|-------------|---------|----------|
| `osc_stripe_test_mode` | Enable test mode | `true` | Yes |
| `osc_stripe_test_publishable_key` | Test publishable key | - | Yes (test mode) |
| `osc_stripe_test_secret_key` | Test secret key | - | Yes (test mode) |
| `osc_stripe_test_webhook_secret` | Test webhook secret | - | Yes |
| `osc_stripe_live_publishable_key` | Live publishable key | - | Yes (live mode) |
| `osc_stripe_live_secret_key` | Live secret key | - | Yes (live mode) |
| `osc_stripe_live_webhook_secret` | Live webhook secret | - | Yes (live mode) |

### Payment Method Configuration

| Setting | Description | Options | Default |
|---------|-------------|---------|---------|
| `osc_stripe_payment_methods` | Enabled payment methods | `card`, `sepa_debit`, `giropay` | `['card']` |
| `osc_stripe_capture_method` | When to capture payment | `automatic`, `manual` | `automatic` |

### Capture Methods Explained

**Automatic Capture:**
- Payment captured immediately after authorization
- Best for digital products or immediate fulfillment
- Lower fraud risk

**Manual Capture:**
- Payment authorized but not captured
- Merchant must manually capture (within 7 days)
- Best for physical products (capture at shipping)
- Higher flexibility, better cash flow management

## Test Mode vs Live Mode

### Test Mode
- Uses test API keys (`pk_test_...`, `sk_test_...`)
- No real charges processed
- Use Stripe test cards
- Clearly indicated in checkout

### Live Mode
- Uses live API keys (`pk_live_...`, `sk_live_...`)
- Real charges processed
- Must pass Stripe verification
- PCI compliance required

## Going Live Checklist

- [ ] Stripe account verified
- [ ] Live API keys configured
- [ ] Webhook endpoint configured in live mode
- [ ] SSL certificate installed (HTTPS)
- [ ] Test mode disabled
- [ ] Terms & conditions page linked
- [ ] Privacy policy updated
- [ ] PCI compliance questionnaire completed
```

---

### 3. Developer Quick Start

**File:** `docs/03-quick-start.md`

```markdown
# Developer Quick Start

Get up and running in 15 minutes.

## 1. Clone and Install

```bash
git clone https://github.com/oxid-solution-catalysts/stripe-payment
cd stripe-payment
composer install
```

## 2. Set Up Test Environment

```bash
# Copy environment template
cp .env.example .env

# Edit .env and add your Stripe test keys
STRIPE_TEST_SECRET_KEY=sk_test_...
STRIPE_TEST_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

## 3. Run Tests

```bash
# Run unit tests
vendor/bin/phpunit --testsuite unit

# Run integration tests
vendor/bin/phpunit --testsuite integration

# Check code coverage
vendor/bin/phpunit --coverage-html coverage
```

## 4. Test Payment Flow

```bash
# Start development server
docker-compose up

# In browser:
http://localhost:8000

# Use Stripe test cards:
# - Success: 4242 4242 4242 4242
# - Decline: 4000 0000 0000 0002
# - 3D Secure: 4000 0027 6000 3184
```

## 5. Test Webhooks Locally

```bash
# Install Stripe CLI
brew install stripe/stripe-cli/stripe

# Forward webhooks to local dev
stripe listen --forward-to http://localhost:8000/webhook/stripe

# Trigger test webhook
stripe trigger payment_intent.succeeded
```

## Example: Create Payment Programmatically

```php
<?php

use OxidSolutionCatalysts\Payments\Service\PaymentService;

$paymentService = $container->get(PaymentService::class);

$result = $paymentService->initiatePayment(
    userId: 'user_123',
    amount: 99.99,
    currency: 'EUR',
    basket: [
        'items' => [
            ['productId' => 'prod1', 'quantity' => 2, 'price' => 49.99],
        ],
        'totalGross' => 99.99,
    ],
    returnUrl: 'https://shop.com/payment/success',
    cancelUrl: 'https://shop.com/payment/cancel'
);

// Result contains:
// - contractId
// - clientSecret (for Stripe Elements)
```
```

---

### 4. API Reference (Auto-Generated)

**File:** `docs/04-api-reference.md`

```markdown
# API Reference

*Auto-generated from PHPDoc comments*

## PaymentService

### `initiatePayment()`

Initiates a new payment and creates a payment contract.

**Parameters:**
- `string $userId` - User ID
- `float $amount` - Payment amount
- `string $currency` - Currency code (ISO 4217)
- `array $basket` - Basket data
- `string $returnUrl` - Success redirect URL
- `string $cancelUrl` - Cancel redirect URL

**Returns:** `array`
```php
[
    'contractId' => string,
    'clientSecret' => string,
    'requiresAction' => bool
]
```

**Throws:**
- `\DomainException` - If basket is empty
- `\RuntimeException` - If Stripe API fails

**Example:**
```php
$result = $paymentService->initiatePayment(
    userId: 'user123',
    amount: 100.00,
    currency: 'EUR',
    basket: $basketData,
    returnUrl: '/payment/success',
    cancelUrl: '/payment/cancel'
);
```

---

### `capturePayment()`

Captures an authorized payment.

**Parameters:**
- `string $contractId` - Payment contract ID
- `?float $amount` - Amount to capture (null = full)

**Returns:** `array`
```php
[
    'success' => bool,
    'captureId' => string,
    'amount' => float
]
```

---

## Events

### `PaymentInitiatedEvent`

Dispatched when payment is initiated.

**Properties:**
- `EventContext $context`
- `string $provider` - Payment provider name
- `float $amount`
- `string $currency`

**Listeners:**
- `ContractCreationHandler`
- `FraudCheckHandler`
- `StockReservationHandler`

---

## Configuration

### `ModuleConfigurationService`

### `isTestMode(): bool`

Returns whether module is in test mode.

### `getSecretKey(): string`

Returns Stripe secret key (test or live based on mode).

### `getWebhookUrl(): string`

Returns configured webhook URL.
```

---

### 5. Troubleshooting Guide

**File:** `docs/05-troubleshooting.md`

```markdown
# Troubleshooting Guide

## Common Issues

### 1. "Payment failed: Invalid API key"

**Cause:** Wrong API key or test/live mode mismatch

**Solution:**
```bash
# Check configuration
vendor/bin/oe-console config:get osc_stripe_test_mode
vendor/bin/oe-console config:get osc_stripe_test_secret_key

# Verify key format:
# Test keys start with: sk_test_
# Live keys start with: sk_live_
```

---

### 2. "Webhook signature verification failed"

**Cause:** Incorrect webhook secret

**Solution:**
1. In Stripe Dashboard, go to Webhooks
2. Click on your endpoint
3. Click "Reveal" next to signing secret
4. Copy and paste into module configuration
5. Save and test

---

### 3. "No such payment_intent: pi_..."

**Cause:** Payment intent not found or expired

**Debug steps:**
```bash
# Check Stripe logs
https://dashboard.stripe.com/test/logs

# Check application logs
tail -f var/log/oxideshop.log | grep stripe

# Verify payment intent ID matches
```

---

### 4. "Contract not found for payment intent"

**Cause:** Webhook received before contract saved, or contract ID mismatch

**Solution:**
- Enable debug logging
- Check webhook timing
- Verify providerOrderId is saved correctly

---

### 5. Payment stuck in "Pending" status

**Cause:** Webhook not received or processed

**Debug steps:**
1. Check webhook logs in Stripe Dashboard
2. Verify webhook endpoint accessible (not blocked by firewall)
3. Check webhook processing logs
4. Manually dispatch webhook via Stripe CLI:
   ```bash
   stripe trigger payment_intent.succeeded
   ```

---

## Debugging Tools

### Enable Debug Logging

```php
// In .env
LOG_LEVEL=debug
```

### Check Contract State

```php
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepository;

$repository = $container->get(ContractRepository::class);
$contract = $repository->findById('contract_123');

echo "State: " . $contract->getState()->getValue() . "\n";
echo "Conditions fulfilled: " . $contract->areAllConditionsFulfilled() . "\n";
```

### Test Stripe Connection

```bash
vendor/bin/oe-console stripe:test-connection
```
```

---

### 6. Code Examples & Recipes

**File:** `docs/06-examples.md`

```markdown
# Code Examples

## Custom Event Handler

```php
<?php

namespace MyShop\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentInitiatedEvent;

class CustomPaymentHandler implements HandlerInterface
{
    public function handle(PaymentInitiatedEvent $event): void
    {
        $context = $event->getContext();
        $amount = $event->getAmount();

        // Custom logic: Send notification for high-value orders
        if ($amount > 1000.00) {
            $this->sendAdminNotification("High-value order: €{$amount}");
        }
    }
}
```

---

## Add Custom Fraud Rule

```php
<?php

use OxidSolutionCatalysts\Payments\Service\FraudScoringService;

class CustomFraudScoring extends FraudScoringService
{
    public function calculateRiskScore(array $data): int
    {
        $score = parent::calculateRiskScore($data);

        // Custom rule: Flag orders from blacklisted countries
        if (in_array($data['country'], ['XX', 'YY'])) {
            $score += 50;
        }

        return $score;
    }
}
```

---

## Listen to Webhook Events

```php
<?php

use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\WebhookReceivedEvent;

$dispatcher->addListener(
    WebhookReceivedEvent::class,
    function (WebhookReceivedEvent $event) {
        if ($event->getEventType() === 'payment_intent.succeeded') {
            // Custom post-payment logic
            $this->sendConfirmationEmail($event->getContext()->get('userId'));
        }
    }
);
```
```

---

## 📊 Documentation Deliverables

### User Documentation (6 files)
1. Installation Guide
2. Configuration Guide
3. User Manual (admin operations)
4. FAQ
5. Troubleshooting Guide
6. Migration Guide

### Developer Documentation (6 files)
1. Quick Start Guide
2. Architecture Overview (already exists ✅)
3. API Reference (auto-generated)
4. Code Examples
5. Extension Points
6. Testing Guide

### Video Tutorials (Optional, 3 videos)
1. Installation & Configuration (5 min)
2. Complete Payment Flow Demo (10 min)
3. Troubleshooting Common Issues (8 min)

---

## ✅ Acceptance Criteria

### Documentation Quality
- [ ] All guides complete and accurate
- [ ] Code examples tested and working
- [ ] Screenshots included where helpful
- [ ] Search functionality implemented

### Developer Experience
- [ ] Quick start works from scratch
- [ ] API reference auto-generated
- [ ] CLI tools documented
- [ ] Debugging guide comprehensive

---

## 📁 Files to Create

### Documentation Files (12)
```
docs/user/
├── 01-installation.md                         (500 lines)
├── 02-configuration.md                        (400 lines)
├── 03-user-manual.md                          (600 lines)
├── 04-faq.md                                  (300 lines)
├── 05-troubleshooting.md                      (400 lines)
└── 06-migration.md                            (300 lines)

docs/developer/
├── 01-quick-start.md                          (300 lines)
├── 02-api-reference.md                        (800 lines)
├── 03-examples.md                             (500 lines)
├── 04-extension-points.md                     (400 lines)
├── 05-testing.md                              (300 lines)
└── 06-cli-tools.md                            (200 lines)
```

**Total Lines:** ~5,000 (documentation)

---

## 🚀 Implementation Order

### Day 1 (4 hours)
1. Installation & Configuration guides (2 hours)
2. Quick start guide (1 hour)
3. Test all guides work (1 hour)

### Day 2 (2-4 hours)
1. Troubleshooting guide (1.5 hours)
2. Code examples (1 hour)
3. API reference generation (0.5-1.5 hours)

---

## 📋 Definition of Done

- [x] All user guides written
- [x] All developer guides written
- [x] Quick start tested from scratch
- [x] API reference generated
- [x] Code examples tested
- [x] FAQ covers common questions
- [x] Troubleshooting guide comprehensive

---

**Estimated Completion:** 6-8 hours (1 day)
**Priority:** 🟡 MEDIUM (Developer Experience)
**Blocks:** None (Final ticket)

*Created: 2025-10-30*
*Version: 1.0*
