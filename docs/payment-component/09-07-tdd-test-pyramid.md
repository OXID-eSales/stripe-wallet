# TDD Strategy - Part 7 of 8: Test Pyramid Strategy, Unit/Integration/E2E Tests, Fixtures

**Version:** 2.1.0
**Date:** 2025-10-16
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)

**Part of Series:**
- [Part 1](09-01-tdd-overview.md): Overview, Test Organization, Priority Classification, Payment Security
- [Part 2](09-02-tdd-data-persistence.md): Data Persistence & Integrity
- [Part 3](09-03-tdd-event-system.md): Event System & Business Logic, Service Layer
- [Part 4](09-04-tdd-provider-integration.md): Provider Integration, SDK-Adapter Layer
- [Part 5](09-05-tdd-authorization-flow.md): Two-Step Authorization Flow, Webhook Processing
- [Part 6](09-06-tdd-checkout-frontend.md): Checkout Frontend, Admin Features
- **Part 7** (This document): Test Pyramid Strategy, Unit/Integration/E2E Tests, Fixtures
- [Part 8](09-08-tdd-mocking-coverage.md): Mocking Strategy, Coverage Goals, CI/CD, Best Practices

---

        $transaction->setStatus('CAPTURED');
        $this->repository->saveTransaction($transaction);
    }
}
```

**Test Cases:**
- ✓ Save and retrieve order from database
- ✓ Complex queries with joins
- ✓ Transaction history queries
- ✓ Cleanup operations
- ✓ Concurrent access scenarios
- ✓ Database constraint validation

---

#### 2. Event Flow Integration Tests

**Test File:** `tests/Integration/EventFlow/CaptureEventFlowTest.php`

```php
<?php
namespace OxidSolutionCatalysts\Component\Tests\Integration\EventFlow;

use OxidSolutionCatalysts\Component\Tests\Integration\DatabaseTestCase;
use OxidSolutionCatalysts\Component\Event\CaptureRequestedEvent;
use OxidSolutionCatalysts\Component\Event\PaymentCapturedEvent;
use OxidSolutionCatalysts\Component\EventHandler\PaymentCaptureHandler;
use OxidSolutionCatalysts\Component\Service\PaymentService;
use OxidSolutionCatalysts\Component\Repository\OrderRepository;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CaptureEventFlowTest extends DatabaseTestCase
{
    private EventDispatcher $dispatcher;
    private OrderRepository $orderRepository;
    private PaymentService $paymentService;
    private bool $capturedEventFired = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = new OrderRepository(self::$pdo);
        $this->paymentService = $this->createMockedPaymentService();
        $this->dispatcher = new EventDispatcher();

        // Register handler
        $handler = new PaymentCaptureHandler(
            $this->paymentService,
            $this->orderRepository,
            $this->dispatcher
        );

        $this->dispatcher->addListener(
            CaptureRequestedEvent::class,
            [$handler, 'handle']
        );

        // Register subscriber to verify event is emitted
        $this->dispatcher->addListener(
            PaymentCapturedEvent::class,
            function (PaymentCapturedEvent $event) {
                $this->capturedEventFired = true;
            }
        );
    }

    public function testFullCaptureEventFlow(): void
    {
        // Arrange - Create authorized order
        $order = new Order();
        $order->setOxid('order-123');
        $order->setPaymentProviderOrderId('pi_123');
        $order->setPaymentState('AUTHORIZED');
        $order->setOxtotalordersum(99.99);
        $this->orderRepository->save($order);

        // Act - Emit capture requested event
        $event = new CaptureRequestedEvent(
            'order-123',
            99.99,
            'admin',
            'idempotency-key-123'
        );

        $this->dispatcher->dispatch($event);

        // Assert - Check order updated
        $updatedOrder = $this->orderRepository->getById('order-123');
        $this->assertEquals('CAPTURED', $updatedOrder->getPaymentState());

        // Assert - Check transaction saved
        $transactions = $this->orderRepository->getTransactionsByOrderId('order-123');
        $this->assertCount(1, $transactions);
        $this->assertEquals('capture', $transactions[0]->getTransactionType());

        // Assert - Check event emitted
        $this->assertTrue($this->capturedEventFired);
    }

    public function testIdempotentCaptureFlow(): void
    {
        // Arrange - Create authorized order
        $order = new Order();
        $order->setOxid('order-456');
        $order->setPaymentProviderOrderId('pi_456');
        $order->setPaymentState('AUTHORIZED');
        $order->setOxtotalordersum(150.00);
        $this->orderRepository->save($order);

        $event = new CaptureRequestedEvent(
            'order-456',
            150.00,
            'api',
            'same-idempotency-key'
        );

        // Act - Dispatch same event twice
        $this->dispatcher->dispatch($event);
        $this->dispatcher->dispatch($event);

        // Assert - Only one transaction created
        $transactions = $this->orderRepository->getTransactionsByOrderId('order-456');
        $this->assertCount(1, $transactions);
    }

    private function createMockedPaymentService(): PaymentService
    {
        // Mock only the external API calls, not the business logic
        $apiClientMock = Mockery::mock('ApiClient');
        $apiClientMock->shouldReceive('capturePayment')->andReturn(
            new CaptureResult('ch_123', 99.99, 'CAPTURED')
        );

        $service = new PaymentService(...);
        $service->setApiClient($apiClientMock);
        return $service;
    }
}
```

**Test Cases:**
- ✓ Complete capture event flow (event → handler → service → repository)
- ✓ Refund event flow
- ✓ Multiple subscribers react to same event
- ✓ Event handler error propagation
- ✓ Idempotency across event flow

---

#### 3. Webhook Integration Tests

**Test File:** `tests/Integration/Webhook/WebhookProcessingTest.php`

```php
<?php
namespace OxidSolutionCatalysts\Component\Tests\Integration\Webhook;

