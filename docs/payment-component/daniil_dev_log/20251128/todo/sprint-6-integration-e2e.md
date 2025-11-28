# Sprint 6: Integration & E2E Testing

**Sprint Goal:** Verify complete checkout flows work end-to-end
**Estimated Duration:** 2-3 hours
**Status:** NOT STARTED
**Depends On:** Sprint 1-5 (All components must be working)

---

## Architecture Reference

### PUML Sources
- `puml/04-02-payment-smart-contract-flow-standard.puml`: Complete flow visualization
- `puml/05-02-webhook-system-with-contracts.puml`: Webhook integration

### Documentation
- `05-02-webhooks-with-smart-contracts.md`: Contract-aware webhooks
- `00-overview.md`: System integration overview

---

## Test Environment

```bash
# Run integration tests in Docker
docker compose exec php vendor/bin/phpunit tests/Integration/

# Run E2E tests
docker compose exec php vendor/bin/phpunit tests/E2E/

# Run full test suite
docker compose exec php vendor/bin/phpunit

# Pre-commit check (includes all tests)
./source/extensions/stripe/bin/pre-commit-check.sh
```

---

## Tasks

### 6.1 Checkout Session Flow Integration Test
**Status:** [ ] NOT STARTED

Test the complete flow: createCheckoutSession → Stripe → checkoutSuccess

**Test:**
```php
// tests/Integration/Stripe/CheckoutSessionFlowTest.php
class CheckoutSessionFlowTest extends TestCase
{
    public function testCompleteCheckoutSessionFlow(): void
    {
        // 1. Setup: Create basket, user
        $basket = $this->createTestBasket();
        $user = $this->createTestUser();

        // 2. Call createCheckoutSession
        $controller = $this->getController();
        $context = $this->simulateCreateCheckoutSession($controller, $basket, $user);

        // 3. Verify contract created
        $contractId = $context->get('contractId');
        $this->assertNotNull($contractId);

        $contract = $this->contractRepository->find($contractId);
        $this->assertEquals(ContractState::PENDING, $contract->getState());
        $this->assertNull($contract->getOrderId()); // NO ORDER YET

        // 4. Verify Stripe session created with contract_id
        $sessionId = $context->get('checkoutSessionId');
        $this->assertNotNull($sessionId);

        // 5. Simulate successful payment return
        $returnContext = $this->simulateCheckoutSuccess($controller, $sessionId, $contractId);

        // 6. Verify order created NOW
        $orderId = $returnContext->get('orderId');
        $this->assertNotNull($orderId);

        // 7. Verify contract linked to order
        $contract = $this->contractRepository->find($contractId);
        $this->assertEquals(ContractState::COMMITTED, $contract->getState());
        $this->assertEquals($orderId, $contract->getOrderId());

        // 8. Verify redirect to thankyou
        $this->assertEquals('thankyou', $returnContext->get('redirectTarget'));
    }

    public function testCheckoutSessionFlowWithPaymentFailure(): void
    {
        // Test that contract is cancelled, no order created
    }

    public function testCheckoutSessionFlowWithCancellation(): void
    {
        // Test customer cancels on Stripe page
    }
}
```

---

### 6.2 Payment Element Flow Integration Test
**Status:** [ ] NOT STARTED

Test the flow: render → Payment Element → execute → success

**Test:**
```php
// tests/Integration/Stripe/PaymentElementFlowTest.php
class PaymentElementFlowTest extends TestCase
{
    public function testCompletePaymentElementFlow(): void
    {
        // 1. Setup
        $basket = $this->createTestBasket();
        $user = $this->createTestUser();

        // 2. Create PaymentIntent (simulated render)
        $paymentIntentId = $this->createTestPaymentIntent($basket);

        // 3. Execute payment (simulated form submit)
        $context = $this->simulateExecute($paymentIntentId, $basket, $user);

        // 4. Verify contract created
        $contractId = $context->get('contractId');
        $contract = $this->contractRepository->find($contractId);
        $this->assertNotNull($contract);

        // 5. Verify order created (payment was successful)
        $orderId = $context->get('orderId');
        $this->assertNotNull($orderId);

        // 6. Verify contract committed
        $this->assertEquals(ContractState::COMMITTED, $contract->getState());
    }

    public function testPaymentElementFlowWith3DS(): void
    {
        // Test 3DS authentication requirement
        // Verify context has requires3DS = true
        // Verify redirectTarget = 'order'
    }

    public function testPaymentElementFlowWithDecline(): void
    {
        // Test declined card
        // Verify no order created
        // Verify error message set
    }
}
```

