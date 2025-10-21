# TDD Strategy - Part 6 of 8: Checkout Frontend & Admin Features

**Version:** 2.1.0
**Date:** 2025-10-16
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)

**Part of Series:**
- [Part 1](09-01-tdd-overview.md): Overview, Test Organization, Priority Classification, Payment Security
- [Part 2](09-02-tdd-data-persistence.md): Data Persistence & Integrity
- [Part 3](09-03-tdd-event-system.md): Event System & Business Logic, Service Layer
- [Part 4](09-04-tdd-provider-integration.md): Provider Integration, SDK-Adapter Layer
- [Part 5](09-05-tdd-authorization-flow.md): Two-Step Authorization Flow, Webhook Processing
- **Part 6** (This document): Checkout Frontend, Admin Features
- [Part 7](09-07-tdd-test-pyramid.md): Test Pyramid Strategy, Unit/Integration/E2E Tests, Fixtures
- [Part 8](09-08-tdd-mocking-coverage.md): Mocking Strategy, Coverage Goals, CI/CD, Best Practices

---

        // Arrange
        $order = new Order();
        $order->setOxid('test-order-id');
        $order->markAsPaymentInProgress();

        // Act
        $order->markAsPaymentCompleted();

        // Assert
        $this->assertEquals('COMPLETED', $order->getPaymentState());
        $this->assertFalse($order->isAwaitingPayment());
        $this->assertTrue($order->isOrderPaid());
        $this->assertNotNull($order->getOxpaid());
    }

    public function testCannotCaptureUnauthorizedOrder(): void
    {
        // Arrange
        $order = new Order();
        $order->setOxid('test-order-id');

        // Act & Assert
        $this->assertFalse($order->canBeCaptured());
    }

    public function testOrderStateTransitionValidation(): void
    {
        // Arrange
        $order = new Order();
        $order->setOxid('test-order-id');

        // Act & Assert
        $this->expectException(\InvalidStateException::class);
        $order->markAsPaymentCompleted(); // Cannot complete without in-progress state
    }

    /**
     * @dataProvider amountCalculationProvider
     */
    public function testRefundableAmountCalculation(
        float $totalAmount,
        float $refundedAmount,
        float $expectedRefundable
    ): void {
        // Arrange
        $order = new Order();
        $order->setOxtotalordersum($totalAmount);
        $order->setRefundedAmount($refundedAmount);

        // Act
        $refundable = $order->getRefundableAmount();

        // Assert
        $this->assertEquals($expectedRefundable, $refundable);
    }

    public function amountCalculationProvider(): array
    {
        return [
            'No refunds' => [100.00, 0.00, 100.00],
            'Partial refund' => [100.00, 30.00, 70.00],
            'Full refund' => [100.00, 100.00, 0.00],
        ];
    }
}
```

**Test Cases:**
- ✓ Order state transitions (NOT_FINISHED → IN_PROGRESS → COMPLETED)
- ✓ State validation (cannot skip states)
- ✓ Payment completion sets oxpaid timestamp
- ✓ Refundable amount calculation
- ✓ Order finalization workflow
- ✓ Transaction ID assignment
- ✓ Email sending flag

---

#### 3. Service Layer (90% coverage)

**Test Files:**
- `tests/Unit/Service/PaymentServiceTest.php`
- `tests/Unit/Service/OrderManagerTest.php`
- `tests/Unit/Service/ModuleSettingsTest.php`

**Example: PaymentService Create Order Test**

```php
<?php
// tests/Unit/Service/PaymentServiceTest.php

namespace PaymentComponent\Tests\Unit\Service;

use PaymentComponent\Service\PaymentService;
use PaymentComponent\Repository\OrderRepository;
use PaymentComponent\Service\ModuleSettings;
use PaymentComponent\Factory\OrderRequestFactory;
use PaymentComponent\Model\Basket;
use PaymentComponent\Model\ProviderOrder;
use Mockery;
use PHPUnit\Framework\TestCase;