use OxidSolutionCatalysts\Component\Tests\Integration\DatabaseTestCase;
use OxidSolutionCatalysts\Component\Webhook\RequestHandler;
use OxidSolutionCatalysts\Component\Webhook\EventVerifier;
use OxidSolutionCatalysts\Component\Repository\OrderRepository;

class WebhookProcessingTest extends DatabaseTestCase
{
    private RequestHandler $webhookHandler;
    private OrderRepository $orderRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = new OrderRepository(self::$pdo);
        $verifier = new EventVerifier('test-webhook-secret');
        $this->webhookHandler = new RequestHandler($verifier, $this->orderRepository);
    }

    public function testProcessValidWebhook(): void
    {
        // Arrange - Create order
        $order = new Order();
        $order->setOxid('order-123');
        $order->setPaymentProviderOrderId('pi_123');
        $order->setPaymentState('AUTHORIZED');
        $this->orderRepository->save($order);

        // Create webhook payload
        $payload = json_encode([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_123',
                    'status' => 'succeeded',
                    'amount' => 9999,
                ],
            ],
        ]);

        $signature = $this->generateSignature($payload, 'test-webhook-secret');

        // Act
        $result = $this->webhookHandler->process($payload, $signature);

        // Assert
        $this->assertTrue($result->isSuccess());

        $updatedOrder = $this->orderRepository->getById('order-123');
        $this->assertEquals('CAPTURED', $updatedOrder->getPaymentState());
    }

    public function testRejectInvalidSignature(): void
    {
        // Arrange
        $payload = json_encode(['type' => 'payment_intent.succeeded']);
        $invalidSignature = 'invalid-signature';

        // Act & Assert
        $this->expectException(\SignatureVerificationException::class);
        $this->webhookHandler->process($payload, $invalidSignature);
    }

    private function generateSignature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }
}
```

**Test Cases:**
- ✓ Valid webhook processing
- ✓ Invalid signature rejection
- ✓ Duplicate webhook handling (idempotency)
- ✓ Unknown event type handling
- ✓ Concurrent webhook processing

---

### Integration Test Best Practices

1. **Use TestContainers:**
   - Real database in Docker
   - Consistent environment
   - Isolated from host

2. **Transactional Tests:**
   - Start transaction in setUp()
   - Rollback in tearDown()
   - Clean slate for each test

3. **Mock Only External APIs:**
   - Database: Real
   - Payment provider API: Mocked (WireMock)
   - Event dispatcher: Real

4. **Test Happy Path + Edge Cases:**
   - Normal flow
   - Error handling
   - Race conditions
   - Constraint violations

---

## E2E Tests (10%)

### Purpose

E2E tests verify **critical user flows** through the entire system:
- Real browser (Playwright/Codeception)
- Real database
- Provider sandbox environments
- Complete checkout flows

### Setup Requirements

```bash
# Install Codeception
composer require --dev codeception/codeception

