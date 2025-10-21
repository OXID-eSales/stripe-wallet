# TDD Strategy - Part 8 of 8: Mocking Strategy, Coverage Goals, CI/CD, Best Practices

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
- [Part 7](09-07-tdd-test-pyramid.md): Test Pyramid Strategy, Unit/Integration/E2E Tests, Fixtures
- **Part 8** (This document): Mocking Strategy, Coverage Goals, CI/CD, Best Practices

---


### 2. Integration Test Fixtures (Factories)

**Location:** `tests/Fixtures/Factories/`

**Factory Pattern Example:**

```php
<?php
// tests/Fixtures/Factories/OrderFactory.php

namespace PaymentComponent\Tests\Fixtures\Factories;

use PaymentComponent\Model\Order;

class OrderFactory
{
    private static \PDO $pdo;

    public static function setPdo(\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function create(array $attributes = []): Order
    {
        $defaults = [
            'oxid' => 'order-' . uniqid(),
            'oxtotalordersum' => 99.99,
            'oxordernr' => rand(100000, 999999),
            'oxtransstatus' => 'NOT_FINISHED',
            'oxorderdate' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $attributes);

        // Insert into database
        $sql = "INSERT INTO oxorder (OXID, OXTOTALORDERSUM, OXORDERNR, OXTRANSSTATUS, OXORDERDATE)
                VALUES (:oxid, :oxtotalordersum, :oxordernr, :oxtransstatus, :oxorderdate)";

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($data);

        // Return order object
        $order = new Order();
        $order->load($data['oxid']);
        return $order;
    }

    public static function createAuthorized(array $attributes = []): Order
    {
        return self::create(array_merge([
            'oxtransstatus' => 'AUTHORIZED',
            'payment_provider_order_id' => 'pi_' . uniqid(),
        ], $attributes));
    }

    public static function createCaptured(array $attributes = []): Order
    {
        return self::create(array_merge([
            'oxtransstatus' => 'OK',
            'payment_provider_order_id' => 'pi_' . uniqid(),
            'oxtransid' => 'ch_' . uniqid(),
            'oxpaid' => date('Y-m-d H:i:s'),
        ], $attributes));
    }
}
```

**Usage in Integration Tests:**

```php
// Create order in database
$order = OrderFactory::create(['oxtotalordersum' => 150.50]);

// Create authorized order
$order = OrderFactory::createAuthorized();

// Create captured order
$order = OrderFactory::createCaptured();
```

**Additional Factories:**

```php
// tests/Fixtures/Factories/TransactionFactory.php
class TransactionFactory
{
    public static function create(array $attributes = []): PaymentTransaction { /* ... */ }
    public static function createCapture(Order $order): PaymentTransaction { /* ... */ }
    public static function createRefund(Order $order): PaymentTransaction { /* ... */ }
}

// tests/Fixtures/Factories/UserFactory.php
class UserFactory
{
    public static function create(array $attributes = []): User { /* ... */ }
    public static function createWithAddress(): User { /* ... */ }
}

// tests/Fixtures/Factories/BasketFactory.php
class BasketFactory
{
    public static function create(array $attributes = []): Basket { /* ... */ }
    public static function createWithItems(array $items): Basket { /* ... */ }
}
```

---

### 3. E2E Test Fixtures (Seeders)

**Location:** `tests/Fixtures/Seeders/`

**Database Seeder Example:**

