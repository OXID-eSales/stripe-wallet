# Sprint 5: Controller Refactoring (Thin Controller)

**Sprint Goal:** Refactor StripeOrderController to be thin - only dispatch events
**Estimated Duration:** 2-3 hours
**Status:** NOT STARTED
**Depends On:** Sprint 1-4 (All handlers must be working)

---

## Architecture Reference

### PUML Sources
- `puml/04-02-payment-smart-contract-flow-standard.puml` lines 45-55: Controller is THIN
- `puml/01-architecture-overview.puml`: Controller layer responsibilities

### Documentation
- `01-architecture-layers.md`: Controller layer patterns
- `03-building-payment-modules.md`: Controller best practices

### Key Principle from PUML (lines 45-55)
```
note right of OC
  **Controller is THIN**
  Only validates & emits event
  NO business logic!
end note
```

---

## Test Environment

```bash
# Run unit tests in Docker
docker compose exec php vendor/bin/phpunit tests/Unit/Stripe/Controller/StripeOrderControllerTest.php

# Run integration tests
docker compose exec php vendor/bin/phpunit tests/Integration/Stripe/Controller/
```

---

## Current State: Bartek's Controller

**File:** `src/Stripe/Controller/OrderController.php`
**Lines:** 700+
**Problem:** Contains all business logic

### Methods to Refactor:
| Method | Lines | Target |
|--------|-------|--------|
| `render()` | ~100 | Keep minimal, dispatch event for PaymentIntent |
| `execute()` | ~20 | Dispatch StripePaymentExecuteEvent |
| `executeStripePayment()` | ~60 | **REMOVE** - now in StripePaymentStatusHandler |
| `handleSuccessfulPayment()` | ~90 | **REMOVE** - now in OrderCreationHandler |
| `handle3DSecure()` | ~20 | **REMOVE** - now in Stripe3DSHandler |
| `handleProcessingPayment()` | ~15 | **REMOVE** - now in handler |
| `handleFailedPayment()` | ~25 | **REMOVE** - now in handler |
| `createCheckoutSession()` | ~100 | Dispatch StripeCheckoutSessionRequestEvent |
| `checkoutSuccess()` | ~80 | Dispatch StripeCheckoutReturnEvent |
| `stripeReturn()` | ~50 | Dispatch StripePaymentReturnEvent |
| `return3DS()` | ~15 | Dispatch event |
| Helper methods | ~200 | **REMOVE** or move to handlers |

---

## Tasks

### 5.1 Component/Controller/Core/OrderController (Base)
**Status:** [ ] NOT STARTED

Ensure base controller is provider-agnostic.

**Test First:**
```php
// tests/Unit/Component/Controller/Core/OrderControllerTest.php
class OrderControllerTest extends TestCase
{
    public function testExecuteWithNonPaymentMethodCallsParent(): void;
    public function testHasNoStripeImports(): void;
    public function testProvidesEventDispatcherGetter(): void;
    public function testProvidesSessionHelpers(): void;
}
```

**Implementation:**
```php
class OrderController extends OxidOrderController
{
    use ServiceContainer;

    public function execute(): mixed
    {
        // Check if payment method is handled by component
        if (!$this->isPaymentMethodHandledByComponent()) {
            return parent::execute();
        }

        // Subclass should override this
        return $this->executeWithPaymentComponent();
    }

    protected function executeWithPaymentComponent(): mixed
    {
        // Default implementation - subclass overrides
        return parent::execute();
    }

    protected function isPaymentMethodHandledByComponent(): bool
    {
        $basket = $this->getOxidSession()->getBasket();
        $paymentId = $basket->getPaymentId();

        // Check if payment starts with known prefixes
        return str_starts_with((string) $paymentId, 'stripe_')
            || str_starts_with((string) $paymentId, 'osc_stripe_');
    }

    protected function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->getServiceFromContainer(EventDispatcherInterface::class);
    }

    protected function getOxidSession(): Session
    {
        return Registry::getSession();
    }

    protected function addErrorToDisplay(string $message): void
    {
        Registry::getUtilsView()->addErrorToDisplay($message);
    }
}
```

---

### 5.2 Stripe/Controller/StripeOrderController (Thin)
**Status:** [ ] NOT STARTED

Refactor to ONLY dispatch events.

**Test First:**
```php
// tests/Unit/Stripe/Controller/StripeOrderControllerTest.php
class StripeOrderControllerTest extends TestCase
{
    public function testExecuteDispatchesStripePaymentExecuteEvent(): void;
    public function testExecuteReturnsRedirectFromContext(): void;
    public function testCreateCheckoutSessionDispatchesEvent(): void;
    public function testCheckoutSuccessDispatchesEvent(): void;
    public function testStripeReturnDispatchesEvent(): void;
    public function testControllerHasNoBusiness Logic(): void;
    public function testControllerHandlesSessionDataFromContext(): void;
    public function testControllerHandlesTemplateParamsFromContext(): void;
}
```

