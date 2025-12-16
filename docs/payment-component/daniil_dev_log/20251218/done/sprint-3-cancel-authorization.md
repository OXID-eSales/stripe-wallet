# Sprint 3: Implement Cancel Authorization Feature

**Sprint Goal:** Add cancel authorization button in admin for orders in manual capture mode (requires_capture status)
**Status:** PENDING
**Priority:** MEDIUM

---

## Problem Description

For orders in manual capture mode, the payment is **authorized but not captured**. The admin UI currently provides:
- **Capture** button - to capture the authorized payment
- **Refund** button - only visible after capture

**Missing:** A "Cancel Authorization" button to release the authorization without capturing.

### Current Workarounds

1. Authorized payments expire automatically after **7 days** (Stripe default)
2. Cancel via Stripe Dashboard directly

### Business Need

Merchants need to cancel authorizations when:
- Order cancelled by customer before shipping
- Stock unavailable after authorization
- Fraud suspicion identified after authorization
- Any scenario where order should not be fulfilled

---

## Architecture Design

### Event-Driven Pattern (Consistent with Existing Code)

The implementation will follow the same pattern as capture and refund:

1. **Controller** (THIN): Validates input, creates EventContext, dispatches event
2. **Event**: `StripeCancelAuthorizationRequestEvent`
3. **Handler**: `StripeCancelAuthorizationRequestHandler` - contains all business logic
4. **Template**: Add cancel authorization section in `stripe_order_refund.html.twig`

### Stripe API

Stripe's `PaymentIntent.cancel()` API:
```php
$stripe->paymentIntents->cancel('pi_xxx', [
    'cancellation_reason' => 'requested_by_customer', // or 'duplicate', 'fraudulent', 'abandoned'
]);
```

**Allowed statuses for cancel:** `requires_payment_method`, `requires_capture`, `requires_confirmation`, `requires_action`, `processing`

---

## Tasks

### 3.1 Create StripeCancelAuthorizationRequestEvent (TDD)

**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Stripe/EventSystem/Event/StripeCancelAuthorizationRequestEventTest.php
class StripeCancelAuthorizationRequestEventTest extends TestCase
{
    public function testEventContainsContext(): void;
    public function testEventExtendsBaseEvent(): void;
    public function testEventCanAccessPaymentIntentId(): void;
    public function testEventCanAccessCancellationReason(): void;
}
```

**Implementation:**
```php
// src/Stripe/EventSystem/Event/StripeCancelAuthorizationRequestEvent.php
namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\BaseEvent;

class StripeCancelAuthorizationRequestEvent extends BaseEvent
{
    public function getPaymentIntentId(): ?string
    {
        return $this->context->get('paymentIntentId');
    }

    public function getCancellationReason(): ?string
    {
        return $this->context->get('cancellationReason');
    }
}
```

---

### 3.2 Create StripeCancelAuthorizationRequestHandler (TDD)

**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandlerTest.php
class StripeCancelAuthorizationRequestHandlerTest extends TestCase
{
    public function testHandlerIgnoresNonCancelAuthorizationEvent(): void;
    public function testHandlerRejectsInvalidPaymentIntentId(): void;
    public function testHandlerCancelsPaymentIntentViaStripeApi(): void;
    public function testHandlerSetsSuccessInContext(): void;
    public function testHandlerSetsErrorOnApiFailure(): void;
    public function testHandlerSetsCancellationReason(): void;
    public function testHandlerUpdatesOrderStatus(): void;
    public function testHandlerDispatchesPaymentCancelledEvent(): void;
}
```

**Implementation:**
```php
// src/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandler.php
namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

class StripeCancelAuthorizationRequestHandler implements HandlerInterface
{
    public function __construct(
        private StripeAdapterInterface $stripeAdapter,
        private OrderRepositoryInterface $orderRepository,
        private EventDispatcherInterface $eventDispatcher,
        private ?FileLoggerInterface $eventLogger = null
    ) {}

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCancelAuthorizationRequestEvent) {
            return;
        }

        $context = $event->getContext();
        $paymentIntentId = $event->getPaymentIntentId();

        try {
            // Cancel the PaymentIntent via Stripe API
            $result = $this->stripeAdapter->cancelPaymentIntent(
                $paymentIntentId,
                $event->getCancellationReason()
            );

            // Update order status
            $orderId = $context->get('orderId');
            if ($orderId) {
                $this->updateOrderStatus($orderId, 'cancelled');
            }

            $context->set('cancelSuccess', true);
            $context->set('cancelledPaymentIntentId', $paymentIntentId);

        } catch (\Exception $e) {
            $context->set('cancelSuccess', false);
            $context->set('error', $e->getMessage());
        }
    }
}
```