```php
<?php
// tests/Fixtures/Seeders/DatabaseSeeder.php

namespace PaymentComponent\Tests\Fixtures\Seeders;

class DatabaseSeeder
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function seed(string $scenario = 'default'): void
    {
        $this->clearTables();

        match ($scenario) {
            'checkout' => $this->seedCheckoutScenario(),
            'admin-capture' => $this->seedAdminCaptureScenario(),
            'webhook' => $this->seedWebhookScenario(),
            default => $this->seedDefaultScenario(),
        };
    }

    private function seedCheckoutScenario(): void
    {
        // Create products
        $this->createProduct('prod-1', 'Test Product 1', 99.99);
        $this->createProduct('prod-2', 'Test Product 2', 149.99);

        // Create test user
        $this->createUser('test@example.com', 'Test User');

        // Create categories
        $this->createCategory('Electronics');
    }

    private function seedAdminCaptureScenario(): void
    {
        // Create admin user
        $this->createUser('admin@example.com', 'Admin', 'admin');

        // Create authorized orders
        for ($i = 1; $i <= 5; $i++) {
            $this->createOrder([
                'oxid' => "order-auth-{$i}",
                'oxtotalordersum' => 100.00 * $i,
                'oxtransstatus' => 'AUTHORIZED',
                'payment_provider_order_id' => "pi_test_{$i}",
            ]);
        }
    }

    private function clearTables(): void
    {
        $tables = [
            'oxorder',
            'oxorderarticles',
            'osc_transaction',
            'oxuser',
            'oxarticles',
            'oxcategories',
        ];

        foreach ($tables as $table) {
            $this->pdo->exec("TRUNCATE TABLE {$table}");
        }
    }

    private function createProduct(string $id, string $title, float $price): void
    {
        $sql = "INSERT INTO oxarticles (OXID, OXTITLE, OXPRICE) VALUES (:id, :title, :price)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'title' => $title, 'price' => $price]);
    }

    private function createUser(string $email, string $name, string $role = 'user'): void
    {
        $sql = "INSERT INTO oxuser (OXID, OXUSERNAME, OXFNAME) VALUES (:id, :email, :name)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => uniqid('user_'), 'email' => $email, 'name' => $name]);
    }

    private function createOrder(array $data): void
    {
        $sql = "INSERT INTO oxorder (OXID, OXTOTALORDERSUM, OXTRANSSTATUS, payment_provider_order_id)
                VALUES (:oxid, :oxtotalordersum, :oxtransstatus, :payment_provider_order_id)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    private function createCategory(string $name): void
    {
        $sql = "INSERT INTO oxcategories (OXID, OXTITLE) VALUES (:id, :title)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => uniqid('cat_'), 'title' => $name]);
    }
}
```

**Usage in E2E Tests:**

```php
// Seed database for checkout scenario
$seeder = new DatabaseSeeder($pdo);
$seeder->seed('checkout');

// Seed for admin capture scenario
$seeder->seed('admin-capture');
```

---

## Mocking Strategy

### 1. Mock External APIs (Provider APIs)

**Strategy:**
- **Unit Tests:** Full mocks using Mockery
- **Integration Tests:** WireMock for HTTP mocking
- **E2E Tests:** Provider sandbox environments

**Example: Mocking Stripe API in Unit Tests**

```php
use Mockery;
use Stripe\StripeClient;
use Stripe\PaymentIntent;

// Mock Stripe client
$stripeMock = Mockery::mock(StripeClient::class);

// Mock createPaymentIntent method
$stripeMock->paymentIntents = Mockery::mock();
$stripeMock->paymentIntents
    ->shouldReceive('create')
    ->once()
    ->with([
        'amount' => 9999,
        'currency' => 'usd',
        'payment_method_types' => ['card'],
    ])
    ->andReturn(new PaymentIntent([
        'id' => 'pi_test_123',
        'status' => 'requires_capture',
        'amount' => 9999,
    ]));

// Inject into service
$paymentService->setStripeClient($stripeMock);
```

**Example: WireMock for Integration Tests**

```yaml
# tests/wiremock/stripe-create-payment-intent.json
{
  "request": {
    "method": "POST",
    "url": "/v1/payment_intents"
  },
  "response": {
    "status": 200,
    "headers": {
      "Content-Type": "application/json"
    },
    "jsonBody": {
      "id": "pi_test_123",
      "object": "payment_intent",
      "amount": 9999,
      "currency": "usd",
      "status": "requires_capture"
    }
  }
}
```

```bash
# Start WireMock
docker run -d -p 8080:8080 \
  -v $(pwd)/tests/wiremock:/home/wiremock \
  wiremock/wiremock
```

---

### 2. Mock Internal Services

**Example: Mocking EventDispatcher**

```php
use Mockery;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use PaymentComponent\Event\PaymentCapturedEvent;

$dispatcherMock = Mockery::mock(EventDispatcherInterface::class);

// Verify event is dispatched
$dispatcherMock
    ->shouldReceive('dispatch')
    ->once()
    ->with(Mockery::type(PaymentCapturedEvent::class))
    ->andReturnUsing(function ($event) {
        // Can also inspect event properties
        $this->assertEquals('order-123', $event->getOrderId());
        return $event;
    });

// Inject into handler
$handler = new PaymentCaptureHandler(
    $paymentService,
    $orderRepository,
    $dispatcherMock
);
```

**Example: Mocking Logger**

```php
use Psr\Log\LoggerInterface;
use Mockery;

$loggerMock = Mockery::mock(LoggerInterface::class);

// Verify logging calls
$loggerMock
    ->shouldReceive('info')
    ->once()
    ->with('Payment captured', Mockery::on(function ($context) {
        return isset($context['order_id']) && isset($context['capture_id']);
    }));

// Inject into service
$paymentService = new PaymentService($loggerMock, ...);
```