class PaymentServiceTest extends TestCase
{
    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    private PaymentService $service;
    private OrderRepository $orderRepoMock;
    private ModuleSettings $settingsMock;
    private OrderRequestFactory $factoryMock;

    protected function setUp(): void
    {
        $this->orderRepoMock = Mockery::mock(OrderRepository::class);
        $this->settingsMock = Mockery::mock(ModuleSettings::class);
        $this->factoryMock = Mockery::mock(OrderRequestFactory::class);

        $this->service = new PaymentService(
            $this->orderRepoMock,
            $this->settingsMock,
            $this->factoryMock
        );
    }

    public function testCreatePaymentOrderCallsProviderApi(): void
    {
        // Arrange
        $basket = $this->createMockBasket(99.99);
        $providerResponse = new ProviderOrder('pi_123', 'requires_capture', 99.99);

        $this->settingsMock
            ->shouldReceive('getCaptureStrategy')
            ->once()
            ->andReturn('direct');

        $this->factoryMock
            ->shouldReceive('buildOrderRequest')
            ->once()
            ->with($basket, 'capture', [])
            ->andReturn(['intent' => 'capture', 'amount' => 9999]);

        // Mock provider API client
        $apiClientMock = Mockery::mock('ApiClient');
        $apiClientMock
            ->shouldReceive('createOrder')
            ->once()
            ->with(['intent' => 'capture', 'amount' => 9999])
            ->andReturn($providerResponse);

        $this->service->setApiClient($apiClientMock);

        // Act
        $result = $this->service->createPaymentOrder($basket, 'capture', []);

        // Assert
        $this->assertInstanceOf(ProviderOrder::class, $result);
        $this->assertEquals('pi_123', $result->getId());
        $this->assertEquals(99.99, $result->getAmount());
    }

    public function testTrackTransactionPersistsToDatabase(): void
    {
        // Arrange
        $orderId = 'order-123';
        $providerOrderId = 'pi_123';
        $transactionId = 'ch_456';

        $this->orderRepoMock
            ->shouldReceive('saveTransaction')
            ->once()
            ->with(Mockery::on(function ($transaction) use ($orderId, $providerOrderId, $transactionId) {
                return $transaction->getShopOrderId() === $orderId
                    && $transaction->getProviderOrderId() === $providerOrderId
                    && $transaction->getTransactionId() === $transactionId
                    && $transaction->getStatus() === 'CAPTURED';
            }))
            ->andReturn(true);

        // Act
        $result = $this->service->trackTransaction(
            $orderId,
            $providerOrderId,
            'card',
            'CAPTURED',
            $transactionId,
            'capture'
        );

        // Assert
        $this->assertInstanceOf(PaymentTransaction::class, $result);
    }

    public function testCapturePaymentHandlesProviderErrors(): void
    {
        // Arrange
        $order = $this->createMockOrder('order-123');
        $providerOrderId = 'pi_123';

        $apiClientMock = Mockery::mock('ApiClient');
        $apiClientMock
            ->shouldReceive('capturePayment')
            ->once()
            ->with($providerOrderId)
            ->andThrow(new \PaymentProviderException('Insufficient funds'));

        $this->service->setApiClient($apiClientMock);

        // Act & Assert
        $this->expectException(\PaymentException::class);
        $this->expectExceptionMessage('Payment capture failed: Insufficient funds');

        $this->service->capturePayment($order, $providerOrderId, 'card');
    }

    private function createMockBasket(float $amount): Basket
    {
        $basket = Mockery::mock(Basket::class);
        $basket->shouldReceive('getPaymentTotal')->andReturn($amount);
        $basket->shouldReceive('getCurrency')->andReturn('USD');
        return $basket;
    }

