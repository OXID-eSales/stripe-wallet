# Extending the Stripe Module

This guide covers the six extension mechanisms available to developers building on top of the stripe module. Each pattern is illustrated with concrete code examples using subscriptions and bookings as use cases.

Prerequisites: Read [Module Principles](01-module-principles.md) and [Payment-Component Dependency](02-payment-component-dependency.md) first.

---

## Extension Points Overview

| # | Mechanism | When to Use | Complexity | Example Use Case |
|---|-----------|------------|------------|-----------------|
| 1 | Event Handler | React to contract/payment lifecycle events | Low | Subscription validation on payment |
| 2 | Service Override via DI | Replace or extend a service implementation | Medium | Custom Checkout Session parameters |
| 3 | Contract Metadata | Store custom data on contracts | Low | Booking information, subscription IDs |
| 4 | Webhook Handler Addition | Handle new Stripe webhook event types | Medium | `invoice.paid` for subscriptions |
| 5 | Controller Extension | Add UI or logic to existing pages | Medium | Booking calendar on payment page |
| 6 | Adapter Decoration | Wrap the StripeAdapter with additional behavior | High | Marketplace headers, logging |

---

## Pattern 1: Event Handler

**Use case:** Validate that a subscription plan exists before allowing payment.

The simplest extension point. You write a handler class, register it with a `payment.event_handler` tag, and it participates in the event chain.

### Handler Class

```php
<?php

declare(strict_types=1);

namespace YourVendor\Subscription\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use YourVendor\Subscription\Service\SubscriptionPlanServiceInterface;

class SubscriptionValidationHandler implements HandlerInterface
{
    public function __construct(
        private readonly SubscriptionPlanServiceInterface $planService
    ) {}

    public static function getHandledEventClass(): string
    {
        return StripeCheckoutSessionRequestEvent::class;
    }

    public function getPriority(): int
    {
        // Run AFTER contract creation (100) but BEFORE session creation (0)
        return 50;
    }

    public function handle(object $event): void
    {
        $context = $event->getContext();
        $planId = $context->get('subscriptionPlanId');

        if ($planId === null) {
            return; // Not a subscription checkout — skip
        }

        $plan = $this->planService->findById($planId);
        if ($plan === null) {
            throw new \RuntimeException("Subscription plan not found: {$planId}");
        }

        // Store validated plan data in context for downstream handlers
        $context->set('subscriptionPlan', $plan);

        // Store plan ID in contract metadata for persistence
        $contract = $context->getContract();
        if ($contract !== null) {
            $contract->setMetadata('subscription_plan_id', $planId);
            $contract->setMetadata('subscription_interval', $plan->getInterval());
        }
    }
}
```

### services.yaml Registration

```yaml
# your-module/services.yaml

YourVendor\Subscription\EventSystem\Handler\SubscriptionValidationHandler:
    tags:
        - { name: payment.event_handler, priority: 50 }
```

### Unit Test

```php
<?php

declare(strict_types=1);

namespace YourVendor\Subscription\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use PHPUnit\Framework\TestCase;
use YourVendor\Subscription\EventSystem\Handler\SubscriptionValidationHandler;
use YourVendor\Subscription\Service\SubscriptionPlanServiceInterface;

class SubscriptionValidationHandlerTest extends TestCase
{
    public function testSkipsNonSubscriptionCheckout(): void
    {
        $planService = $this->createMock(SubscriptionPlanServiceInterface::class);
        $planService->expects($this->never())->method('findById');

        $handler = new SubscriptionValidationHandler($planService);

        $context = new EventContext();
        // No 'subscriptionPlanId' in context
        $event = $this->createMock(StripeCheckoutSessionRequestEvent::class);
        $event->method('getContext')->willReturn($context);

        $handler->handle($event);
    }

    public function testThrowsForInvalidPlan(): void
    {
        $planService = $this->createMock(SubscriptionPlanServiceInterface::class);
        $planService->method('findById')->willReturn(null);

        $handler = new SubscriptionValidationHandler($planService);

        $context = new EventContext(['subscriptionPlanId' => 'plan_invalid']);
        $event = $this->createMock(StripeCheckoutSessionRequestEvent::class);
        $event->method('getContext')->willReturn($context);

        $this->expectException(\RuntimeException::class);
        $handler->handle($event);
    }

    public function testStoresValidatedPlanInContext(): void
    {
        $plan = new \stdClass();
        $planService = $this->createMock(SubscriptionPlanServiceInterface::class);
        $planService->method('findById')->willReturn($plan);

        $handler = new SubscriptionValidationHandler($planService);

        $context = new EventContext(['subscriptionPlanId' => 'plan_monthly']);
        $event = $this->createMock(StripeCheckoutSessionRequestEvent::class);
        $event->method('getContext')->willReturn($context);

        $handler->handle($event);

        $this->assertSame($plan, $context->get('subscriptionPlan'));
    }
}
```