---

### 3. Mock Repositories (Unit Tests Only)

**Example: Mocking OrderRepository**

```php
use Mockery;
use PaymentComponent\Repository\OrderRepository;
use PaymentComponent\Model\Order;

$orderRepoMock = Mockery::mock(OrderRepository::class);

// Mock getById
$order = OrderBuilder::new()->authorized()->build();
$orderRepoMock
    ->shouldReceive('getById')
    ->once()
    ->with('order-123')
    ->andReturn($order);

// Mock save
$orderRepoMock
    ->shouldReceive('save')
    ->once()
    ->with(Mockery::type(Order::class))
    ->andReturn(true);

// Inject into handler
$handler = new PaymentCaptureHandler($paymentService, $orderRepoMock, $dispatcher);
```

---

## Coverage Goals

**Note:** Coverage requirements differ between component and provider tests. See [09-test-organization.md](09-test-organization.md) for detailed test organization strategy.

### Overall Coverage Targets

| Metric | Component Target | Provider Target | Current | Status |
|--------|------------------|-----------------|---------|--------|
| **Line Coverage** | 95% | 90% | TBD | 🟡 In Progress |
| **Branch Coverage** | 90% | 85% | TBD | 🟡 In Progress |
| **Method Coverage** | 95% | 90% | TBD | 🟡 In Progress |

**Rationale:**
- **Component Tests**: Higher coverage (95%+) because they test provider-agnostic business logic without external dependencies. Fast execution enables comprehensive testing.
- **Provider Tests**: Slightly lower coverage (90%+) because they test adapter implementations with real SDK integration. External dependencies and API limitations may prevent 100% coverage.

### Coverage by Component/Provider

| Component/Provider | Line Coverage | Branch Coverage | Test Location | Priority |
|-------------------|---------------|-----------------|---------------|----------|
| **Component (Core)** | 95% | 90% | `tests/Component/` | 🔴 Critical |
| **Stripe Adapter** | 90% | 85% | `tests/Stripe/` | 🟡 Medium |
| **Unzer Adapter** | 90% | 85% | `tests/Unzer/` | 🟡 Medium |
| **PayPal Adapter** | 90% | 85% | `tests/PayPal/` | 🟡 Medium |

### Coverage by Layer (Component Tests Only)

| Layer | Line Coverage | Branch Coverage | Priority |
|-------|---------------|-----------------|----------|
| **Event Layer** | 100% | 100% | 🔴 Critical |
| **Domain Layer** | 95% | 90% | 🔴 Critical |
| **Service Layer** | 95% | 90% | 🔴 Critical |
| **Repository Layer** | 100% | 100% | 🔴 Critical |
| **Adapter Interface** | 100% | 100% | 🔴 Critical |
| **Factory Layer** | 90% | 85% | 🟡 High |
| **Controller Layer** | 85% | 80% | 🟡 High |
| **Webhook System** | 100% | 100% | 🔴 Critical |

### Generating Coverage Reports

**Component Tests:**
```bash
# Generate HTML coverage report for component
vendor/bin/phpunit --testsuite=Component --coverage-html coverage/component/

# Generate Clover XML for CI
vendor/bin/phpunit --testsuite=Component --coverage-clover coverage-component.xml

# Check coverage threshold (fail if < 95%)
vendor/bin/phpunit --testsuite=Component --coverage-text --coverage-clover=coverage-component.xml
php coverage-check.php coverage-component.xml 95
```

**Provider Tests:**
```bash
# Generate HTML coverage report for Stripe adapter
vendor/bin/phpunit --testsuite=Stripe --coverage-html coverage/stripe/

# Generate Clover XML for Stripe
vendor/bin/phpunit --testsuite=Stripe --coverage-clover coverage-stripe.xml

# Check coverage threshold (fail if < 90%)
vendor/bin/phpunit --testsuite=Stripe --coverage-text --coverage-clover=coverage-stripe.xml
php coverage-check.php coverage-stripe.xml 90

# Repeat for Unzer, PayPal, etc.
```

**Combined Coverage:**
```bash
# Generate combined coverage report
vendor/bin/phpunit --coverage-html coverage/all/ --coverage-clover coverage-all.xml
```

---

## CI/CD Pipeline

**Test Organization Note:** CI/CD pipeline now includes separate jobs for component tests and provider tests. See [09-test-organization.md](09-test-organization.md) for complete test separation strategy.

