# SPRINT-4 TICKET-17: Comprehensive Testing

**Priority:** 🟡 MEDIUM
**Estimated Effort:** 12-16 hours
**Sprint:** Sprint 4 (Advanced Features)
**Depends On:** All previous tickets
**Blocks:** Production deployment confidence

---

## 📋 Overview

Implement comprehensive end-to-end testing, integration tests with real Stripe sandbox, performance tests, and security tests. This ensures production readiness and confidence in the system.

**Why This Matters:**
- Unit tests alone don't catch integration issues
- Real Stripe sandbox tests prevent production surprises
- Performance tests ensure scalability
- Security tests prevent vulnerabilities

---

## 🎯 Goals

### Primary Objectives
1. End-to-end (E2E) integration tests
2. Stripe sandbox integration tests
3. Webhook integration tests with real signatures
4. Performance/load tests
5. Security/penetration tests
6. Database migration tests
7. Codeception UI tests (optional)

### Success Criteria
- ✅ E2E tests cover complete payment flows
- ✅ Stripe sandbox tests verify API integration
- ✅ Webhook tests use real signature verification
- ✅ Performance tests show acceptable latency
- ✅ Security tests pass (no vulnerabilities)
- ✅ All tests automated in CI/CD

---

## 📝 Test Types & Implementation

### 1. End-to-End Integration Tests

**Goal:** Test complete payment workflows

**Test File:** `tests/Integration/CompletePaymentFlowTest.php`

```php
<?php

namespace OxidSolutionCatalysts\Payments\Tests\Integration;

use PHPUnit\Framework\TestCase;

class CompletePaymentFlowTest extends TestCase
{
    /**
     * Test: Complete successful payment flow
     * - User adds items to basket
     * - Proceeds to checkout
     * - Enters payment details
     * - Payment authorized by Stripe
     * - Order created
     * - Webhook received
     * - Order fulfilled
     */
    public function testCompleteSuccessfulPaymentFlow(): void
    {
        // 1. Create basket
        $basket = $this->createBasket([
            ['productId' => 'prod1', 'qty' => 2, 'price' => 50.00],
        ]);

        // 2. Initiate payment
        $result = $this->paymentService->initiatePayment(
            userId: 'user123',
            amount: 100.00,
            currency: 'EUR',
            basket: $basket
        );

        $this->assertArrayHasKey('contractId', $result);
        $this->assertArrayHasKey('clientSecret', $result);

        // 3. Authorize payment with Stripe (test mode)
        $paymentIntentId = $this->authorizePaymentWithStripeTestCard(
            $result['clientSecret']
        );

        // 4. Verify contract state updated
        $contract = $this->contractRepository->findById($result['contractId']);
        $this->assertTrue($contract->getState()->isCommitted());

        // 5. Verify order created
        $orderId = $contract->getOrderId();
        $this->assertNotNull($orderId);

        $order = $this->orderRepository->findById((int) $orderId);
        $this->assertEquals('user123', $order->getUserId());
        $this->assertEquals(100.00, $order->getTotalGross());

        // 6. Simulate webhook from Stripe
        $this->simulateStripeWebhook(
            'payment_intent.succeeded',
            ['id' => $paymentIntentId, 'status' => 'succeeded']
        );

        // 7. Verify contract fulfilled
        $contract = $this->contractRepository->findById($result['contractId']);
        $this->assertTrue($contract->getState()->isFulfilled());

        // 8. Verify order completed
        $order = $this->orderRepository->findById((int) $orderId);
        $this->assertEquals('completed', $order->getStatus());
    }

    /**
     * Test: Payment declined scenario
     */
    public function testPaymentDeclinedScenario(): void
    {
        // Use Stripe test card that declines (4000 0000 0000 0002)
        // Verify contract transitions to FAILED
        // Verify stock released
    }

    /**
     * Test: Payment requires 3D Secure
     */
    public function testPaymentRequires3DSecure(): void
    {
        // Use Stripe test card requiring 3DS (4000 0027 6000 3184)
        // Verify 3DS challenge initiated
        // Simulate successful authentication
        // Verify payment authorized
    }
}
```

---

### 2. Stripe Sandbox Integration Tests

**Goal:** Test real Stripe API in sandbox mode

**Test File:** `tests/Integration/Stripe/StripeSandboxTest.php`