### Priority Guidelines

| Priority Range | Purpose |
|---------------|---------|
| 100+ | Contract creation, critical setup (reserved by core) |
| 80-99 | Order creation, authorization (reserved by core) |
| 50-79 | Validation, enrichment (your extensions) |
| 1-49 | Post-processing, notifications (your extensions) |
| 0 | Default — session creation, capture, refund (core defaults) |

---

## Pattern 2: Service Override via DI

**Use case:** Customize the Stripe Checkout Session parameters (e.g., add subscription mode, custom line items).

Override a service binding in your module's `services.yaml` to replace or decorate an existing implementation.

### Custom Service Class

```php
<?php

declare(strict_types=1);

namespace YourVendor\Subscription\Service;

use OxidEsales\Payments\Stripe\Service\CheckoutSessionService;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;

class SubscriptionCheckoutSessionService extends CheckoutSessionService
{
    public function createSessionParams(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): array {
        $params = parent::createSessionParams($contract, $context);

        $planId = $contract->getMetadata('subscription_plan_id');
        if ($planId !== null) {
            // Switch to subscription mode
            $params['mode'] = 'subscription';
            $params['line_items'] = [
                [
                    'price' => $planId,
                    'quantity' => 1,
                ],
            ];
            // Remove one-time payment params
            unset($params['payment_intent_data']);
        }

        return $params;
    }
}
```

### services.yaml Override

```yaml
# your-module/services.yaml

# Override the interface binding — your class replaces the original
OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface:
    class: YourVendor\Subscription\Service\SubscriptionCheckoutSessionService
```

**Important:** Service overrides depend on OXID's module loading order. Your module's `services.yaml` must load after the stripe module's. This is controlled by module dependencies in `composer.json`.

---

## Pattern 3: Contract Metadata

**Use case:** Store booking details (date, time slot, resource ID) on the payment contract.

Contract metadata is the simplest way to attach custom data. It uses the `OXMETADATA` JSON column — no schema changes needed.

### Writing Metadata (in a handler)

```php
public function handle(object $event): void
{
    $context = $event->getContext();
    $contract = $context->getContract();

    if ($contract === null) {
        return;
    }

    // Store booking information
    $contract->setMetadata('booking_date', '2026-03-15');
    $contract->setMetadata('booking_time_slot', '14:00-15:00');
    $contract->setMetadata('booking_resource_id', 'room_42');
    $contract->setMetadata('booking_participants', 3);

    // Persist via repository
    $this->contractRepository->save($contract);
}
```

### Reading Metadata

```php
$bookingDate = $contract->getMetadata('booking_date');       // '2026-03-15'
$timeSlot = $contract->getMetadata('booking_time_slot');     // '14:00-15:00'
$resourceId = $contract->getMetadata('booking_resource_id'); // 'room_42'
$missing = $contract->getMetadata('nonexistent');            // null
```

### Metadata via ContractMetadataServiceInterface

For more structured operations, inject `ContractMetadataServiceInterface`:

```php
use OxidEsales\PaymentComponent\Service\ContractMetadataServiceInterface;

class BookingMetadataService
{
    public function __construct(
        private readonly ContractMetadataServiceInterface $metadataService
    ) {}

    public function storeBookingData(
        PaymentContractInterface $contract,
        string $date,
        string $timeSlot,
        string $resourceId
    ): void {
        $contract->setMetadata('booking_date', $date);
        $contract->setMetadata('booking_time_slot', $timeSlot);
        $contract->setMetadata('booking_resource_id', $resourceId);
    }
}
```

### Limitations

- Metadata values are stored as JSON. Complex objects should be serialized to arrays.
- There is no schema validation on metadata keys. Use constants to avoid typos.
- Metadata is not indexed. Do not use it for queries that need to filter by metadata values.

---

## Pattern 4: Webhook Handler Addition

**Use case:** Handle `invoice.paid` events from Stripe for subscription renewals.

The stripe module has a webhook processing pipeline. You can add handlers for Stripe event types that the base module does not handle.

### Webhook Handler Class