---

### 3.3 Add cancelPaymentIntent to StripeAdapterInterface

**Status:** [ ] NOT STARTED

**Implementation:**
```php
// Add to src/Stripe/Adapter/StripeAdapterInterface.php
public function cancelPaymentIntent(string $paymentIntentId, ?string $reason = null): PaymentIntent;

// Add to src/Stripe/Adapter/StripeAdapter.php
public function cancelPaymentIntent(string $paymentIntentId, ?string $reason = null): PaymentIntent
{
    $params = [];
    if ($reason !== null) {
        $params['cancellation_reason'] = $reason;
    }
    return $this->client->paymentIntents->cancel($paymentIntentId, $params);
}
```

---

### 3.4 Update Admin Controller (OrderRefund.php)

**Status:** [ ] NOT STARTED

**Add method:**
```php
/**
 * Cancel authorization for uncaptured payment via event system.
 *
 * @return void
 */
public function cancelAuthorization(): void
{
    $oOrder = $this->getOrder();
    if ($oOrder === null) {
        $this->setErrorMessage(Registry::getLang()->translateString('STRIPE_CANCEL_NO_ORDER'));
        $this->_blSuccessfulCancel = false;
        return;
    }

    $paymentIntentId = $oOrder->oxorder__oxtransid->value ?? null;
    if (empty($paymentIntentId)) {
        $this->setErrorMessage(Registry::getLang()->translateString('STRIPE_CANCEL_NO_TRANSACTION'));
        $this->_blSuccessfulCancel = false;
        return;
    }

    $context = new EventContext([
        'orderId' => $oOrder->getId(),
        'paymentIntentId' => $paymentIntentId,
        'cancellationReason' => $this->getCancellationReasonFromRequest(),
        'initiator' => 'admin',
    ]);

    $event = new StripeCancelAuthorizationRequestEvent($context);
    $this->getEventDispatcher()->dispatch($event);

    $this->_oEventContext = $context;
    $this->processCancelResults($context);
}

/**
 * Check if order can be cancelled (has requires_capture status).
 */
public function isOrderCancellable(): bool
{
    // Can cancel if capturable (requires_capture status)
    return $this->isOrderCapturable();
}

/**
 * Check if cancel was successful.
 */
public function wasCancelSuccessful(): ?bool
{
    return $this->_blSuccessfulCancel;
}
```

---

### 3.5 Update Admin Template (stripe_order_refund.html.twig)

**Status:** [ ] NOT STARTED

**Add cancel authorization section after capture section:**

```twig
{# Cancel Authorization Section - only show if payment can be cancelled #}
{% set blIsOrderCancellable = oView.isOrderCancellable() %}
{% if blIsOrderCancellable == true %}
    <fieldset class="cancelPayment">
        <legend>{{ translate({ ident: "STRIPE_CANCEL_AUTHORIZATION" }) }}</legend>
        <form name="cancelForm" id="cancelForm" action="{{ oViewConf.getSelfLink() }}" method="post">
            {{ oViewConf.getHiddenSid()|raw }}
            <input type="hidden" name="cl" value="stripe_order_refund">
            <input type="hidden" name="oxid" value="{{ oxid }}">
            <input type="hidden" name="fnc" value="cancelAuthorization">

            <p>{{ translate({ ident: "STRIPE_CANCEL_AUTHORIZATION_TEXT" }) }}</p>

            <span><label for="cancel_reason">{{ translate({ ident: "STRIPE_CANCEL_REASON" }) }}:</label></span>
            <select id="cancel_reason" name="cancel_reason">
                <option value="">{{ translate({ ident: "STRIPE_PLEASE_SELECT" }) }}</option>
                <option value="requested_by_customer">{{ translate({ ident: "STRIPE_CANCEL_CUSTOMER" }) }}</option>
                <option value="duplicate">{{ translate({ ident: "STRIPE_CANCEL_DUPLICATE" }) }}</option>
                <option value="fraudulent">{{ translate({ ident: "STRIPE_CANCEL_FRAUD" }) }}</option>
                <option value="abandoned">{{ translate({ ident: "STRIPE_CANCEL_ABANDONED" }) }}</option>
            </select><br>

            <input type="submit" value="{{ translate({ ident: "STRIPE_CANCEL_SUBMIT" }) }}" class="cancelSubmit">
        </form>
    </fieldset>
{% endif %}

{# Cancel Success Message #}
{% if oView.wasCancelSuccessful() == true %}
    <fieldset class="cancelSuccess message">
        {{ translate({ ident: "STRIPE_CANCEL_SUCCESSFUL" }) }}
    </fieldset>
{% endif %}
```

