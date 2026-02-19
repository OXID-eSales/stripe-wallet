# Sprint 4: Stripe-Specific Handlers

**Sprint Goal:** Implement Stripe-specific handlers that use contract-first architecture
**Estimated Duration:** 3-4 hours
**Status:** NOT STARTED
**Depends On:** Sprint 1-3 (Contract Infrastructure, Conditions, Order Creation)

---

## Architecture Reference

### PUML Sources
- `puml/04-sdk-adapter-layer.puml`: SDK adapter pattern for Stripe
- `puml/05-02-webhook-system-with-contracts.puml`: Webhook processing with contracts

### Documentation
- `04-sdk-adapter-layer.md`: PaymentAdapterFactory, StripeAdapter
- `05-02-webhooks-with-smart-contracts.md`: Contract-aware webhook handling

### Key Pattern from SDK Adapter Layer
```
Controller → Event → Handler → PaymentAdapterFactory → StripeAdapter → Stripe SDK
```

---

## Test Environment

```bash
# Run unit tests in Docker
docker compose exec php vendor/bin/phpunit tests/Unit/Stripe/EventSystem/Handler/

# Run specific test
docker compose exec php vendor/bin/phpunit tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutSessionHandlerTest.php
```

---

## Tasks

### 4.1 Stripe Events
**Status:** [ ] NOT STARTED

**Events to create:**
- `StripeCheckoutSessionRequestEvent` - when createCheckoutSession() called
- `StripeCheckoutReturnEvent` - when checkoutSuccess() called
- `StripePaymentExecuteEvent` - when execute() called (Payment Element flow)
- `Stripe3DSRequiredEvent` - when 3DS authentication needed
- `StripePaymentReturnEvent` - when stripeReturn() called

**Test First:**
```php
// tests/Unit/Stripe/EventSystem/Event/StripeEventsTest.php
class StripeEventsTest extends TestCase
{
    public function testStripeCheckoutSessionRequestEventContainsBasket(): void;
    public function testStripeCheckoutReturnEventContainsSessionId(): void;
    public function testStripe3DSRequiredEventContainsClientSecret(): void;
}
```

---

### 4.2 StripeCheckoutSessionHandler
**Status:** [ ] NOT STARTED

**Replaces:** `createCheckoutSession()` logic from Bartek's controller
**Key Change:** Creates Stripe session with `contract_id`, NOT `order_id`

**Test First:**
```php
// tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutSessionHandlerTest.php
class StripeCheckoutSessionHandlerTest extends TestCase
{
    public function testCreatesStripeCheckoutSession(): void;
    public function testUsesContractIdInMetadata(): void;
    public function testDoesNotCreateOrder(): void;
    public function testBuildsLineItemsFromContractSnapshot(): void;
    public function testSetsCheckoutSessionIdInContext(): void;
    public function testStoresSessionIdInContract(): void;
}
```

**Implementation:**
```php
class StripeCheckoutSessionHandler extends AbstractHandler
{
    public function __construct(
        ContractRepository $contractRepository,
        private PaymentAdapterFactory $adapterFactory,
        private ModuleConfigurationService $config,
        ?EventDispatcher $eventDispatcher = null
    ) {
        parent::__construct($contractRepository, $eventDispatcher);
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCheckoutSessionRequestEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $context->getContract(); // Already created by ContractCreationHandler

        // Build line items from CONTRACT's basket snapshot (not current basket!)
        $lineItems = $this->buildLineItems($contract->getBasketSnapshot());

        // Create Checkout Session with CONTRACT reference
        $stripeClient = $this->adapterFactory->getStripeClient();
        $checkoutSession = $stripeClient->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $lineItems,
            'success_url' => $this->buildSuccessUrl($contract->getId()),
            'cancel_url' => $this->buildCancelUrl($contract->getId()),
            'metadata' => [
                'contract_id' => $contract->getId(),  // CONTRACT, not order!
                'shop_id' => $context->get('shopId'),
            ],
            'payment_intent_data' => [
                'capture_method' => $context->get('captureMode', 'automatic'),
                'metadata' => [
                    'contract_id' => $contract->getId(),
                ],
            ],
        ]);

        // Store session ID in contract for later retrieval
        $contract->setProviderSessionId($checkoutSession->id);
        $this->contractRepository->save($contract);

        // Update context for controller
        $context->set('checkoutSessionId', $checkoutSession->id);

        Registry::getLogger()->info('Stripe Checkout Session created', [
            'session_id' => $checkoutSession->id,
            'contract_id' => $contract->getId(),
        ]);
    }
}
```

---

### 4.3 StripeCheckoutReturnHandler
**Status:** [ ] NOT STARTED