    private function createMockOrder(string $orderId): Order
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('getId')->andReturn($orderId);
        $order->shouldReceive('getTotalAmount')->andReturn(99.99);
        return $order;
    }
}
```

**Test Cases:**
- ✓ Payment order creation calls provider API
- ✓ Transaction tracking persists to database
- ✓ Payment capture workflow
- ✓ Payment authorization workflow
- ✓ Provider API error handling
- ✓ SCA validation logic
- ✓ Capture strategy selection
- ✓ Session cleanup

---

#### 4. Event Handlers (95% coverage)

**Test Files:**
- `tests/Unit/EventHandler/PaymentInitiationHandlerTest.php`
- `tests/Unit/EventHandler/PaymentCaptureHandlerTest.php`
- `tests/Unit/EventHandler/PaymentRefundHandlerTest.php`

**Example: Payment Capture Handler Test**

```php
<?php
// tests/Unit/EventHandler/PaymentCaptureHandlerTest.php

namespace PaymentComponent\Tests\Unit\EventHandler;

use PaymentComponent\EventHandler\PaymentCaptureHandler;
use PaymentComponent\Event\CaptureRequestedEvent;
use PaymentComponent\Event\PaymentCapturedEvent;
use PaymentComponent\Service\PaymentService;
use PaymentComponent\Repository\OrderRepository;
use PaymentComponent\Model\Order;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class PaymentCaptureHandlerTest extends TestCase
{
    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    private PaymentCaptureHandler $handler;
    private PaymentService $paymentServiceMock;
    private OrderRepository $orderRepoMock;
    private EventDispatcherInterface $dispatcherMock;

    protected function setUp(): void
    {
        $this->paymentServiceMock = Mockery::mock(PaymentService::class);
        $this->orderRepoMock = Mockery::mock(OrderRepository::class);
        $this->dispatcherMock = Mockery::mock(EventDispatcherInterface::class);

        $this->handler = new PaymentCaptureHandler(
            $this->paymentServiceMock,
            $this->orderRepoMock,
            $this->dispatcherMock
        );
    }

    public function testHandleCapturesPaymentAndEmitsEvent(): void
    {
        // Arrange
        $orderId = 'order-123';
        $providerOrderId = 'pi_123';
        $captureId = 'ch_456';
        $amount = 99.99;

        $event = new CaptureRequestedEvent($orderId, $amount, 'admin', 'idempotency-key-123');

        $order = Mockery::mock(Order::class);
        $order->shouldReceive('isAwaitingCapture')->once()->andReturn(true);
        $order->shouldReceive('getProviderOrderId')->once()->andReturn($providerOrderId);
        $order->shouldReceive('getTotalAmount')->once()->andReturn(99.99);
        $order->shouldReceive('getId')->andReturn($orderId);

        $this->orderRepoMock
            ->shouldReceive('getById')
            ->once()
            ->with($orderId)
            ->andReturn($order);

        $captureResult = new CaptureResult($captureId, $amount, 'CAPTURED');

        $this->paymentServiceMock
            ->shouldReceive('capturePayment')
            ->once()
            ->with($providerOrderId, $amount)
            ->andReturn($captureResult);

        $this->paymentServiceMock
            ->shouldReceive('trackTransaction')
            ->once();

        $this->dispatcherMock
            ->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(PaymentCapturedEvent::class));

        // Act
        $this->handler->handle($event);

        // Assert - via Mockery expectations
    }

    public function testHandleThrowsExceptionForInvalidState(): void
    {
        // Arrange
        $orderId = 'order-123';
        $event = new CaptureRequestedEvent($orderId, 99.99, 'admin', 'key-123');

        $order = Mockery::mock(Order::class);
        $order->shouldReceive('isAwaitingCapture')->once()->andReturn(false);

        $this->orderRepoMock
            ->shouldReceive('getById')
            ->once()
            ->with($orderId)
            ->andReturn($order);

        // Act & Assert
        $this->expectException(\InvalidStateException::class);
        $this->expectExceptionMessage('Order not in AUTHORIZED state');

        $this->handler->handle($event);
    }

    public function testHandleSkipsDuplicateCaptureWithSameIdempotencyKey(): void
    {
        // Arrange
        $orderId = 'order-123';
        $idempotencyKey = 'key-123';
        $event = new CaptureRequestedEvent($orderId, 99.99, 'admin', $idempotencyKey);

        $order = Mockery::mock(Order::class);
        $order->shouldReceive('isAwaitingCapture')->once()->andReturn(true);
        $order->shouldReceive('getId')->andReturn($orderId);

        $this->orderRepoMock
            ->shouldReceive('getById')
            ->once()
            ->andReturn($order);

        $this->orderRepoMock
            ->shouldReceive('existsByIdempotencyKey')
            ->once()
            ->with($orderId, $idempotencyKey, 'capture')
            ->andReturn(true);

        // Should not call payment service
        $this->paymentServiceMock->shouldNotReceive('capturePayment');
        $this->dispatcherMock->shouldNotReceive('dispatch');

        // Act
        $this->handler->handle($event);

        // Assert - idempotency check prevents duplicate
    }
}
```

**Test Cases:**
- ✓ Handler captures payment and emits success event
- ✓ Handler validates order state before capture
- ✓ Handler prevents duplicate captures via idempotency key
- ✓ Handler handles provider API errors gracefully
- ✓ Handler emits PaymentFailedEvent on error
- ✓ Handler updates transaction status in database
- ✓ Handler logs operations for audit

---

#### 5. Factory Layer (85% coverage)

**Test Files:**
- `tests/Unit/Factory/OrderRequestFactoryTest.php`
- `tests/Unit/Factory/PurchaseUnitsFactoryTest.php`

**Example: OrderRequestFactory Test**

```php
<?php
// tests/Unit/Factory/OrderRequestFactoryTest.php