Webhook handlers implement `WebhookContractFulfillmentHandlerInterface` or can be standalone classes registered in the webhook processor.

```php
<?php

declare(strict_types=1);

namespace YourVendor\Subscription\WebhookHandler;

use OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandlerInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

class InvoicePaidHandler implements WebhookContractFulfillmentHandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ?FileLoggerInterface $webhookLogger = null
    ) {}

    public function getEventType(): string
    {
        return 'invoice.paid';
    }

    public function handle(array $eventData): void
    {
        $subscriptionId = $eventData['data']['object']['subscription'] ?? null;
        $invoiceId = $eventData['data']['object']['id'] ?? null;

        if ($subscriptionId === null) {
            return;
        }

        $this->webhookLogger?->log('InvoicePaidHandler: Processing', [
            'subscriptionId' => $subscriptionId,
            'invoiceId' => $invoiceId,
        ]);

        // Find contract by subscription metadata
        // Your business logic here: extend subscription, create renewal record, etc.
    }
}
```

### services.yaml Registration

```yaml
YourVendor\Subscription\WebhookHandler\InvoicePaidHandler:
    arguments:
        $webhookLogger: '@stripe.webhook_file_logger'
    tags:
        - { name: stripe.webhook_handler }
```

**Note:** The exact tag name depends on how the webhook processor collects handlers. Check `src/Stripe/Webhook/StripeWebhookProcessor.php` for the current collection mechanism.

---

## Pattern 5: Controller Extension

**Use case:** Add a booking calendar to the payment page.

OXID modules extend controllers via `metadata.php`. The stripe module already extends `PaymentController` and `OrderController`. Your module extends these further.

### metadata.php Entry

```php
// your-module/metadata.php

'extend' => [
    // Extend Stripe's PaymentController (which already extends OXID's)
    \OxidEsales\Payments\Stripe\Controller\PaymentController::class
        => \YourVendor\Booking\Controller\BookingPaymentController::class,
],
```

### Controller Class

```php
<?php

declare(strict_types=1);

namespace YourVendor\Booking\Controller;

class BookingPaymentController extends BookingPaymentController_parent
{
    public function getBookingCalendarData(): array
    {
        $resourceId = $this->getRequestParameter('resourceId');
        if ($resourceId === null) {
            return [];
        }

        // Use OXID's service container to get your service
        $bookingService = $this->getServiceFromContainer(
            \YourVendor\Booking\Service\BookingServiceInterface::class
        );

        return $bookingService->getAvailableSlots($resourceId);
    }

    public function render(): string
    {
        $template = parent::render();
        // Add booking data to view
        $this->addTplParam('bookingSlots', $this->getBookingCalendarData());
        return $template;
    }
}
```

**Important:** The `_parent` suffix in `BookingPaymentController_parent` is OXID's virtual inheritance mechanism. Your class extends the dynamically generated parent, not a concrete class. This is why PHPStan needs ignore rules for OXID module extensions.

### Template Block (Twig)

```twig
{# your-module/views/twig/extensions/page/checkout/payment.html.twig #}

{% block checkout_payment_main %}
    {{ parent() }}

    {% if bookingSlots is defined and bookingSlots|length > 0 %}
    <div class="booking-calendar">
        <h3>{{ translate({ ident: "BOOKING_SELECT_SLOT" }) }}</h3>
        {% for slot in bookingSlots %}
            <label>
                <input type="radio" name="bookingSlot" value="{{ slot.id }}">
                {{ slot.date }} {{ slot.time }}
            </label>
        {% endfor %}
    </div>
    {% endif %}
{% endblock %}
```

---

## Pattern 6: Adapter Decoration

**Use case:** Add marketplace platform headers to every Stripe API call.

Decoration wraps the existing `StripeAdapter` with additional behavior without replacing it. This is the most advanced pattern.

### Decorator Class

```php
<?php

declare(strict_types=1);

namespace YourVendor\Marketplace\Adapter;

use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;

class MarketplaceStripeAdapter implements StripeAdapterInterface
{
    public function __construct(
        private readonly StripeAdapterInterface $inner,
        private readonly string $platformAccountId
    ) {}

    public function createCheckoutSession(array $params): object
    {
        // Add marketplace headers
        $params['payment_intent_data']['on_behalf_of'] = $this->platformAccountId;
        $params['payment_intent_data']['transfer_data'] = [
            'destination' => $this->getConnectedAccountId($params),
        ];

        return $this->inner->createCheckoutSession($params);
    }

    // Delegate all other methods to inner adapter
    public function capturePaymentIntent(string $paymentIntentId, array $params = []): object
    {
        return $this->inner->capturePaymentIntent($paymentIntentId, $params);
    }

    // ... delegate remaining interface methods

    private function getConnectedAccountId(array $params): string
    {
        // Your marketplace routing logic
        return $params['metadata']['connected_account_id'] ?? '';
    }
}
```