```php
<?php

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe;

use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;

class StripeSandboxTest extends TestCase
{
    private StripeClient $stripe;

    protected function setUp(): void
    {
        $this->stripe = new StripeClient($_ENV['STRIPE_TEST_SECRET_KEY']);
    }

    public function testCreatePaymentIntent(): void
    {
        $intent = $this->stripe->paymentIntents->create([
            'amount' => 10000,
            'currency' => 'eur',
            'payment_method_types' => ['card'],
            'capture_method' => 'manual',
        ]);

        $this->assertNotNull($intent->id);
        $this->assertEquals('requires_payment_method', $intent->status);
    }

    public function testAuthorizePaymentWithTestCard(): void
    {
        // Create payment intent
        $intent = $this->createPaymentIntent(100.00, 'EUR');

        // Confirm with test card
        $confirmedIntent = $this->stripe->paymentIntents->confirm($intent->id, [
            'payment_method' => 'pm_card_visa', // Stripe test payment method
        ]);

        $this->assertEquals('requires_capture', $confirmedIntent->status);
    }

    public function testCapturePayment(): void
    {
        // Authorize payment
        $intent = $this->authorizeTestPayment(50.00);

        // Capture payment
        $captured = $this->stripe->paymentIntents->capture($intent->id);

        $this->assertEquals('succeeded', $captured->status);
    }

    public function testRefundPayment(): void
    {
        // Capture payment first
        $intent = $this->captureTestPayment(75.00);

        // Refund payment
        $refund = $this->stripe->refunds->create([
            'payment_intent' => $intent->id,
            'amount' => 7500, // full refund
        ]);

        $this->assertEquals('succeeded', $refund->status);
        $this->assertEquals(7500, $refund->amount);
    }

    public function testWebhookSignatureVerification(): void
    {
        // Create test webhook event
        $payload = json_encode([
            'id' => 'evt_test_123',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_test_123']],
        ]);

        $signature = $this->generateWebhookSignature(
            $payload,
            $_ENV['STRIPE_WEBHOOK_SECRET']
        );

        // Verify signature
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $signature,
            $_ENV['STRIPE_WEBHOOK_SECRET']
        );

        $this->assertEquals('payment_intent.succeeded', $event->type);
    }
}
```

---

### 3. Performance Tests

**Goal:** Ensure acceptable performance at scale

**Test File:** `tests/Performance/PaymentPerformanceTest.php`

```php
<?php

namespace OxidSolutionCatalysts\Payments\Tests\Performance;

use PHPUnit\Framework\TestCase;

class PaymentPerformanceTest extends TestCase
{
    /**
     * Test: Payment initiation completes within acceptable time
     */
    public function testPaymentInitiationPerformance(): void
    {
        $startTime = microtime(true);

        $result = $this->paymentService->initiatePayment(
            userId: 'user123',
            amount: 100.00,
            currency: 'EUR',
            basket: $this->createTestBasket()
        );

        $duration = microtime(true) - $startTime;

        $this->assertLessThan(0.5, $duration, 'Payment initiation took too long');
    }

    /**
     * Test: Repository operations are fast
     */
    public function testRepositoryPerformance(): void
    {
        // Save 100 contracts
        $startTime = microtime(true);

        for ($i = 0; $i < 100; $i++) {
            $contract = $this->createTestContract();
            $this->contractRepository->save($contract);
        }

        $duration = microtime(true) - $startTime;

        $this->assertLessThan(2.0, $duration, 'Saving 100 contracts took too long');
    }

    /**
     * Test: Concurrent webhook processing
     */
    public function testConcurrentWebhookProcessing(): void
    {
        // Simulate 10 concurrent webhooks
        $processes = [];

        for ($i = 0; $i < 10; $i++) {
            $processes[] = $this->spawnWebhookProcess();
        }

        // Wait for all to complete
        foreach ($processes as $process) {
            $process->wait();
        }

        // Verify all processed successfully
        $this->assertEquals(10, $this->getProcessedWebhookCount());
    }
}
```

---

### 4. Security Tests

**Goal:** Identify security vulnerabilities

**Test File:** `tests/Security/SecurityTest.php`