namespace PaymentComponent\Tests\Unit\Factory;

use PaymentComponent\Factory\OrderRequestFactory;
use PaymentComponent\Model\Basket;
use PHPUnit\Framework\TestCase;

class OrderRequestFactoryTest extends TestCase
{
    private OrderRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new OrderRequestFactory();
    }

    public function testBuildOrderRequestWithCaptureIntent(): void
    {
        // Arrange
        $basket = $this->createMockBasket(99.99, 'USD');

        // Act
        $request = $this->factory->buildOrderRequest($basket, 'capture', []);

        // Assert
        $this->assertEquals('capture', $request['intent']);
        $this->assertEquals(9999, $request['amount']); // cents
        $this->assertEquals('USD', $request['currency']);
        $this->assertArrayHasKey('purchase_units', $request);
    }

    public function testBuildOrderRequestWithAuthorizeIntent(): void
    {
        // Arrange
        $basket = $this->createMockBasket(150.50, 'EUR');

        // Act
        $request = $this->factory->buildOrderRequest($basket, 'authorize', []);

        // Assert
        $this->assertEquals('authorize', $request['intent']);
        $this->assertEquals(15050, $request['amount']);
        $this->assertEquals('EUR', $request['currency']);
    }

    public function testIncludesReturnUrlsInRequest(): void
    {
        // Arrange
        $basket = $this->createMockBasket(99.99, 'USD');
        $options = [
            'return_url' => 'https://shop.com/success',
            'cancel_url' => 'https://shop.com/cancel',
        ];

        // Act
        $request = $this->factory->buildOrderRequest($basket, 'capture', $options);

        // Assert
        $this->assertEquals('https://shop.com/success', $request['return_url']);
        $this->assertEquals('https://shop.com/cancel', $request['cancel_url']);
    }

    private function createMockBasket(float $amount, string $currency): Basket
    {
        $basket = Mockery::mock(Basket::class);
        $basket->shouldReceive('getPaymentTotal')->andReturn($amount);
        $basket->shouldReceive('getCurrency')->andReturn($currency);
        $basket->shouldReceive('getItems')->andReturn([]);
        return $basket;
    }
}
```

**Test Cases:**
- ✓ Request building with capture intent
- ✓ Request building with authorize intent
- ✓ Amount conversion (dollars to cents)
- ✓ Currency code inclusion
- ✓ Return/cancel URLs inclusion
- ✓ Purchase units array structure
- ✓ Line items formatting

---

#### 6. Repository Layer (Unit Tests Only - No DB)

**Test Files:**
- `tests/Unit/Repository/OrderRepositoryTest.php`

**Example: OrderRepository Query Building Test**

```php
<?php
// tests/Unit/Repository/OrderRepositoryTest.php