### services.yaml Decoration

```yaml
# Use Symfony's decoration pattern
YourVendor\Marketplace\Adapter\MarketplaceStripeAdapter:
    decorates: OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface
    arguments:
        $inner: '@.inner'
        $platformAccountId: '%marketplace.platform_account_id%'
```

**Warning:** Decoration requires you to implement the entire `StripeAdapterInterface`. If the interface changes in a stripe module update, your decorator will break at compile time. This is by design — it forces you to review the change. Consider whether a simpler event handler can achieve your goal before choosing decoration.

---

## Full Example: Subscription Module

A complete subscription module that validates plans, creates Stripe subscriptions, and handles renewal webhooks.

### Directory Structure

```
your-vendor/subscription-module/
├── composer.json
├── metadata.php
├── services.yaml
├── src/
│   ├── EventSystem/
│   │   └── Handler/
│   │       └── SubscriptionValidationHandler.php    # Pattern 1
│   ├── Service/
│   │   ├── SubscriptionCheckoutSessionService.php   # Pattern 2
│   │   ├── SubscriptionPlanService.php
│   │   └── SubscriptionPlanServiceInterface.php
│   └── WebhookHandler/
│       └── InvoicePaidHandler.php                   # Pattern 4
├── tests/
│   └── Unit/
│       └── EventSystem/
│           └── Handler/
│               └── SubscriptionValidationHandlerTest.php
└── views/
    └── twig/
```

### Handler Chain

```
StripeCheckoutSessionRequestEvent
  → StripeContractCreationHandler (100)    [core: creates contract]
  → SubscriptionValidationHandler (50)     [YOUR: validates plan, stores metadata]
  → StripeCheckoutSessionHandler (0)       [core: creates Stripe session]
      ↳ uses SubscriptionCheckoutSessionService  [YOUR: adds subscription params]

[Stripe webhook: invoice.paid]
  → StripeWebhookProcessor
      → InvoicePaidHandler                 [YOUR: processes renewal]
```

### services.yaml

```yaml
_defaults:
    autowire: true
    autoconfigure: true
    public: false

# Handler registration
YourVendor\Subscription\EventSystem\Handler\SubscriptionValidationHandler:
    tags:
        - { name: payment.event_handler, priority: 50 }

# Service override
OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface:
    class: YourVendor\Subscription\Service\SubscriptionCheckoutSessionService

# Webhook handler
YourVendor\Subscription\WebhookHandler\InvoicePaidHandler:
    arguments:
        $webhookLogger: '@stripe.webhook_file_logger'
    tags:
        - { name: stripe.webhook_handler }

# Your services
YourVendor\Subscription\Service\SubscriptionPlanServiceInterface:
    class: YourVendor\Subscription\Service\SubscriptionPlanService
```

---

## Full Example: Booking Module

A booking module that stores reservation data on contracts and adds a calendar to the payment page.

### Directory Structure

```
your-vendor/booking-module/
├── composer.json
├── metadata.php
├── services.yaml
├── src/
│   ├── Controller/
│   │   └── BookingPaymentController.php             # Pattern 5
│   ├── EventSystem/
│   │   └── Handler/
│   │       └── BookingMetadataHandler.php            # Pattern 1 + 3
│   └── Service/
│       ├── BookingService.php
│       └── BookingServiceInterface.php
├── tests/
│   └── Unit/
│       └── EventSystem/
│           └── Handler/
│               └── BookingMetadataHandlerTest.php
└── views/
    └── twig/
        └── extensions/
            └── page/
                └── checkout/
                    └── payment.html.twig
```

### Handler Chain

```
StripeCheckoutSessionRequestEvent
  → StripeContractCreationHandler (100)    [core: creates contract]
  → BookingMetadataHandler (60)            [YOUR: stores booking data as metadata]
  → StripeCheckoutSessionHandler (0)       [core: creates Stripe session]

[After payment, on ContractCommittedEvent]
  → BookingConfirmationHandler (50)        [YOUR: confirms resource reservation]
```

### metadata.php