**Implementation:**
```php
use docs\daniil_dev_log\class StripeOrderController extends OrderController
{
    protected function executeWithPaymentComponent(): mixed
    {
        // Validate
        $basket = $this->getBasketFromSession();
        if (!$basket || $basket->getProductsCount() == 0) {
            $this->addErrorToDisplay('Basket is empty');
            return 'basket';
        }

        $paymentIntentId = $this->getPaymentIntentIdFromRequest()
            ?? $this->getOxidSession()->getVariable('stripe_payment_intent_id');

        if (!$paymentIntentId) {
            $this->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // Create context - ONLY DATA, NO LOGIC
        $context = new EventContext([
            'basket' => $basket,
            'user' => $basket->getBasketUser(),
            'userId' => $basket->getBasketUser()?->getId(),
            'sessionId' => $this->getOxidSession()->getId(),
            'paymentId' => $basket->getPaymentId(),
            'paymentIntentId' => $paymentIntentId,
            'orderRemark' => $this->getOxidSession()->getVariable('ordRem'),
        ]);

        // Dispatch event - HANDLERS DO THE WORK
        $event = new StripePaymentExecuteEvent($context);
        $this->getEventDispatcher()->dispatch($event);

        // Process results from context
        $this->processContextResults($context);

        return $context->get('redirectTarget') ?? 'order';
    }

    public function createCheckoutSession(): void
    {
        header('Content-Type: application/json');

        try {
            $basket = $this->getBasketFromSession();
            $user = $this->getUser();

            // Create context - ONLY DATA
            $context = new EventContext([
                'basket' => $basket,
                'user' => $user,
                'userId' => $user?->getId(),
                'sessionId' => $this->getOxidSession()->getId(),
                'shopId' => Registry::getConfig()->getShopId(),
                'captureMode' => Registry::getRequest()->getRequestParameter('capture') ?? 'automatic',
            ]);

            // Dispatch event - HANDLERS DO THE WORK
            $event = new StripeCheckoutSessionRequestEvent($context);
            $this->getEventDispatcher()->dispatch($event);

            // Store in session
            if ($sessionId = $context->get('checkoutSessionId')) {
                $this->getOxidSession()->setVariable('stripe_checkout_session_id', $sessionId);
            }
            if ($contractId = $context->get('contractId')) {
                $this->getOxidSession()->setVariable('stripe_contract_id', $contractId);
            }

            echo json_encode([
                'id' => $context->get('checkoutSessionId'),
                'contract_id' => $context->get('contractId'),
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            Registry::getLogger()->error('createCheckoutSession failed', ['error' => $e->getMessage()]);
            echo json_encode(['error' => $e->getMessage()]);
        }

        exit;
    }

    public function checkoutSuccess(): string
    {
        $sessionId = Registry::getRequest()->getRequestParameter('session_id');

        if (!$sessionId) {
            $this->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // Create context - ONLY DATA
        $context = new EventContext([
            'checkoutSessionId' => $sessionId,
            'contractId' => $this->getOxidSession()->getVariable('stripe_contract_id'),
        ]);

        // Dispatch event - HANDLERS DO THE WORK
        $event = new StripeCheckoutReturnEvent($context);
        $this->getEventDispatcher()->dispatch($event);

        // Process results
        $this->processContextResults($context);

        // Set order in session for thank you page
        if ($orderId = $context->get('orderId')) {
            $this->getOxidSession()->setVariable('sess_challenge', $orderId);
            $this->clearStripeSessionVariables();
        }

        if ($error = $context->getError()) {
            $this->addErrorToDisplay($error);
        }

        return $context->get('redirectTarget') ?? 'payment';
    }

    public function stripeReturn(): string
    {
        $paymentIntentId = Registry::getRequest()->getRequestParameter('payment_intent');
        $redirectStatus = Registry::getRequest()->getRequestParameter('redirect_status');

        if (!$paymentIntentId) {
            $paymentIntentId = $this->getOxidSession()->getVariable('stripe_payment_intent_id');
        }

        if (!$paymentIntentId) {
            $this->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // Create context - ONLY DATA
        $context = new EventContext([
            'paymentIntentId' => $paymentIntentId,
            'redirectStatus' => $redirectStatus,
            'contractId' => $this->getOxidSession()->getVariable('stripe_contract_id'),
        ]);

        // Dispatch event - HANDLERS DO THE WORK
        $event = new StripePaymentReturnEvent($context);
        $this->getEventDispatcher()->dispatch($event);

        // Process results
        $this->processContextResults($context);

        return $context->get('redirectTarget') ?? 'payment';
    }

    // ==========================================
    // HELPER METHODS (Only for controller concerns)
    // ==========================================

    private function processContextResults(EventContext $context): void
    {
        // Handle session data from handlers
        if ($sessionData = $context->get('sessionData')) {
            foreach ($sessionData as $key => $value) {
                $this->getOxidSession()->setVariable($key, $value);
            }
        }

        // Handle template params from handlers
        if ($context->get('requires3DS')) {
            $this->addTplParam('stripe3DSRequired', true);
            $this->addTplParam('stripeClientSecret', $context->get('clientSecret'));
        }

        // Handle errors
        if ($error = $context->getError()) {
            $this->addErrorToDisplay($error);
        }
    }

    private function clearStripeSessionVariables(): void
    {
        $session = $this->getOxidSession();
        $session->deleteVariable('stripe_payment_intent_id');
        $session->deleteVariable('stripe_client_secret');
        $session->deleteVariable('stripe_checkout_session_id');
        $session->deleteVariable('stripe_contract_id');
    }

    private function getPaymentIntentIdFromRequest(): ?string
    {
        return Registry::getRequest()->getRequestParameter('payment_intent_id')
            ?? Registry::getRequest()->getRequestParameter('payment_intent');
    }

    private function getBasketFromSession(): ?object
    {
        return $this->getOxidSession()->getBasket();
    }
}
```