---

### 6.3 Contract State Machine Integration Test
**Status:** [ ] NOT STARTED

Verify contract transitions match PUML state machine.

**Test:**
```php
// tests/Integration/Component/ContractStateMachineTest.php
class ContractStateMachineTest extends TestCase
{
    public function testContractTransitionsDraftToPending(): void
    {
        $contract = $this->contractFactory->createFromBasket($basket, $user);
        $this->assertEquals(ContractState::DRAFT, $contract->getState());

        $contract->transitionToPending();
        $this->assertEquals(ContractState::PENDING, $contract->getState());
    }

    public function testContractTransitionsPendingToReadyToCommit(): void
    {
        $contract = $this->createPendingContract();

        // Fulfill all conditions
        $contract->fulfillCondition('payment_authorized', ['pi' => 'pi_123']);
        $contract->fulfillCondition('fraud_check', ['score' => 100]);
        $contract->fulfillCondition('stock_reserved', ['res' => 'res_123']);

        $this->assertTrue($contract->areAllConditionsFulfilled());

        $contract->transitionToReadyToCommit();
        $this->assertEquals(ContractState::READY_TO_COMMIT, $contract->getState());
    }

    public function testContractTransitionsReadyToCommitToCommitted(): void
    {
        $contract = $this->createReadyToCommitContract();

        $contract->commitToOrder('order_123');
        $this->assertEquals(ContractState::COMMITTED, $contract->getState());
        $this->assertEquals('order_123', $contract->getOrderId());
    }

    public function testContractTransitionsCommittedToFulfilled(): void
    {
        $contract = $this->createCommittedContract();

        $contract->fulfill();
        $this->assertEquals(ContractState::FULFILLED, $contract->getState());
        $this->assertNotNull($contract->getFulfilledAt());
    }

    public function testInvalidTransitionThrowsException(): void
    {
        $contract = $this->createDraftContract();

        $this->expectException(\RuntimeException::class);
        $contract->commitToOrder('order_123'); // Can't commit from DRAFT
    }
}
```

---

### 6.4 Event Chain Integration Test
**Status:** [ ] NOT STARTED

Verify events trigger correct handlers in correct order.

**Test:**
```php
// tests/Integration/Component/EventChainTest.php
class EventChainTest extends TestCase
{
    private array $handledEvents = [];

    public function testCheckoutSessionEventChain(): void
    {
        // Setup event tracking
        $this->trackEvents();

        // Trigger initial event
        $context = new EventContext(['basket' => $basket, 'user' => $user]);
        $event = new StripeCheckoutSessionRequestEvent($context);
        $this->eventDispatcher->dispatch($event);

        // Verify event chain
        $this->assertEventHandled(ContractCreatedEvent::class);
        $this->assertEventHandled(StripeCheckoutSessionCreatedEvent::class);

        // Verify contract in context
        $this->assertNotNull($context->getContract());
    }

    public function testPaymentConfirmedEventChain(): void
    {
        // Setup contract
        $contract = $this->createPendingContractWithAutoPassConditions();

        $context = new EventContext([
            'contractId' => $contract->getId(),
            'paymentIntentId' => 'pi_test',
            'amount' => 100.00,
            'currency' => 'EUR',
        ]);

        // Trigger payment confirmed
        $event = new PaymentConfirmedEvent($context);
        $this->eventDispatcher->dispatch($event);

        // Verify chain: PaymentConfirmed → ConditionFulfilled → ReadyToCommit → OrderCreated
        $this->assertEventHandled(ContractConditionFulfilledEvent::class);
        $this->assertEventHandled(ContractReadyToCommitEvent::class);
        $this->assertEventHandled(OrderCreatedFromContractEvent::class);

        // Verify order created
        $this->assertNotNull($context->get('orderId'));
    }
}
```