namespace PaymentComponent\Tests\Unit\Repository;

use PaymentComponent\Repository\OrderRepository;
use Doctrine\DBAL\Query\QueryBuilder;
use Mockery;
use PHPUnit\Framework\TestCase;

class OrderRepositoryTest extends TestCase
{
    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    private OrderRepository $repository;
    private QueryBuilder $queryBuilderMock;

    protected function setUp(): void
    {
        $this->queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $this->repository = new OrderRepository($this->queryBuilderMock);
    }

    public function testGetByIdBuildsCorrectQuery(): void
    {
        // Arrange
        $orderId = 'order-123';

        $this->queryBuilderMock
            ->shouldReceive('select')->once()->with('*')->andReturnSelf()
            ->shouldReceive('from')->once()->with('oxorder')->andReturnSelf()
            ->shouldReceive('where')->once()->with('OXID = :oxid')->andReturnSelf()
            ->shouldReceive('setParameter')->once()->with('oxid', $orderId)->andReturnSelf()
            ->shouldReceive('executeQuery')->once()->andReturn($resultMock);

        $resultMock = Mockery::mock('Result');
        $resultMock->shouldReceive('fetchAssociative')->once()->andReturn([
            'OXID' => $orderId,
            'OXTOTALORDERSUM' => 99.99,
        ]);

        // Act
        $order = $this->repository->getById($orderId);

        // Assert
        $this->assertEquals($orderId, $order->getId());
    }

    // More query building tests...
}
```

**Note:** Repository layer will also have **integration tests with real database** (see Integration Tests section).

---

### Unit Test Best Practices

1. **Naming Convention:**
   - Test class: `{ClassName}Test.php`
   - Test method: `test{MethodName}{Scenario}(): void`
   - Example: `testCreatePaymentOrderCallsProviderApi()`

2. **AAA Pattern:**
   - **Arrange:** Set up test data and mocks
   - **Act:** Execute the method under test
   - **Assert:** Verify the expected outcome

3. **One Assertion Focus:**
   - Each test should focus on one behavior
   - Multiple assertions are OK if testing same behavior

4. **Use Data Providers:**
   - For testing multiple scenarios with same logic
   - Reduces code duplication

5. **Mock External Dependencies:**
   - Never hit real database, APIs, or filesystem
   - Use Mockery for method expectations

---

## Integration Tests (30%)

### Purpose

Integration tests verify **component interactions** with:
- Real database (using TestContainers)
- Mocked external APIs (using WireMock)
- Event dispatcher behavior
- Multi-step workflows

### Setup Requirements

```bash
# Install TestContainers for PHP
composer require --dev testcontainers/testcontainers

# Install WireMock for API mocking
docker pull wiremock/wiremock
```

### Test Database Configuration

```php
<?php
// tests/Integration/DatabaseTestCase.php

namespace PaymentComponent\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Testcontainers\Container\MySQLContainer;

abstract class DatabaseTestCase extends TestCase
{
    protected static MySQLContainer $dbContainer;
    protected static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        // Start MySQL container
        self::$dbContainer = MySQLContainer::make('mysql:8.0')
            ->withDatabase('test_payment')
            ->withUsername('test')
            ->withPassword('test');

        self::$dbContainer->start();

        // Connect to database
        self::$pdo = new \PDO(
            self::$dbContainer->getConnectionString(),
            'test',
            'test'
        );

        // Run migrations
        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        self::$dbContainer->stop();
    }

    protected function setUp(): void
    {
        // Start transaction
        self::$pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Rollback transaction (clean slate for next test)
        self::$pdo->rollBack();
    }

    private static function runMigrations(): void
    {
        // Read and execute migration SQL
        $sql = file_get_contents(__DIR__ . '/../../migrations/001_payment_transaction.sql');
        self::$pdo->exec($sql);
    }
}
```

---

### Integration Test Examples

#### 1. Repository Integration Tests

**Test File:** `tests/Integration/Repository/OrderRepositoryIntegrationTest.php`

```php
<?php
namespace PaymentComponent\Tests\Integration\Repository;