# Or install Playwright for PHP
composer require --dev symfony/panther
```

### E2E Test Examples

#### 1. Complete Checkout Flow

**Test File:** `tests/E2E/CheckoutFlowTest.php`

```php
<?php
namespace OxidSolutionCatalysts\Component\Tests\E2E;

use Codeception\Test\Unit;

class CheckoutFlowTest extends Unit
{
    protected $tester;

    public function testCompleteStripeCheckoutFlow()
    {
        // 1. Browse shop and add product to cart
        $this->tester->amOnPage('/');
        $this->tester->click('Product 1');
        $this->tester->click('Add to Cart');
        $this->tester->see('Product added to cart');

        // 2. Go to checkout
        $this->tester->click('Checkout');
        $this->tester->seeInCurrentUrl('/checkout');

        // 3. Select payment method
        $this->tester->selectOption('payment_method', 'stripe_card');
        $this->tester->click('Continue');

        // 4. Fill payment details (Stripe test card)
        $this->tester->waitForElement('#card-element');
        $this->tester->fillStripeCard('4242424242424242', '12/25', '123');

        // 5. Complete payment
        $this->tester->click('Pay Now');
        $this->tester->waitForText('Payment successful', 30);

        // 6. Verify order confirmation
        $this->tester->seeInCurrentUrl('/order-confirmation');
        $this->tester->see('Order #');
        $this->tester->see('Total: $99.99');

        // 7. Verify order in database
        $orderId = $this->tester->grabTextFrom('.order-id');
        $this->tester->seeInDatabase('oxorder', [
            'OXORDERNR' => $orderId,
            'OXTRANSSTATUS' => 'OK',
        ]);

        // 8. Verify transaction tracked
        $this->tester->seeInDatabase('osc_transaction', [
            'order_id' => $orderId,
            'status' => 'CAPTURED',
        ]);
    }

    public function testCheckoutWithWebhookFlow()
    {
        // Similar flow but verify webhook processing
        $this->tester->amOnPage('/checkout');
        $this->tester->selectOption('payment_method', 'paymenter');
        $this->tester->click('Pay with Paymenter');

        // Redirected to Paymenter
        $this->tester->seeInCurrentUrl('paymenter.com');

        // Complete payment on Paymenter (sandbox)
        $this->tester->fillField('email', 'buyer@test.com');
        $this->tester->fillField('password', 'test123');
        $this->tester->click('Log In');
        $this->tester->click('Pay Now');

        // Redirected back to shop
        $this->tester->wait(5); // Wait for webhook
        $this->tester->seeInCurrentUrl('/order-confirmation');

        // Verify webhook processed
        $orderId = $this->tester->grabTextFrom('.order-id');
        $this->tester->seeInDatabase('oxorder', [
            'OXORDERNR' => $orderId,
            'OXTRANSSTATUS' => 'OK',
        ]);
    }
}
```

**Test Cases:**
- ✓ Complete checkout with card payment
- ✓ Complete checkout with redirect payment (Paymenter)
- ✓ Webhook processing after redirect
- ✓ Failed payment handling
- ✓ Abandoned cart recovery
- ✓ Capture from admin panel
- ✓ Refund from admin panel

---

#### 2. GraphQL API E2E Tests

**Test File:** `tests/E2E/GraphQLApiTest.php`

```php
<?php
namespace OxidSolutionCatalysts\Component\Tests\E2E;