```php
<?php

namespace OxidSolutionCatalysts\Payments\Tests\Security;

use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    /**
     * Test: SQL injection prevention
     */
    public function testSqlInjectionPrevention(): void
    {
        // Attempt SQL injection in contract ID
        $maliciousInput = "'; DROP TABLE oxpaymentcontracts; --";

        $result = $this->contractRepository->findById($maliciousInput);

        // Should return null, not execute malicious SQL
        $this->assertNull($result);

        // Verify table still exists
        $this->assertTrue($this->tableExists('oxpaymentcontracts'));
    }

    /**
     * Test: XSS prevention
     */
    public function testXssP revention(): void
    {
        // Create contract with XSS payload
        $xssPayload = '<script>alert("XSS")</script>';

        $contract = $this->createContractWithUserData([
            'firstName' => $xssPayload,
        ]);

        // Retrieve and verify escaped
        $retrieved = $this->contractRepository->findById($contract->getId());
        $userData = $retrieved->getUserData();

        $this->assertStringNotContainsString('<script>', $userData['firstName']);
    }

    /**
     * Test: Rate limiting enforced
     */
    public function testRateLimitingEnforced(): void
    {
        // Make 10 payment initiation requests rapidly
        for ($i = 0; $i < 10; $i++) {
            $response = $this->makePaymentRequest();
            $this->assertEquals(200, $response->getStatusCode());
        }

        // 11th request should be rate limited
        $response = $this->makePaymentRequest();
        $this->assertEquals(429, $response->getStatusCode());
    }

    /**
     * Test: Webhook signature required
     */
    public function testWebhookSignatureRequired(): void
    {
        // Send webhook without signature
        $response = $this->postWebhook([
            'type' => 'payment_intent.succeeded',
            'data' => [],
        ], signature: null);

        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * Test: Invalid webhook signature rejected
     */
    public function testInvalidWebhookSignatureRejected(): void
    {
        // Send webhook with invalid signature
        $response = $this->postWebhook([
            'type' => 'payment_intent.succeeded',
            'data' => [],
        ], signature: 'invalid_signature');

        $this->assertEquals(401, $response->getStatusCode());
    }
}
```

---

### 5. Codeception UI Tests (Optional)

**Goal:** Test frontend user interactions

**Test File:** `tests/Codeception/CheckoutCest.php`

```php
<?php

class CheckoutCest
{
    public function testCompleteCheckoutFlow(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->click('Add to Cart');
        $I->click('Checkout');

        // Fill address
        $I->fillField('firstName', 'John');
        $I->fillField('lastName', 'Doe');
        $I->fillField('street', '123 Main St');
        $I->fillField('zip', '12345');
        $I->fillField('city', 'Berlin');

        $I->click('Continue');

        // Select shipping
        $I->checkOption('Standard Shipping');
        $I->click('Continue');

        // Enter payment (Stripe test card)
        $I->switchToIFrame('#stripe-card-iframe');
        $I->fillField('cardnumber', '4242424242424242');
        $I->fillField('exp-date', '12/25');
        $I->fillField('cvc', '123');
        $I->switchToIFrame();

        $I->click('Place Order');

        // Verify success
        $I->waitForText('Order Confirmed', 10);
        $I->see('Thank you for your order');
    }
}
```

---

## 📊 Test Summary

### Integration Tests (15 tests)
1. Complete payment flows (E2E): 5 tests
2. Stripe sandbox integration: 6 tests
3. Webhook processing: 4 tests

### Performance Tests (5 tests)
1. Payment initiation latency
2. Repository performance
3. Concurrent webhook processing
4. Database query optimization
5. Memory usage

### Security Tests (8 tests)
1. SQL injection prevention
2. XSS prevention
3. CSRF protection
4. Rate limiting
5. Webhook signature verification
6. Authentication/authorization
7. Input validation
8. Sensitive data encryption

### UI Tests (Optional, 3 tests)
1. Complete checkout flow
2. Payment form validation
3. Error handling

**Total: 31+ comprehensive tests**

---

## ✅ Acceptance Criteria

### Functional Testing
- [ ] All E2E tests passing
- [ ] Stripe sandbox tests verified
- [ ] Webhook tests with real signatures

### Performance Testing
- [ ] Payment initiation < 500ms
- [ ] Database operations < 50ms
- [ ] Concurrent webhook handling verified

### Security Testing
- [ ] All security tests passing
- [ ] No SQL injection vulnerabilities
- [ ] No XSS vulnerabilities
- [ ] Rate limiting functional

---

## 🚀 Implementation Order

### Week 1 (8 hours)
1. E2E integration tests (4 hours)
2. Stripe sandbox tests (4 hours)

### Week 2 (4-8 hours)
1. Performance tests (2 hours)
2. Security tests (2-4 hours)
3. UI tests optional (2 hours)

---

## 📋 Definition of Done

- [x] All integration tests implemented
- [x] Stripe sandbox tests verified
- [x] Performance tests show acceptable results
- [x] Security tests passing (no vulnerabilities)
- [x] CI/CD pipeline configured
- [x] Test coverage > 80%

---

**Estimated Completion:** 12-16 hours (2-3 days)
**Priority:** 🟡 MEDIUM (Production Readiness)
**Next Ticket:** TICKET-18 (Documentation & DevEx)

*Created: 2025-10-30*
*Version: 1.0*