### GitHub Actions Workflow

```yaml
# .github/workflows/tests.yml

name: Test Suite

on: [push, pull_request]

jobs:
  component-tests:
    name: Component Tests (Provider-Agnostic)
    runs-on: ubuntu-latest
    timeout-minutes: 5

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: test_payment
        ports:
          - 3306:3306

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, xml, pdo, pdo_mysql
          coverage: xdebug

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run migrations
        run: php migrations/run.php

      - name: Run component tests (Unit + Integration)
        run: vendor/bin/phpunit --testsuite=Component --coverage-clover=coverage-component.xml

      - name: Check coverage threshold (95%)
        run: php coverage-check.php coverage-component.xml 95

      - name: Upload component coverage
        uses: codecov/codecov-action@v3
        with:
          files: ./coverage-component.xml
          flags: component

  stripe-adapter-tests:
    name: Stripe Adapter Tests
    runs-on: ubuntu-latest
    timeout-minutes: 10

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, xml
          coverage: xdebug

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run Stripe adapter tests
        env:
          STRIPE_SECRET_KEY: ${{ secrets.STRIPE_TEST_SECRET_KEY }}
          STRIPE_WEBHOOK_SECRET: ${{ secrets.STRIPE_TEST_WEBHOOK_SECRET }}
        run: vendor/bin/phpunit --testsuite=Stripe --coverage-clover=coverage-stripe.xml

      - name: Check coverage threshold (90%)
        run: php coverage-check.php coverage-stripe.xml 90

      - name: Upload Stripe coverage
        uses: codecov/codecov-action@v3
        with:
          files: ./coverage-stripe.xml
          flags: stripe

  unzer-adapter-tests:
    name: Unzer Adapter Tests
    runs-on: ubuntu-latest
    timeout-minutes: 10

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, xml
          coverage: xdebug

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run Unzer adapter tests
        env:
          UNZER_PRIVATE_KEY: ${{ secrets.UNZER_TEST_PRIVATE_KEY }}
          UNZER_PUBLIC_KEY: ${{ secrets.UNZER_TEST_PUBLIC_KEY }}
        run: vendor/bin/phpunit --testsuite=Unzer --coverage-clover=coverage-unzer.xml

      - name: Check coverage threshold (90%)
        run: php coverage-check.php coverage-unzer.xml 90

      - name: Upload Unzer coverage
        uses: codecov/codecov-action@v3
        with:
          files: ./coverage-unzer.xml
          flags: unzer

  e2e-tests:
    name: End-to-End Tests
    runs-on: ubuntu-latest
    timeout-minutes: 20
    needs: [component-tests, stripe-adapter-tests]

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Start application
        run: |
          php -S localhost:8000 -t public &
          sleep 5

      - name: Install Playwright
        run: npx playwright install --with-deps

      - name: Run E2E tests
        run: vendor/bin/codecept run e2e
        env:
          STRIPE_TEST_KEY: ${{ secrets.STRIPE_TEST_KEY }}
          PAYPAL_SANDBOX_CLIENT_ID: ${{ secrets.PAYPAL_SANDBOX_CLIENT_ID }}
```

---

## Best Practices

### 1. Test Naming Conventions

**Format:** `test{MethodName}{Scenario}{ExpectedOutcome}`

**Examples:**
- ✅ `testCreatePaymentOrderCallsProviderApi()`
- ✅ `testMarkAsPaymentCompletedSetsOxpaidTimestamp()`
- ✅ `testHandleCaptureThrowsExceptionForInvalidState()`

**Avoid:**
- ❌ `testOrder()` (too vague)
- ❌ `testPayment1()` (meaningless)

---

### 2. AAA Pattern (Arrange-Act-Assert)

```php
public function testExampleMethod(): void
{
    // Arrange - Set up test data and mocks
    $order = OrderBuilder::new()->authorized()->build();
    $service = new PaymentService(...);

    // Act - Execute the method under test
    $result = $service->capturePayment($order);

    // Assert - Verify the expected outcome
    $this->assertTrue($result->isSuccess());
    $this->assertEquals('CAPTURED', $result->getStatus());
}
```

---

### 3. One Assertion Focus Per Test

**Good:**
```php
public function testOrderStateTransitionToCompleted(): void
{
    $order = OrderBuilder::new()->authorized()->build();
    $order->markAsPaymentCompleted();

    $this->assertEquals('COMPLETED', $order->getPaymentState());
}

public function testOrderPaidTimestampSetOnCompletion(): void
{
    $order = OrderBuilder::new()->authorized()->build();
    $order->markAsPaymentCompleted();

    $this->assertNotNull($order->getOxpaid());
}
```