use Codeception\Test\Unit;
use GuzzleHttp\Client;

class GraphQLApiTest extends Unit
{
    private Client $httpClient;
    private string $apiUrl = 'http://localhost:8000/graphql';
    private string $authToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = new Client();
        $this->authToken = $this->getAdminAuthToken();
    }

    public function testCapturePaymentViaGraphQL()
    {
        // Arrange - Create authorized order
        $orderId = $this->createAuthorizedOrder();

        // Act - Call GraphQL mutation
        $mutation = <<<GQL
        mutation {
          capturePayment(input: {
            orderId: "{$orderId}"
            amount: 99.99
            reason: "Test capture"
            idempotencyKey: "test-key-123"
          }) {
            success
            captureId
            orderStatus
          }
        }
        GQL;

        $response = $this->httpClient->post($this->apiUrl, [
            'json' => ['query' => $mutation],
            'headers' => ['Authorization' => 'Bearer ' . $this->authToken],
        ]);

        $result = json_decode($response->getBody(), true);

        // Assert
        $this->assertTrue($result['data']['capturePayment']['success']);
        $this->assertNotEmpty($result['data']['capturePayment']['captureId']);
        $this->assertEquals('CAPTURED', $result['data']['capturePayment']['orderStatus']);

        // Verify in database
        $this->tester->seeInDatabase('oxorder', [
            'OXID' => $orderId,
            'OXTRANSSTATUS' => 'OK',
        ]);
    }

    public function testIdempotentCapture()
    {
        // Arrange
        $orderId = $this->createAuthorizedOrder();
        $idempotencyKey = 'same-key-' . uniqid();

        // Act - Call mutation twice with same idempotency key
        $mutation = $this->buildCaptureMutation($orderId, $idempotencyKey);

        $response1 = $this->httpClient->post($this->apiUrl, [
            'json' => ['query' => $mutation],
            'headers' => ['Authorization' => 'Bearer ' . $this->authToken],
        ]);

        $response2 = $this->httpClient->post($this->apiUrl, [
            'json' => ['query' => $mutation],
            'headers' => ['Authorization' => 'Bearer ' . $this->authToken],
        ]);

        // Assert - Both succeed but only one transaction created
        $result1 = json_decode($response1->getBody(), true);
        $result2 = json_decode($response2->getBody(), true);

        $this->assertTrue($result1['data']['capturePayment']['success']);
        $this->assertTrue($result2['data']['capturePayment']['success']);

        // Only one capture transaction
        $this->tester->seeNumRecords(1, 'osc_transaction', [
            'order_id' => $orderId,
            'transaction_type' => 'capture',
        ]);
    }

    private function getAdminAuthToken(): string
    {
        // Login and get JWT token
        $response = $this->httpClient->post('http://localhost:8000/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'test123',
            ],
        ]);

        $result = json_decode($response->getBody(), true);
        return $result['token'];
    }

    private function createAuthorizedOrder(): string
    {
        // Create test order via API or database
        $orderId = 'test-order-' . uniqid();

        $this->tester->haveInDatabase('oxorder', [
            'OXID' => $orderId,
            'OXTOTALORDERSUM' => 99.99,
            'OXTRANSSTATUS' => 'AUTHORIZED',
            'payment_provider_order_id' => 'pi_test_' . uniqid(),
        ]);

        return $orderId;
    }

    private function buildCaptureMutation(string $orderId, string $idempotencyKey): string
    {
        return <<<GQL
        mutation {
          capturePayment(input: {
            orderId: "{$orderId}"
            amount: 99.99
            idempotencyKey: "{$idempotencyKey}"
          }) {
            success
            captureId
          }
        }
        GQL;
    }
}
```

**Test Cases:**
- ✓ Capture payment via GraphQL
- ✓ Refund payment via GraphQL
- ✓ Idempotency key handling
- ✓ Authentication/authorization
- ✓ Error handling
- ✓ Rate limiting

---

### E2E Test Best Practices

1. **Test Critical Paths Only:**
   - Complete checkout flow
   - Webhook integration
   - Admin operations
   - API integrations

2. **Use Provider Sandboxes:**
   - Stripe: Test mode keys
   - Paymenter: Sandbox accounts
   - Adyen: Test environment

3. **Clean Up After Tests:**
   - Delete test orders
   - Clear test data
   - Reset database state

4. **Run E2E Tests Less Frequently:**
   - Before merge to main
   - Nightly builds
   - Not on every commit

---

## Test Data & Fixtures

### Fixture Strategy Overview

| Test Type | Fixture Type | Example |
|-----------|-------------|---------|
| Unit | In-memory objects (builders/mocks) | `OrderBuilder::new()->withAmount(99.99)->build()` |
| Integration | Database seeding (factories) | `OrderFactory::create(['amount' => 99.99])` |
| E2E | Full database snapshots | `DatabaseSeeder::seed('checkout-scenario')` |

---

### 1. Unit Test Fixtures (Builders)

**Location:** `tests/Fixtures/Builders/`

**Builder Pattern Example:**

```php
<?php
// tests/Fixtures/Builders/OrderBuilder.php