**Replaces:** `checkoutSuccess()` logic from Bartek's controller
**Key Action:** Verifies payment, dispatches PaymentConfirmedEvent to trigger condition fulfillment

**Test First:**
```php
// tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php
class StripeCheckoutReturnHandlerTest extends TestCase
{
    public function testRetrievesCheckoutSession(): void;
    public function testVerifiesPaymentStatus(): void;
    public function testLoadsContractFromMetadata(): void;
    public function testDispatchesPaymentConfirmedEvent(): void;
    public function testSetsErrorOnPaymentNotCompleted(): void;
    public function testSetsRedirectTargetToThankyouOnSuccess(): void;
}
```

**Implementation:**
```php
class StripeCheckoutReturnHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        if (!$event instanceof StripeCheckoutReturnEvent) {
            return;
        }

        $context = $event->getContext();
        $sessionId = $context->get('checkoutSessionId');

        // Retrieve Checkout Session from Stripe
        $stripeClient = $this->adapterFactory->getStripeClient();
        $checkoutSession = $stripeClient->checkout->sessions->retrieve($sessionId, [
            'expand' => ['payment_intent']
        ]);

        // Verify payment was successful
        if ($checkoutSession->payment_status !== 'paid') {
            $context->setError('Payment not completed: ' . $checkoutSession->payment_status);
            $context->set('redirectTarget', 'payment');
            return;
        }

        // Get contract from metadata
        $contractId = $checkoutSession->metadata->contract_id;
        $contract = $this->contractRepository->find($contractId);

        if (!$contract) {
            $context->setError('Contract not found: ' . $contractId);
            $context->set('redirectTarget', 'payment');
            return;
        }

        // Set contract in context
        $context->setContract($contract);
        $context->set('contractId', $contractId);

        // Get PaymentIntent details
        $paymentIntent = $checkoutSession->payment_intent;
        $paymentIntentId = is_string($paymentIntent) ? $paymentIntent : $paymentIntent->id;

        $context->set('paymentIntentId', $paymentIntentId);
        $context->set('amount', $checkoutSession->amount_total / 100);
        $context->set('currency', $checkoutSession->currency);

        // Dispatch PaymentConfirmedEvent
        // This triggers: PaymentAuthorizationConditionHandler
        //            → ContractReadyToCommitEvent
        //            → OrderCreationHandler
        $this->eventDispatcher->dispatch(new PaymentConfirmedEvent($context));

        // After event chain completes, check result
        if ($context->get('orderId')) {
            $context->set('redirectTarget', 'thankyou');
        }
    }
}
```

---

### 4.4 StripePaymentStatusHandler (Payment Element Flow)
**Status:** [ ] NOT STARTED

**Replaces:** `executeStripePayment()` logic from Bartek's controller
**For:** Payment Element integration (card form on order page)

**Test First:**
```php
// tests/Unit/Stripe/EventSystem/Handler/StripePaymentStatusHandlerTest.php
class StripePaymentStatusHandlerTest extends TestCase
{
    public function testGetsPaymentIntentStatus(): void;
    public function testDispatchesPaymentConfirmedOnSuccess(): void;
    public function testDispatches3DSRequiredOnRequiresAction(): void;
    public function testDispatchesFailedOnDecline(): void;
    public function testSetsPaymentDetailsInContext(): void;
}
```

**Implementation:**
```php
class StripePaymentStatusHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        if (!$event instanceof StripePaymentExecuteEvent) {
            return;
        }

        $context = $event->getContext();
        $paymentIntentId = $context->get('paymentIntentId');

        // Get payment status via adapter
        $adapter = $this->adapterFactory->createDefaultAdapter();
        $paymentDetails = $adapter->getPaymentDetails($paymentIntentId);

        // Store in context
        $context->set('paymentDetails', $paymentDetails);
        $context->set('paymentStatus', $paymentDetails->status);

        // Route based on status
        match ($paymentDetails->status) {
            StripeStatusMapper::STATUS_CAPTURED,
            StripeStatusMapper::STATUS_AUTHORIZED =>
                $this->handleSuccess($context, $paymentDetails),

            StripeStatusMapper::STATUS_PENDING =>
                $this->handlePending($context, $paymentDetails),

            default =>
                $this->handleFailure($context, $paymentDetails),
        };
    }

    private function handleSuccess(EventContext $context, $paymentDetails): void
    {
        $context->set('amount', $paymentDetails->amount);
        $context->set('currency', $paymentDetails->currency);

        // Dispatch PaymentConfirmedEvent to trigger condition fulfillment
        $this->eventDispatcher->dispatch(new PaymentConfirmedEvent($context));
    }

    private function handlePending(EventContext $context, $paymentDetails): void
    {
        $stripeStatus = $paymentDetails->providerData['status'] ?? '';

        if (in_array($stripeStatus, ['requires_action', 'requires_confirmation'])) {
            $context->set('requires3DS', true);
            $context->set('clientSecret', $paymentDetails->providerData['client_secret'] ?? null);
            $context->set('redirectTarget', 'order');

            $this->eventDispatcher->dispatch(new Stripe3DSRequiredEvent($context));
        } else {
            $this->handleFailure($context, $paymentDetails);
        }
    }

    private function handleFailure(EventContext $context, $paymentDetails): void
    {
        $context->setError('Payment failed: ' . $paymentDetails->status);
        $context->set('redirectTarget', 'payment');
    }
}
```