---

### 3.6 Add Language Translations

**Status:** [ ] NOT STARTED

**Files:**
- `translations/en/admin/stripe_lang.php`
- `translations/de/admin/stripe_lang.php`

**English:**
```php
'STRIPE_CANCEL_AUTHORIZATION' => 'Cancel Authorization',
'STRIPE_CANCEL_AUTHORIZATION_TEXT' => 'Cancel the payment authorization. The customer will not be charged and the authorized amount will be released.',
'STRIPE_CANCEL_REASON' => 'Cancellation reason',
'STRIPE_CANCEL_CUSTOMER' => 'Requested by customer',
'STRIPE_CANCEL_DUPLICATE' => 'Duplicate',
'STRIPE_CANCEL_FRAUD' => 'Fraudulent',
'STRIPE_CANCEL_ABANDONED' => 'Abandoned',
'STRIPE_CANCEL_SUBMIT' => 'Cancel Authorization',
'STRIPE_CANCEL_SUCCESSFUL' => 'Authorization successfully cancelled. The customer will not be charged.',
'STRIPE_CANCEL_NO_ORDER' => 'Order not found',
'STRIPE_CANCEL_NO_TRANSACTION' => 'Order has no Stripe transaction ID',
```

**German:**
```php
'STRIPE_CANCEL_AUTHORIZATION' => 'Autorisierung stornieren',
'STRIPE_CANCEL_AUTHORIZATION_TEXT' => 'Die Zahlungsautorisierung stornieren. Der Kunde wird nicht belastet und der autorisierte Betrag wird freigegeben.',
'STRIPE_CANCEL_REASON' => 'Stornierungsgrund',
'STRIPE_CANCEL_CUSTOMER' => 'Vom Kunden angefordert',
'STRIPE_CANCEL_DUPLICATE' => 'Duplikat',
'STRIPE_CANCEL_FRAUD' => 'Betrugsverdacht',
'STRIPE_CANCEL_ABANDONED' => 'Abgebrochen',
'STRIPE_CANCEL_SUBMIT' => 'Autorisierung stornieren',
'STRIPE_CANCEL_SUCCESSFUL' => 'Autorisierung erfolgreich storniert. Der Kunde wird nicht belastet.',
'STRIPE_CANCEL_NO_ORDER' => 'Bestellung nicht gefunden',
'STRIPE_CANCEL_NO_TRANSACTION' => 'Bestellung hat keine Stripe-Transaktions-ID',
```

---

### 3.7 Register Handler in services.yaml

**Status:** [ ] NOT STARTED

```yaml
OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCancelAuthorizationRequestHandler:
    arguments:
        $stripeAdapter: '@OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapterInterface'
        $orderRepository: '@OxidSolutionCatalysts\Payments\Stripe\Repository\OrderRepositoryInterface'
        $eventDispatcher: '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
        $eventLogger: '@stripe.events.file_logger'
    tags:
        - { name: 'stripe.event_handler' }
```

---

### 3.8 Update E2E Test (AdminStripeOrderPage.ts)

**Status:** [ ] NOT STARTED

**Add methods:**
```typescript
async isCancelButtonVisible(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    return editFrame.locator('input.cancelSubmit, input[value*="Cancel Authorization"]')
        .isVisible({ timeout: 3000 }).catch(() => false);
}

async executeCancelAuthorization(reason: string = 'requested_by_customer'): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    // Select cancellation reason
    const reasonSelect = editFrame.locator('#cancel_reason');
    if (await reasonSelect.isVisible({ timeout: 3000 }).catch(() => false)) {
        await reasonSelect.selectOption({ value: reason });
    }

    // Click cancel button
    const cancelBtn = editFrame.locator('input.cancelSubmit').first();
    if (await cancelBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await cancelBtn.click();
        await this.page.waitForLoadState('networkidle').catch(() => {});
        await this.page.waitForTimeout(2000);
        return true;
    }

    return false;
}

async wasCancelSuccessful(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    return editFrame.locator('fieldset.cancelSuccess, text=successfully cancelled')
        .isVisible({ timeout: 5000 }).catch(() => false);
}
```

---

### 3.9 Add E2E Test for Cancel Authorization