namespace OxidSolutionCatalysts\Component\Tests\Fixtures\Builders;

use OxidSolutionCatalysts\Component\Model\Order;

class OrderBuilder
{
    private string $id = 'test-order-1';
    private float $amount = 99.99;
    private string $state = 'NOT_FINISHED';
    private ?string $providerOrderId = null;
    private ?string $transactionId = null;

    public static function new(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withAmount(float $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function withState(string $state): self
    {
        $this->state = $state;
        return $this;
    }

    public function authorized(): self
    {
        $this->state = 'AUTHORIZED';
        $this->providerOrderId = 'pi_' . uniqid();
        return $this;
    }

    public function captured(): self
    {
        $this->state = 'CAPTURED';
        $this->providerOrderId = 'pi_' . uniqid();
        $this->transactionId = 'ch_' . uniqid();
        return $this;
    }

    public function build(): Order
    {
        $order = new Order();
        $order->setOxid($this->id);
        $order->setOxtotalordersum($this->amount);
        $order->setPaymentState($this->state);

        if ($this->providerOrderId) {
            $order->setPaymentProviderOrderId($this->providerOrderId);
        }

        if ($this->transactionId) {
            $order->setOxtransid($this->transactionId);
        }

        return $order;
    }
}
```

**Usage in Tests:**

```php
// Create authorized order with custom amount
$order = OrderBuilder::new()
    ->withAmount(150.50)
    ->authorized()
    ->build();

// Create captured order
$order = OrderBuilder::new()->captured()->build();
```

**Additional Builders:**

```php
// tests/Fixtures/Builders/BasketBuilder.php
class BasketBuilder
{
    public static function new(): self { /* ... */ }
    public function withItems(array $items): self { /* ... */ }
    public function withTotal(float $total): self { /* ... */ }
    public function build(): Basket { /* ... */ }
}

// tests/Fixtures/Builders/UserBuilder.php
class UserBuilder
{
    public static function new(): self { /* ... */ }
    public function withEmail(string $email): self { /* ... */ }
    public function withAddress(Address $address): self { /* ... */ }
    public function build(): User { /* ... */ }
}

// tests/Fixtures/Builders/EventContextBuilder.php
class EventContextBuilder
{
    public static function new(): self { /* ... */ }
    public function withBasket(Basket $basket): self { /* ... */ }
    public function withUser(User $user): self { /* ... */ }
    public function build(): EventContext { /* ... */ }
}
```

---

---

## Related Documentation

- **[Part 6: Checkout Frontend](09-06-tdd-checkout-frontend.md)** - E2E checkout testing
- **[Part 8: Mocking & Coverage](09-08-tdd-mocking-coverage.md)** - Mocking strategies and CI/CD (continues from here)
- **[Test Organization](09-test-organization.md)** - Component vs provider test separation

---

**Version:** 2.1.0
**Last Updated:** 2025-10-16