---

### 5.3 Remove Dead Code
**Status:** [ ] NOT STARTED

After refactoring, remove all business logic methods from controller:

- [ ] `executeStripePayment()` - moved to StripePaymentStatusHandler
- [ ] `handleSuccessfulPayment()` - moved to OrderCreationHandler
- [ ] `handle3DSecure()` - moved to Stripe3DSHandler
- [ ] `handleProcessingPayment()` - moved to handler
- [ ] `handleFailedPayment()` - moved to handler
- [ ] `createPaymentViaAdapter()` - moved to handler
- [ ] `buildCheckoutLineItems()` - moved to StripeCheckoutSessionHandler
- [ ] `buildReturnUrl()` / `buildCancelUrl()` - moved to handler
- [ ] `getUserFriendlyError()` - moved to handler
- [ ] `dispatchOrderCreatedEvent()` - handled by event system
- [ ] `dispatchPaymentCapturedEvent()` - handled by event system

---

### 5.4 Update metadata.php
**Status:** [ ] NOT STARTED

```php
// metadata.php
'controllers' => [
    'order' => \OxidSolutionCatalysts\Payments\Stripe\Controller\StripeOrderController::class,
    // ... other controllers
],
```

---

### 5.5 Update services.yaml
**Status:** [ ] NOT STARTED

```yaml
# services.yaml
services:
    OxidSolutionCatalysts\Payments\Stripe\Controller\StripeOrderController:
        # Controller gets EventDispatcher via ServiceContainer trait
        # No constructor injection needed for thin controller
```

---

## Controller Size Comparison

| Metric | Before (Bartek) | After (Thin) |
|--------|-----------------|--------------|
| Lines of code | ~700 | ~150 |
| Methods | 20+ | 6 |
| Business logic | ALL | NONE |
| Dependencies | 5 injected | 0 injected |
| Testability | Hard | Easy |

---

## Definition of Done

- [ ] All tests pass: `docker compose exec php vendor/bin/phpunit tests/Unit/Stripe/Controller/`
- [ ] Pre-commit check passes
- [ ] Controller has NO business logic
- [ ] All methods only: validate → create context → dispatch event → return result
- [ ] Removed all dead code
- [ ] Controller < 200 lines

---

## Files Created/Modified

### Modified Files
- `src/Component/Controller/Core/OrderController.php` (ensure provider-agnostic)
- `src/Stripe/Controller/OrderController.php` → renamed to `StripeOrderController.php`
- `metadata.php` (update controller registration)
- `services.yaml` (update DI if needed)

### New Files
- `tests/Unit/Component/Controller/Core/OrderControllerTest.php`
- `tests/Unit/Stripe/Controller/StripeOrderControllerTest.php`

### Deleted Code
- All business logic methods from Bartek's controller

---

## Notes

- Controller is now a "router" - routes requests to event handlers
- All testable logic is in handlers
- Controller tests only verify event dispatching, not business logic
- Session handling stays in controller (framework concern)
- Template params stay in controller (framework concern)