---

### 6.5 Webhook Integration Test
**Status:** [ ] NOT STARTED

Verify webhooks work with contract-first architecture.

**Test:**
```php
// tests/Integration/Stripe/WebhookIntegrationTest.php
class WebhookIntegrationTest extends TestCase
{
    public function testPaymentIntentSucceededWebhook(): void
    {
        // 1. Create contract in COMMITTED state (order exists)
        $contract = $this->createCommittedContract();
        $orderId = $contract->getOrderId();

        // 2. Simulate webhook
        $webhookPayload = $this->buildPaymentIntentSucceededPayload($contract->getId());
        $this->webhookController->handle($webhookPayload);

        // 3. Verify contract fulfilled
        $contract = $this->contractRepository->find($contract->getId());
        $this->assertEquals(ContractState::FULFILLED, $contract->getState());

        // 4. Verify order updated
        $order = $this->orderRepository->find($orderId);
        $this->assertEquals('OK', $order->getFieldData('oxtransstatus'));
        $this->assertNotNull($order->getFieldData('oxpaid'));
    }

    public function testPaymentIntentFailedWebhook(): void
    {
        // Test webhook for failed payment
        // Contract should be cancelled
    }

    public function testWebhookIdempotency(): void
    {
        // Test same webhook received twice
        // Should not create duplicate orders
    }
}
```

---

### 6.6 E2E Browser Test (Manual/Cypress)
**Status:** [ ] NOT STARTED

Document manual E2E test steps or Cypress tests.

**Test Scenarios:**

#### Scenario 1: Checkout Session Happy Path
```
1. Add product to basket
2. Go to checkout
3. Select Stripe payment
4. Click "Place Order"
5. Redirect to Stripe Checkout
6. Complete payment with test card 4242424242424242
7. Redirect back to shop
8. Verify thank you page
9. Verify order in admin
10. Verify contract in database
```

#### Scenario 2: Payment Element with 3DS
```
1. Add product to basket
2. Go to checkout
3. Select Stripe card payment
4. Enter 3DS test card 4000002500003155
5. Click "Place Order"
6. 3DS modal appears
7. Complete 3DS authentication
8. Verify thank you page
```

#### Scenario 3: Declined Card
```
1. Add product to basket
2. Go to checkout
3. Select Stripe card payment
4. Enter decline test card 4000000000000002
5. Click "Place Order"
6. Verify error message
7. Verify NO order created
8. Verify contract cancelled
```

---

## Definition of Done

- [ ] All integration tests pass: `docker compose exec php vendor/bin/phpunit tests/Integration/`
- [ ] Pre-commit check passes: `./source/extensions/stripe/bin/pre-commit-check.sh`
- [ ] Checkout Session flow works end-to-end
- [ ] Payment Element flow works end-to-end
- [ ] Contract state machine transitions correctly
- [ ] Event chain triggers handlers in correct order
- [ ] Webhooks update contract and order correctly
- [ ] Manual E2E test scenarios pass

---

## Files Created

### New Files
- `tests/Integration/Stripe/CheckoutSessionFlowTest.php`
- `tests/Integration/Stripe/PaymentElementFlowTest.php`
- `tests/Integration/Stripe/WebhookIntegrationTest.php`
- `tests/Integration/Component/ContractStateMachineTest.php`
- `tests/Integration/Component/EventChainTest.php`
- `docs/E2E-TEST-SCENARIOS.md` (manual test documentation)

---

## Test Data

### Stripe Test Cards
| Card Number | Scenario |
|-------------|----------|
| 4242424242424242 | Success |
| 4000002500003155 | 3DS Required |
| 4000000000000002 | Declined |
| 4000000000009995 | Insufficient Funds |

### Test Webhook Secrets
```
# .env.test
STRIPE_WEBHOOK_SECRET=whsec_test_...
```

---

## Notes

- Integration tests use mocked Stripe API responses
- E2E tests require Stripe test mode credentials
- Webhook tests require webhook secret configuration
- All tests run in Docker container for consistency