---

### 4.5 Stripe3DSHandler
**Status:** [ ] NOT STARTED

**Replaces:** `handle3DSecure()` logic from Bartek's controller

**Test First:**
```php
// tests/Unit/Stripe/EventSystem/Handler/Stripe3DSHandlerTest.php
class Stripe3DSHandlerTest extends TestCase
{
    public function testSetsClientSecretInContext(): void;
    public function testSetsRedirectTargetToOrder(): void;
    public function testStoresPaymentIntentIdInSession(): void;
}
```

---

### 4.6 StripePaymentReturnHandler
**Status:** [ ] NOT STARTED

**Replaces:** `stripeReturn()` logic from Bartek's controller
**For:** Handling return from Stripe after Payment Element confirmation

**Test First:**
```php
// tests/Unit/Stripe/EventSystem/Handler/StripePaymentReturnHandlerTest.php
class StripePaymentReturnHandlerTest extends TestCase
{
    public function testRetrievesPaymentIntentFromUrl(): void;
    public function testDispatchesStripePaymentExecuteEvent(): void;
    public function testHandlesRedirectStatus(): void;
}
```

---

## Event Chain: Checkout Session Flow

```
createCheckoutSession() called
    │
    └─► StripeCheckoutSessionRequestEvent
            │
            ├─► ContractCreationHandler (Sprint 1)
            │       • Creates CONTRACT
            │       • Basket snapshot captured
            │
            └─► StripeCheckoutSessionHandler (THIS SPRINT)
                    • Creates Stripe Checkout Session
                    • Metadata: { contract_id }
                    • Returns session_id

═══════════════════════════════════════════════

Customer pays on Stripe...

═══════════════════════════════════════════════

checkoutSuccess() called
    │
    └─► StripeCheckoutReturnEvent
            │
            └─► StripeCheckoutReturnHandler (THIS SPRINT)
                    • Verifies payment_status = 'paid'
                    • Loads contract by contract_id
                    • Dispatches PaymentConfirmedEvent
                            │
                            ▼
                    PaymentAuthorizationConditionHandler (Sprint 2)
                            • Fulfills 'payment_authorized'
                            • All conditions met → READY_TO_COMMIT
                            • Dispatches ContractReadyToCommitEvent
                                    │
                                    ▼
                            OrderCreationHandler (Sprint 3)
                                    • Creates oxorder NOW
                                    • Contract → COMMITTED
```

---

## Definition of Done

- [ ] All tests pass: `docker compose exec php vendor/bin/phpunit tests/Unit/Stripe/EventSystem/Handler/`
- [ ] Pre-commit check passes
- [ ] StripeCheckoutSessionHandler uses contract_id, not order_id
- [ ] StripeCheckoutReturnHandler dispatches PaymentConfirmedEvent
- [ ] StripePaymentStatusHandler routes to correct sub-events
- [ ] All handlers extend AbstractHandler

---

## Files Created/Modified

### New Files
- `src/Stripe/EventSystem/Event/StripeCheckoutSessionRequestEvent.php`
- `src/Stripe/EventSystem/Event/StripeCheckoutReturnEvent.php`
- `src/Stripe/EventSystem/Event/StripePaymentExecuteEvent.php`
- `src/Stripe/EventSystem/Event/Stripe3DSRequiredEvent.php`
- `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php`
- `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`
- `src/Stripe/EventSystem/Handler/StripePaymentStatusHandler.php`
- `src/Stripe/EventSystem/Handler/Stripe3DSHandler.php`
- `src/Stripe/EventSystem/Handler/StripePaymentReturnHandler.php`
- Tests for all handlers

### Modified Files
- `services.yaml` (register Stripe handlers)

---

## Notes

- All Stripe handlers are in `src/Stripe/` namespace (provider-specific)
- They use the generic events from `Component/` where possible
- PaymentAdapterFactory provides Stripe SDK access
- Contract ID is used in all Stripe metadata instead of order ID
