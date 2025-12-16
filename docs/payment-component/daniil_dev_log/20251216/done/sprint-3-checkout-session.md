# Sprint 3: Modify CheckoutSessionService for capture_method

**Status:** PENDING
**Priority:** HIGH
**Estimated Effort:** 2 hours
**Depends On:** Sprint 2 (Module Configuration)

---

## Objective

Modify the `CheckoutSessionService` to pass the `capture_method` parameter to Stripe based on the module configuration.

---

## Background

When creating a Stripe Checkout Session, the `payment_intent_data.capture_method` parameter determines whether funds are captured immediately or held for manual capture.

**Stripe API Reference:**
```php
$session = $stripe->checkout->sessions->create([
    'mode' => 'payment',
    'payment_intent_data' => [
        'capture_method' => 'manual',  // or 'automatic'
    ],
    // ...
]);
```

---

## Tasks

### 1. Update CheckoutSessionService

**File:** `src/Stripe/Service/CheckoutSessionService.php`

Inject `CaptureConfigurationService` and use it when building session parameters:

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

// ... existing imports ...

class CheckoutSessionService
{
    public function __construct(
        // ... existing dependencies ...
        private readonly CaptureConfigurationService $captureConfigService,
    ) {
    }

    public function createCheckoutSession(/* ... */): Session
    {
        $params = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => $lineItems,
            'payment_intent_data' => [
                'capture_method' => $this->captureConfigService->getStripeCaptureMethod(),
                'metadata' => $metadata,
            ],
            // ... other params ...
        ];

        // ... rest of method ...
    }
}
```

### 2. Store Capture Mode in Contract Metadata

When creating the contract, store the capture mode so we know how this payment was configured:

```php
// In contract creation
$metadata = [
    'capture_mode' => $this->captureConfigService->getCaptureMode(),
    // ... other metadata ...
];
```

### 3. Update Existing Tests

**File:** `tests/Unit/Stripe/Service/CheckoutSessionServiceTest.php`

Add tests for capture_method parameter:

```php
public function testCheckoutSessionIncludesCaptureMethodFromConfig(): void
{
    // Arrange
    $captureConfigService = $this->createMock(CaptureConfigurationService::class);
    $captureConfigService->method('getStripeCaptureMethod')
        ->willReturn('manual');

    $stripeClient = $this->createMock(StripeClient::class);
    $sessionsMock = $this->createMock(SessionService::class);

    $sessionsMock->expects($this->once())
        ->method('create')
        ->with($this->callback(function (array $params) {
            return isset($params['payment_intent_data']['capture_method'])
                && $params['payment_intent_data']['capture_method'] === 'manual';
        }))
        ->willReturn($this->createMockSession());

    $stripeClient->checkout = new \stdClass();
    $stripeClient->checkout->sessions = $sessionsMock;

    // ... create service and call method ...
}

public function testCheckoutSessionUsesAutomaticCaptureByDefault(): void
{
    $captureConfigService = $this->createMock(CaptureConfigurationService::class);
    $captureConfigService->method('getStripeCaptureMethod')
        ->willReturn('automatic');

    // ... assert 'automatic' is passed to Stripe ...
}
```

### 4. Update DI Configuration

**File:** `services.yaml`

Ensure `CaptureConfigurationService` is injected into `CheckoutSessionService`:

```yaml
OxidSolutionCatalysts\Payments\Stripe\Service\CheckoutSessionService:
    arguments:
        # ... existing arguments ...
        - '@OxidSolutionCatalysts\Payments\Stripe\Service\CaptureConfigurationService'
```

---

## Stripe Checkout Session Parameters

### For Automatic Capture (default)

```php
[
    'mode' => 'payment',
    'payment_intent_data' => [
        'capture_method' => 'automatic',
    ],
]
```

Result: `PaymentIntent.status` becomes `succeeded` immediately after payment.

### For Manual Capture

```php
[
    'mode' => 'payment',
    'payment_intent_data' => [
        'capture_method' => 'manual',
    ],
]
```

Result: `PaymentIntent.status` becomes `requires_capture` after authorization.

---

## Acceptance Criteria

- [ ] `CheckoutSessionService` injects `CaptureConfigurationService`
- [ ] `capture_method` is passed to Stripe based on configuration
- [ ] Contract metadata includes `capture_mode` for historical tracking
- [ ] Automatic capture mode sends `capture_method: 'automatic'`
- [ ] Manual capture mode sends `capture_method: 'manual'`
- [ ] Existing tests still pass
- [ ] New tests verify capture_method parameter
- [ ] PHPStan level 6 passes
- [ ] PSR-12 code style passes

---

## Test Commands

```bash
# Run CheckoutSessionService tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/Service/CheckoutSessionServiceTest.php

# Run all unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
```

---

## Integration Test Plan

After implementation, manually verify in Stripe Dashboard:

1. Set capture mode to "Manual" in admin
2. Create a test order with Stripe Checkout
3. Complete payment with test card `4242 4242 4242 4242`
4. Check Stripe Dashboard: PaymentIntent should show status `requires_capture`
5. Payment should NOT be captured automatically

---

## Notes

- The `capture_method` is set at PaymentIntent creation time and cannot be changed later
- If a payment is created with `automatic`, it will be captured immediately
- If a payment is created with `manual`, it must be explicitly captured via API
- Authorizations typically expire after 7 days (can be extended to 31 days for some card types)