use PaymentComponent\Tests\Integration\DatabaseTestCase;
use PaymentComponent\Repository\OrderRepository;
use PaymentComponent\Model\Order;
use PaymentComponent\Model\PaymentTransaction;

class OrderRepositoryIntegrationTest extends DatabaseTestCase
{
    private OrderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OrderRepository(self::$pdo);
    }

    public function testSaveAndRetrieveOrder(): void
    {
        // Arrange
        $order = new Order();
        $order->setOxid('test-order-1');
        $order->setOxtotalordersum(99.99);
        $order->setOxordernr('100001');

        // Act
        $this->repository->save($order);
        $retrieved = $this->repository->getById('test-order-1');

        // Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals('test-order-1', $retrieved->getId());
        $this->assertEquals(99.99, $retrieved->getTotalAmount());
    }

    public function testGetOrderByProviderOrderId(): void
    {
        // Arrange
        $order = $this->createTestOrder('order-1', 'pi_123');
        $this->repository->save($order);

        $transaction = new PaymentTransaction();
        $transaction->setShopOrderId('order-1');
        $transaction->setProviderOrderId('pi_123');
        $transaction->setStatus('CAPTURED');
        $this->repository->saveTransaction($transaction);

        // Act
        $retrieved = $this->repository->getOrderByProviderOrderId('pi_123');

        // Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals('order-1', $retrieved->getId());
    }

    public function testCleanupAbandonedOrders(): void
    {
        // Arrange - Create old abandoned order
        $oldOrder = $this->createTestOrder('old-order', null);
        $oldOrder->setOxtransstatus('NOT_FINISHED');
        $oldOrder->setOxorderdate(date('Y-m-d H:i:s', strtotime('-2 days')));
        $this->repository->save($oldOrder);

        // Create recent order
        $recentOrder = $this->createTestOrder('recent-order', null);
        $recentOrder->setOxtransstatus('NOT_FINISHED');
        $this->repository->save($recentOrder);

        // Act
        $this->repository->cleanUpAbandonedOrders(24); // 24 hours threshold

        // Assert
        $this->assertNull($this->repository->getById('old-order'));
        $this->assertNotNull($this->repository->getById('recent-order'));
    }

    public function testGetTransactionsByOrderId(): void
    {
        // Arrange
        $orderId = 'order-multi-tx';
        $order = $this->createTestOrder($orderId, 'pi_123');
        $this->repository->save($order);

        // Create multiple transactions
        $this->createAndSaveTransaction($orderId, 'pi_123', 'auth_1', 'authorization');
        $this->createAndSaveTransaction($orderId, 'pi_123', 'cap_1', 'capture');

        // Act
        $transactions = $this->repository->getTransactionsByOrderId($orderId);

        // Assert
        $this->assertCount(2, $transactions);
        $this->assertEquals('authorization', $transactions[0]->getTransactionType());
        $this->assertEquals('capture', $transactions[1]->getTransactionType());
    }

    private function createTestOrder(string $id, ?string $providerOrderId): Order
    {
        $order = new Order();
        $order->setOxid($id);
        $order->setOxtotalordersum(99.99);
        $order->setOxordernr(rand(100000, 999999));
        if ($providerOrderId) {
            $order->setPaymentProviderOrderId($providerOrderId);
        }
        return $order;
    }

    private function createAndSaveTransaction(
        string $orderId,
        string $providerOrderId,
        string $transactionId,
        string $type
    ): void {
        $transaction = new PaymentTransaction();
        $transaction->setShopOrderId($orderId);
        $transaction->setProviderOrderId($providerOrderId);
        $transaction->setTransactionId($transactionId);
        $transaction->setTransactionType($type);

---

## Related Documentation

- **[Part 5: Authorization Flow](09-05-tdd-authorization-flow.md)** - Two-step authorization testing
- **[Part 7: Test Pyramid Strategy](09-07-tdd-test-pyramid.md)** - Test organization (continues from here)
- **[Test Organization](09-test-organization.md)** - Component vs provider test separation

---

**Version:** 2.1.0
**Last Updated:** 2025-10-16