**Also Acceptable (related assertions):**
```php
public function testOrderCompletionSetsMultipleFields(): void
{
    $order = OrderBuilder::new()->authorized()->build();
    $order->markAsPaymentCompleted();

    $this->assertEquals('COMPLETED', $order->getPaymentState());
    $this->assertNotNull($order->getOxpaid());
    $this->assertTrue($order->isOrderPaid());
}
```

---

### 4. Use Data Providers for Multiple Scenarios

```php
/**
 * @dataProvider amountCalculationProvider
 */
public function testRefundableAmount(
    float $total,
    float $refunded,
    float $expected
): void {
    $order = OrderBuilder::new()
        ->withAmount($total)
        ->build();
    $order->setRefundedAmount($refunded);

    $this->assertEquals($expected, $order->getRefundableAmount());
}

public function amountCalculationProvider(): array
{
    return [
        'No refunds' => [100.00, 0.00, 100.00],
        'Partial refund' => [100.00, 30.00, 70.00],
        'Full refund' => [100.00, 100.00, 0.00],
        'Over-refund prevented' => [100.00, 150.00, 0.00],
    ];
}
```

---

### 5. Test Isolation

**Each test must be independent:**

```php
// ✅ Good - Test creates its own data
public function testExample(): void
{
    $order = OrderBuilder::new()->build();
    // Test logic
}

// ❌ Bad - Test depends on previous test
private Order $sharedOrder;

public function testCreate(): void
{
    $this->sharedOrder = new Order();
}

public function testUpdate(): void
{
    $this->sharedOrder->update(); // Depends on testCreate
}
```

---

### 6. Meaningful Assertion Messages

```php
// ✅ Good - Clear failure message
$this->assertEquals(
    'CAPTURED',
    $order->getPaymentState(),
    'Order state should be CAPTURED after successful payment'
);

// ✅ Good - Use assertSame for strict comparison
$this->assertSame(
    99.99,
    $order->getTotalAmount(),
    'Total amount should match exactly (float comparison)'
);

// ❌ Bad - No message
$this->assertEquals('CAPTURED', $order->getPaymentState());
```

---

### 7. Cleanup in tearDown()

```php
protected function tearDown(): void
{
    // Rollback database transaction (integration tests)
    if (self::$pdo && self::$pdo->inTransaction()) {
        self::$pdo->rollBack();
    }

    // Close Mockery (unit tests)
    Mockery::close();

    // Clean up files/temp data
    $this->cleanupTestFiles();

    parent::tearDown();
}
```

---

### 8. Skip Slow Tests in Development

```php
/**
 * @group slow
 * @group e2e
 */
public function testCompleteCheckoutFlow(): void
{
    // E2E test that takes 10 seconds
}
```

```bash
# Run only fast tests during development
vendor/bin/phpunit --exclude-group=slow

# Run all tests before committing
vendor/bin/phpunit
```

---

## Summary

This TDD strategy provides:

✅ **60% Unit Tests** - Fast, isolated, comprehensive coverage
✅ **30% Integration Tests** - Real database, event flow verification
✅ **10% E2E Tests** - Critical user flows, full system validation
✅ **85%+ Coverage** - High confidence in code quality
✅ **Fast Feedback** - Unit tests in < 5 seconds
✅ **CI/CD Pipeline** - Automated testing on every commit
✅ **Clear Fixtures** - Builders, factories, seeders for all test types
✅ **Mocking Strategy** - Appropriate mocking at each layer

**Next Steps:**

1. Set up test infrastructure (PHPUnit, TestContainers, Codeception)
2. Create fixture builders and factories
3. Write unit tests for critical components (TDD: Red → Green → Refactor)
4. Add integration tests for event flows
5. Implement E2E tests for critical paths
6. Set up CI/CD pipeline
7. Monitor coverage and maintain 85%+ target

---

**Visual Diagram:** [puml/10-tdd-strategy.puml](puml/10-tdd-strategy.puml)

**Version:** 1.0.0
**Last Updated:** 2025-10-13
**Author:** Payment Component Team

---

## Series Complete

This completes the 8-part TDD Strategy series. For the complete index of all parts, see:

**[TDD Strategy Index](09-tdd-strategy-index.md)** - Complete navigation guide

---

**Version:** 2.1.0
**Last Updated:** 2025-10-16