```php
<?php

use OxidEsales\Payments\Stripe\Controller\PaymentController;
use YourVendor\Booking\Controller\BookingPaymentController;

$aModule = [
    'id' => 'yourvendor_booking',
    'title' => 'Booking Module',
    'extend' => [
        PaymentController::class => BookingPaymentController::class,
    ],
    'blocks' => [
        [
            'template' => '@oe_payments_stripe_wallet/page/checkout/payment',
            'block' => 'checkout_payment_main',
            'file' => 'views/twig/extensions/page/checkout/payment.html.twig',
        ],
    ],
];
```

---

## Testing Your Extension

### Unit Tests

Mock interfaces, not concrete classes. This catches method signature mismatches at test time:

```php
// Good — catches undefined methods
$contractRepo = $this->createMock(ContractRepositoryInterface::class);

// Bad — may silently accept wrong method names
$contractRepo = $this->createMock(DoctrineContractRepository::class);
```

### Integration Tests

Run inside Docker using the stripe module's test configuration:

```bash
docker compose exec php php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml \
    extensions/your-module/tests/Integration/
```

### Testing Event Handlers

Create the event, dispatch it through your handler, and assert context/contract changes:

```php
public function testHandlerStoresMetadata(): void
{
    // Arrange
    $contract = $this->createMock(PaymentContractInterface::class);
    $contract->expects($this->once())
        ->method('setMetadata')
        ->with('booking_date', '2026-03-15');

    $context = new EventContext(['bookingDate' => '2026-03-15']);
    $context->setContract($contract);

    $event = $this->createMock(StripeCheckoutSessionRequestEvent::class);
    $event->method('getContext')->willReturn($context);

    // Act
    $handler = new BookingMetadataHandler($this->createMock(ContractRepositoryInterface::class));
    $handler->handle($event);

    // Assert — expectations on mock verify the call
}
```

---

## Common Pitfalls

### 1. Wrong Handler Priority

**Problem:** Your handler runs after the Checkout Session is already created, so your modifications have no effect.

**Fix:** Check the [handler chain in Module Principles](01-module-principles.md#5-registered-handler-chain). Place your validation/enrichment handlers between 50-79 (after contract creation at 100, before session creation at 0).

### 2. Condition Type Whitelist

**Problem:** `new ContractCondition('subscription_validated')` throws `InvalidArgumentException`.

**Fix:** Only 4 condition types are allowed: `payment_authorized`, `fraud_check`, `compliance_check`, `address_validated`. Use contract metadata for custom conditions. See [ContractCondition Extensibility](02-payment-component-dependency.md#5-contractcondition-extensibility).

### 3. Service Override vs. Decoration

**Problem:** You override `CheckoutSessionServiceInterface` but lose the original behavior.

**Fix:** If you need the original behavior plus additions, **extend** the concrete class and call `parent::method()`. If you need to wrap behavior around the call (before/after), use **decoration** (Pattern 6). Only use a clean override when you want to fully replace the implementation.

### 4. Context Key Typos

**Problem:** `$context->get('subscriptionPlanId')` returns `null` because the upstream handler set `subscription_plan_id` (different naming convention).

**Fix:** Context keys are stringly-typed with no validation. Define constants:

```php
class SubscriptionContextKeys
{
    public const PLAN_ID = 'subscription_plan_id';
    public const PLAN = 'subscription_plan';
    public const INTERVAL = 'subscription_interval';
}
```

### 5. Metadata Not Persisted

**Problem:** You call `$contract->setMetadata(...)` but the value is gone on the next request.

**Fix:** Metadata changes are in-memory until the contract is saved. Always call `$this->contractRepository->save($contract)` after modifying metadata.

### 6. Module Loading Order

**Problem:** Your service override does not take effect because stripe's `services.yaml` loads after yours.

**Fix:** Declare the stripe module as a dependency in your `composer.json`:

```json
{
    "require": {
        "oxid-esales/stripe-module": "^1.0"
    }
}
```

This ensures OXID loads your module after stripe, so your `services.yaml` overrides take precedence.

### 7. Virtual Parent Classes in Tests

**Problem:** PHPStan reports errors for `BookingPaymentController_parent` — the class does not exist statically.

**Fix:** This is an OXID-core pattern. Add to your PHPStan config:

```neon
# phpstan.neon
parameters:
    ignoreErrors:
        - '#Class .*_parent not found#'
```

This is one of the few cases where suppression is appropriate — it is caused by OXID's virtual inheritance, not by your code.