**Status:** [ ] NOT STARTED

**Update `stripe-admin-capture.spec.ts`:**

```typescript
test('6. Test cancel authorization (for new uncaptured order)', async ({ page }) => {
    // ... existing login code ...

    const stripePage = new AdminStripeOrderPage(page);

    // Check for cancel button
    const cancelButtonVisible = await stripePage.isCancelButtonVisible();

    if (cancelButtonVisible) {
        console.log('✓ Cancel authorization button found');

        // Execute cancel
        const cancelExecuted = await stripePage.executeCancelAuthorization('requested_by_customer');

        if (cancelExecuted) {
            const cancelSuccess = await stripePage.wasCancelSuccessful();
            if (cancelSuccess) {
                console.log('✓ Authorization cancelled successfully');
            }
        }
    } else {
        console.log('⚠ Cancel authorization button NOT found');
    }
});
```

---

## Definition of Done

- [ ] Event class created with tests
- [ ] Handler class created with tests (TDD)
- [ ] StripeAdapter extended with cancelPaymentIntent
- [ ] Controller method added
- [ ] Template updated with cancel section
- [ ] Language files updated (EN + DE)
- [ ] Handler registered in services.yaml
- [ ] E2E test page object updated
- [ ] E2E test for cancel authorization added
- [ ] Unit tests pass: `docker compose exec php vendor/bin/phpunit ... StripeCancelAuthorizationRequestHandlerTest.php`
- [ ] Pre-commit check passes: `./bin/pre-commit-check.sh`
- [ ] E2E tests pass: `npx playwright test tests/admin/stripe-admin-capture.spec.ts`

---

## Files to Create

| File | Purpose |
|------|---------|
| `src/Stripe/EventSystem/Event/StripeCancelAuthorizationRequestEvent.php` | Event class |
| `src/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandler.php` | Handler class |
| `tests/Unit/Stripe/EventSystem/Event/StripeCancelAuthorizationRequestEventTest.php` | Event tests |
| `tests/Unit/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandlerTest.php` | Handler tests |

---

## Files to Modify

| File | Changes |
|------|---------|
| `src/Stripe/Adapter/StripeAdapterInterface.php` | Add cancelPaymentIntent method |
| `src/Stripe/Adapter/StripeAdapter.php` | Implement cancelPaymentIntent |
| `src/Stripe/Controller/Admin/OrderRefund.php` | Add cancelAuthorization method |
| `views/twig/admin/stripe_order_refund.html.twig` | Add cancel authorization section |
| `translations/en/admin/stripe_lang.php` | Add EN translations |
| `translations/de/admin/stripe_lang.php` | Add DE translations |
| `services.yaml` | Register new handler |
| `tests/e2e/playwright/pages/admin/AdminStripeOrderPage.ts` | Add cancel methods |
| `tests/e2e/playwright/tests/admin/stripe-admin-capture.spec.ts` | Update test |

---

## Development Principles

All changes must follow:

- **TDD** - Write failing tests first, then implementation
- **SOLID** - Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **Clean Code** - Meaningful names, small functions (15-25 lines), no else expressions (use early returns), DRY
- **Dependency Injection** - Depend on abstractions, not concretions
- **PSR-12** code style, **PHPStan level 6** compliance

---

## Commands Reference

```bash
# Run pre-commit check
./bin/pre-commit-check.sh           # Unit tests + style checks
./bin/pre-commit-check.sh --full    # Unit + Integration tests
./bin/pre-commit-check.sh --no-phpunit  # Style checks only

# Run specific unit test
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandlerTest.php

# Run all unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Run E2E tests
cd tests/e2e/playwright && SHOP_URL=https://daniil.oxiddev.de npx playwright test tests/admin/stripe-admin-capture.spec.ts

# Code style checks
composer phpcs              # PHP CodeSniffer (PSR-12)
composer phpstan            # PHPStan static analysis (level 6)
composer phpmd              # PHP Mess Detector
composer style              # All style checks

# OXID Module commands
docker compose exec php bin/oe-console oe:module:deactivate osc_stripe_wallet
docker compose exec php bin/oe-console oe:module:install extensions/stripe
docker compose exec php bin/oe-console oe:module:activate osc_stripe_wallet
```

---

## Notes

- Cancel authorization is only available for `requires_capture` status (same as capture)
- After cancellation, the PaymentIntent status changes to `canceled`
- The authorized hold on customer's card is released immediately
- OXPAID should remain `0000-00-00 00:00:00` (never captured)
- Order status should be updated to reflect cancellation
