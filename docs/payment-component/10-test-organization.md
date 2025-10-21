# Test Organization - Component vs Provider-Specific Tests

**Document Version:** 1.0
**Last Updated:** 2025-10-16
**Status:** Architecture Definition

---

## Table of Contents

1. [Overview](#overview)
2. [Testing Philosophy](#testing-philosophy)
3. [Component Test Structure](#component-test-structure)
4. [Provider Test Structure](#provider-test-structure)
5. [Test Boundaries](#test-boundaries)
6. [Directory Structure](#directory-structure)
7. [Test Execution Strategy](#test-execution-strategy)
8. [Coverage Requirements](#coverage-requirements)
9. [CI/CD Integration](#cicd-integration)

---

## 1. Overview

### Purpose

This document defines the **clear separation between component tests and provider-specific tests**, ensuring:

1. **Component tests** validate reusable, provider-agnostic code (100% reusable)
2. **Provider tests** validate provider-specific adapters and integrations (30% code, 100% pattern)
3. **Independent execution** - Component tests run without provider dependencies
4. **Clear ownership** - Each test suite has a specific responsibility

### Key Principles

| Principle | Description |
|-----------|-------------|
| **Separation of Concerns** | Component tests never depend on provider SDKs |
| **Isolation** | Component tests run independently of provider tests |
| **Reusability** | Component test patterns reusable across all providers |
| **Fast Feedback** | Component tests run quickly without external dependencies |
| **Integration Coverage** | Provider tests validate real SDK integration |

---

## 2. Testing Philosophy

### Component Tests (100% Reusable)

**Purpose**: Validate reusable business logic and infrastructure

**Characteristics**:
- ✅ Test provider-agnostic code
- ✅ Mock adapter interfaces (not provider SDKs)
- ✅ Fast execution (no external dependencies)
- ✅ High coverage requirement (95%+)
- ✅ Run in every CI build
- ✅ No provider SDK dependencies

**What Component Tests Cover**:
- Event system (EventDispatcher, EventContext, Domain Events)
- Domain models (PaymentTransaction, PaymentOrderState, PaymentCustomer)
- Repositories (PaymentTransactionRepository, OrderRepository)
- Business services (PaymentService, OrderManager)
- State machine logic
- Abstract base classes (AbstractCustomerMapper, AbstractBasketMapper)
- Utility classes (AmountConverter, CurrencyNormalizer)
- Exception handling
- Request/Response DTOs
- Adapter interface contracts

**What Component Tests DON'T Cover**:
- ❌ Provider SDK integration
- ❌ Provider-specific adapters
- ❌ Provider API calls
- ❌ Provider webhook parsing

### Provider Tests (Provider-Specific)

**Purpose**: Validate provider SDK integration and adapter implementation

**Characteristics**:
- ✅ Test provider-specific code
- ✅ Mock provider SDKs (for unit tests)
- ✅ Use real SDKs (for integration tests)
- ✅ Moderate coverage requirement (90%+)
- ✅ Run in CI with provider API keys
- ✅ Require provider SDK dependencies

**What Provider Tests Cover**:
- Provider adapters (StripeAdapter, UnzerAdapter)
- SDK client factories
- Status mappers (provider states → component states)
- Customer mappers (OXID User → provider customer)
- Basket mappers (OXID Basket → provider basket)
- Webhook verifiers
- Webhook parsers
- Provider-specific error handling
- Amount/currency conversions
- Real API integration (sandbox)

**What Provider Tests DON'T Cover**:
- ❌ Business logic (covered by component tests)
- ❌ Domain models (covered by component tests)
- ❌ Generic repositories (covered by component tests)

---

## 3. Component Test Structure

### Directory Structure

```
payment-component/
├── src/
│   └── Component/              # 100% Reusable code
│       ├── Adapter/
│       │   ├── PaymentAdapterInterface.php
│       │   ├── Request/
│       │   ├── Response/
│       │   ├── Exception/
│       │   └── Util/
│       ├── Contract/
│       ├── Event/
│       ├── Model/
│       ├── Repository/
│       └── Service/
│
└── tests/
    └── Component/              # Component tests (provider-agnostic)
        ├── Unit/
        │   ├── Adapter/
        │   │   ├── Request/
        │   │   │   ├── CreatePaymentRequestTest.php
        │   │   │   ├── CapturePaymentRequestTest.php
        │   │   │   └── RefundPaymentRequestTest.php
        │   │   ├── Response/
        │   │   │   ├── PaymentResponseTest.php
        │   │   │   ├── CaptureResponseTest.php
        │   │   │   └── RefundResponseTest.php
        │   │   ├── Exception/
        │   │   │   └── PaymentAdapterExceptionTest.php
        │   │   └── Util/
        │   │       ├── AmountConverterTest.php
        │   │       └── CurrencyNormalizerTest.php
        │   ├── Event/
        │   │   ├── EventContextTest.php
        │   │   ├── EventDispatcherTest.php
        │   │   └── Domain/
        │   │       ├── PaymentInitiatedEventTest.php
        │   │       ├── PaymentAuthorizedEventTest.php
        │   │       └── PaymentCapturedEventTest.php
        │   ├── Model/
        │   │   ├── PaymentTransactionTest.php
        │   │   ├── PaymentOrderStateTest.php
        │   │   ├── PaymentCustomerTest.php
        │   │   └── PaymentBasketSnapshotTest.php
        │   └── Service/
        │       ├── PaymentServiceTest.php          # Mocks adapter interface
        │       ├── OrderManagerTest.php
        │       └── BasketSummaryServiceTest.php
        │
        ├── Integration/
        │   ├── Repository/
        │   │   ├── PaymentTransactionRepositoryTest.php
        │   │   └── OrderRepositoryTest.php
        │   └── Service/
        │       └── PaymentServiceIntegrationTest.php
        │
        └── Support/
            ├── TestCase.php
            ├── IntegrationTestCase.php
            ├── Builders/
            │   ├── OrderBuilder.php
            │   ├── PaymentTransactionBuilder.php
            │   └── PaymentRequestBuilder.php
            └── Mocks/
                └── MockPaymentAdapter.php          # Mock adapter for tests
```

### Example: Component Unit Test (Mocking Adapter)

```php
<?php
// tests/Component/Unit/Service/PaymentServiceTest.php

namespace Tests\Component\Unit\Service;

use Mockery;
use PHPUnit\Framework\TestCase;
use PaymentComponent\Service\PaymentService;
use PaymentComponent\Contract\PaymentAdapterInterface;
use PaymentComponent\Adapter\Request\CreatePaymentRequest;
use PaymentComponent\Adapter\Response\PaymentResponse;

/**
 * Component Test: Tests business logic without provider dependencies
 */
class PaymentServiceTest extends TestCase
{
    /** @test */
    public function it_creates_payment_using_adapter_interface(): void
    {
        // Arrange - Mock the INTERFACE, not a provider SDK
        $adapterMock = Mockery::mock(PaymentAdapterInterface::class);
        $transactionRepoMock = Mockery::mock(PaymentTransactionRepositoryInterface::class);
        $eventDispatcherMock = Mockery::mock(EventDispatcherInterface::class);

        $adapterMock
            ->shouldReceive('createPayment')
            ->once()
            ->with(Mockery::type(CreatePaymentRequest::class))
            ->andReturn(new PaymentResponse(
                providerPaymentId: 'test-payment-123',
                status: 'authorized',
                amount: 99.99,
                currency: 'EUR'
            ));

        $transactionRepoMock->shouldReceive('save')->once();
        $eventDispatcherMock->shouldReceive('dispatch')->once();

        $service = new PaymentService($adapterMock, $transactionRepoMock, $eventDispatcherMock);

        // Act
        $response = $service->initiatePayment(
            orderId: 'order-123',
            shopId: '1',
            amount: 99.99,
            currency: 'EUR',
            paymentMethod: 'card'
        );

        // Assert
        $this->assertEquals('test-payment-123', $response->getProviderPaymentId());
        $this->assertEquals('authorized', $response->getStatus());
    }

    /** @test */
    public function it_handles_adapter_exceptions_gracefully(): void
    {
        $adapterMock = Mockery::mock(PaymentAdapterInterface::class);
        $transactionRepoMock = Mockery::mock(PaymentTransactionRepositoryInterface::class);
        $eventDispatcherMock = Mockery::mock(EventDispatcherInterface::class);

        $adapterMock
            ->shouldReceive('createPayment')
            ->andThrow(new PaymentAdapterException(
                'Card declined',
                'card_declined',
                'unknown_provider'
            ));

        $service = new PaymentService($adapterMock, $transactionRepoMock, $eventDispatcherMock);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Payment initiation failed: Card declined');

        $service->initiatePayment('order-123', '1', 99.99, 'EUR', 'card');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

### Example: Component Integration Test (With DB)

```php
<?php
// tests/Component/Integration/Repository/PaymentTransactionRepositoryTest.php

namespace Tests\Component\Integration\Repository;

use Tests\Component\Support\IntegrationTestCase;
use PaymentComponent\Repository\PaymentTransactionRepository;
use PaymentComponent\Model\PaymentTransaction;

/**
 * Component Integration Test: Tests repository with real database
 */
class PaymentTransactionRepositoryTest extends IntegrationTestCase
{
    private PaymentTransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PaymentTransactionRepository($this->getDatabase());
        $this->runMigrations(); // Create tables
    }

    /** @test */
    public function it_persists_payment_transaction(): void
    {
        // Arrange
        $transaction = new PaymentTransaction(
            shopId: '1',
            orderId: 'order-123',
            providerOrderId: 'provider-payment-123',
            status: 'authorized',
            paymentMethodId: 'card',
            transactionType: 'authorization'
        );

        // Act
        $this->repository->save($transaction);

        // Assert
        $this->assertNotNull($transaction->getId());

        $retrieved = $this->repository->findById($transaction->getId());
        $this->assertNotNull($retrieved);
        $this->assertEquals('order-123', $retrieved->getOrderId());
        $this->assertEquals('provider-payment-123', $retrieved->getProviderOrderId());
    }

    /** @test */
    public function it_finds_transactions_by_order_id(): void
    {
        // Arrange
        $transaction1 = new PaymentTransaction('1', 'order-123', 'pay-1', 'authorized', 'card', 'authorization');
        $transaction2 = new PaymentTransaction('1', 'order-123', 'pay-2', 'captured', 'card', 'capture');
        $transaction3 = new PaymentTransaction('1', 'order-456', 'pay-3', 'authorized', 'card', 'authorization');

        $this->repository->save($transaction1);
        $this->repository->save($transaction2);
        $this->repository->save($transaction3);

        // Act
        $transactions = $this->repository->findAllByOrderId('order-123');

        // Assert
        $this->assertCount(2, $transactions);
        $this->assertEquals('pay-1', $transactions[0]->getProviderOrderId());
        $this->assertEquals('pay-2', $transactions[1]->getProviderOrderId());
    }
}
```

---

## 4. Provider Test Structure

### Directory Structure (Stripe Example)

```
stripe-module/
├── src/
│   └── Stripe/                 # Provider-specific code
│       ├── Adapter/
│       │   ├── StripeAdapter.php
│       │   ├── StripeStatusMapper.php
│       │   ├── StripeCustomerMapper.php
│       │   └── StripeBasketMapper.php
│       ├── Service/
│       │   ├── StripeApiService.php
│       │   └── StripeConfigService.php
│       └── Webhook/
│           └── StripeWebhookHandler.php
│
└── tests/
    └── Stripe/                 # Provider-specific tests
        ├── Unit/
        │   ├── Adapter/
        │   │   ├── StripeAdapterTest.php           # Mocks Stripe SDK
        │   │   ├── StripeStatusMapperTest.php
        │   │   ├── StripeCustomerMapperTest.php
        │   │   └── StripeBasketMapperTest.php
        │   └── Service/
        │       ├── StripeApiServiceTest.php
        │       └── StripeConfigServiceTest.php
        │
        ├── Integration/
        │   ├── Adapter/
        │   │   └── StripeAdapterIntegrationTest.php # Real Stripe API (sandbox)
        │   └── Webhook/
        │       └── StripeWebhookIntegrationTest.php
        │
        └── Support/
            ├── StripeTestCase.php
            ├── Builders/
            │   └── StripePaymentIntentBuilder.php
            └── Fixtures/
                └── StripeWebhookFixtures.php
```

### Example: Provider Unit Test (Mocking Stripe SDK)

```php
<?php
// tests/Stripe/Unit/Adapter/StripeAdapterTest.php

namespace Tests\Stripe\Unit\Adapter;

use Mockery;
use PHPUnit\Framework\TestCase;
use Stripe\Adapter\StripeAdapter;
use Stripe\StripeClient;
use PaymentComponent\Adapter\Request\CreatePaymentRequest;

/**
 * Provider Unit Test: Tests Stripe adapter with mocked Stripe SDK
 */
class StripeAdapterTest extends TestCase
{
    /** @test */
    public function it_converts_amount_to_cents_when_calling_stripe(): void
    {
        // Arrange - Mock Stripe SDK
        $stripeMock = Mockery::mock(StripeClient::class);
        $stripeMock->paymentIntents = Mockery::mock();

        $stripeMock->paymentIntents
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                // Verify amount is converted to cents
                return $params['amount'] === 9999 // 99.99 EUR → 9999 cents
                    && $params['currency'] === 'eur' // Uppercase → lowercase
                    && $params['capture_method'] === 'manual';
            }))
            ->andReturn((object)[
                'id' => 'pi_123',
                'status' => 'requires_capture',
                'amount' => 9999,
                'currency' => 'eur',
                'client_secret' => 'pi_123_secret',
            ]);

        $adapter = new StripeAdapter('sk_test_123');
        $adapter->setClient($stripeMock); // Inject mock

        // Act
        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $response = $adapter->createPayment($request);

        // Assert
        $this->assertEquals('pi_123', $response->getProviderPaymentId());
        $this->assertEquals('authorized', $response->getStatus());
        $this->assertEquals(99.99, $response->getAmount()); // Converted back to float
        $this->assertEquals('EUR', $response->getCurrency()); // Converted back to uppercase
    }

    /** @test */
    public function it_maps_stripe_status_to_component_status(): void
    {
        $adapter = new StripeAdapter('sk_test_123');

        // Test various Stripe statuses
        $this->assertEquals('authorized', $adapter->mapStripeStatus('requires_capture'));
        $this->assertEquals('captured', $adapter->mapStripeStatus('succeeded'));
        $this->assertEquals('pending', $adapter->mapStripeStatus('requires_payment_method'));
        $this->assertEquals('canceled', $adapter->mapStripeStatus('canceled'));
    }

    /** @test */
    public function it_throws_adapter_exception_on_stripe_error(): void
    {
        $stripeMock = Mockery::mock(StripeClient::class);
        $stripeMock->paymentIntents = Mockery::mock();

        $stripeMock->paymentIntents
            ->shouldReceive('create')
            ->andThrow(new \Stripe\Exception\CardException(
                'Your card was declined',
                'card_declined',
                402
            ));

        $adapter = new StripeAdapter('sk_test_123');
        $adapter->setClient($stripeMock);

        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: '1',
            paymentMethod: 'card'
        );

        $this->expectException(PaymentAdapterException::class);
        $this->expectExceptionMessage('[stripe] Your card was declined');

        $adapter->createPayment($request);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

### Example: Provider Integration Test (Real Stripe API)

```php
<?php
// tests/Stripe/Integration/Adapter/StripeAdapterIntegrationTest.php

namespace Tests\Stripe\Integration\Adapter;

use PHPUnit\Framework\TestCase;
use Stripe\Adapter\StripeAdapter;
use PaymentComponent\Adapter\Request\CreatePaymentRequest;
use PaymentComponent\Adapter\Request\CapturePaymentRequest;

/**
 * Provider Integration Test: Tests with real Stripe API (sandbox)
 *
 * Requires STRIPE_TEST_KEY environment variable
 */
class StripeAdapterIntegrationTest extends TestCase
{
    private StripeAdapter $adapter;

    protected function setUp(): void
    {
        $apiKey = $_ENV['STRIPE_TEST_KEY'] ?? $this->markTestSkipped(
            'Stripe test key not configured. Set STRIPE_TEST_KEY environment variable.'
        );

        $this->adapter = new StripeAdapter($apiKey, sandbox: true);
    }

    /** @test */
    public function it_creates_and_captures_payment_with_real_stripe_api(): void
    {
        // Create payment (authorization)
        $createRequest = new CreatePaymentRequest(
            amount: 10.00,
            currency: 'EUR',
            orderId: 'test-order-' . time(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false // Authorization only
        );

        $paymentResponse = $this->adapter->createPayment($createRequest);

        // Assert payment created
        $this->assertStringStartsWith('pi_', $paymentResponse->getProviderPaymentId());
        $this->assertEquals('authorized', $paymentResponse->getStatus());
        $this->assertEquals(10.00, $paymentResponse->getAmount());
        $this->assertEquals('EUR', $paymentResponse->getCurrency());

        // Capture payment
        $captureRequest = new CapturePaymentRequest(
            providerPaymentId: $paymentResponse->getProviderPaymentId(),
            amount: 10.00
        );

        $captureResponse = $this->adapter->capturePayment($captureRequest);

        // Assert payment captured
        $this->assertNotEmpty($captureResponse->getCaptureId());
        $this->assertEquals('captured', $captureResponse->getStatus());
        $this->assertEquals(10.00, $captureResponse->getAmount());
    }

    /** @test */
    public function it_creates_direct_capture_payment_with_real_stripe_api(): void
    {
        $createRequest = new CreatePaymentRequest(
            amount: 5.00,
            currency: 'EUR',
            orderId: 'test-order-' . time(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: true // Direct capture
        );

        $response = $this->adapter->createPayment($createRequest);

        $this->assertStringStartsWith('pi_', $response->getProviderPaymentId());
        // Note: Status might be 'processing' or 'captured' depending on payment method
        $this->assertContains($response->getStatus(), ['processing', 'captured', 'requires_action']);
    }

    /** @test */
    public function it_retrieves_payment_details_with_real_stripe_api(): void
    {
        // Create a payment first
        $createRequest = new CreatePaymentRequest(
            amount: 15.00,
            currency: 'EUR',
            orderId: 'test-order-' . time(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $payment = $this->adapter->createPayment($createRequest);

        // Retrieve payment details
        $details = $this->adapter->getPaymentDetails($payment->getProviderPaymentId());

        $this->assertEquals($payment->getProviderPaymentId(), $details->getProviderPaymentId());
        $this->assertEquals(15.00, $details->getAmount());
        $this->assertEquals('EUR', $details->getCurrency());
        $this->assertInstanceOf(\DateTimeImmutable::class, $details->getCreatedAt());
    }
}
```

---

## 5. Test Boundaries

### Component Test Boundaries

| Can Test | Cannot Test |
|----------|-------------|
| ✅ PaymentService business logic | ❌ Stripe SDK calls |
| ✅ EventDispatcher behavior | ❌ Unzer SDK initialization |
| ✅ PaymentTransaction model | ❌ PayPal API requests |
| ✅ Repository CRUD operations | ❌ Provider webhooks (real) |
| ✅ State machine transitions | ❌ Provider-specific error codes |
| ✅ Abstract mappers | ❌ SDK client factories |
| ✅ Utility classes | ❌ Provider status mapping |
| ✅ DTO validation | ❌ Amount conversion (provider-specific) |
| ✅ Exception hierarchy | ❌ Currency normalization (provider-specific) |
| ✅ Adapter interface contract | ❌ Adapter implementations |

### Provider Test Boundaries

| Can Test | Cannot Test |
|----------|-------------|
| ✅ StripeAdapter implementation | ❌ PaymentService business logic |
| ✅ Stripe SDK calls | ❌ Event system |
| ✅ Status mapping (Stripe → Component) | ❌ Domain models |
| ✅ Customer mapper (OXID → Stripe) | ❌ Repositories |
| ✅ Amount conversion (EUR → cents) | ❌ State machine |
| ✅ Webhook signature verification | ❌ Generic exceptions |
| ✅ Error translation (Stripe → Component) | ❌ Generic DTOs |
| ✅ Real API integration (sandbox) | ❌ Abstract base classes |

---

## 6. Directory Structure

### Complete Test Organization

```
project-root/
│
├── payment-component/                    # Component package (reusable)
│   ├── src/Component/
│   └── tests/Component/
│       ├── Unit/                         # Component unit tests
│       │   ├── Adapter/                  # Test adapter contracts, DTOs, utils
│       │   ├── Event/                    # Test event system
│       │   ├── Model/                    # Test domain models
│       │   ├── Repository/               # Test repository interfaces
│       │   └── Service/                  # Test business logic (mock adapter)
│       ├── Integration/                  # Component integration tests
│       │   ├── Repository/               # Test with real DB
│       │   └── Service/                  # Test service + repository
│       └── Support/
│           ├── Builders/                 # Test data builders
│           └── Mocks/
│               └── MockPaymentAdapter.php
│
├── stripe-module/                        # Stripe provider extension
│   ├── src/Stripe/
│   └── tests/Stripe/
│       ├── Unit/                         # Stripe unit tests
│       │   ├── Adapter/                  # Test StripeAdapter (mock SDK)
│       │   ├── Service/                  # Test Stripe services
│       │   └── Webhook/                  # Test webhook handling
│       ├── Integration/                  # Stripe integration tests
│       │   ├── Adapter/                  # Test with real Stripe API
│       │   └── Webhook/                  # Test with real webhooks
│       └── Support/
│           ├── Builders/
│           └── Fixtures/
│
├── unzer-module/                         # Unzer provider extension
│   ├── src/Unzer/
│   └── tests/Unzer/
│       ├── Unit/                         # Unzer unit tests
│       │   ├── Adapter/                  # Test UnzerAdapter (mock SDK)
│       │   └── Service/
│       ├── Integration/                  # Unzer integration tests
│       │   └── Adapter/                  # Test with real Unzer API
│       └── Support/
│
└── paypal-module/                        # PayPal provider extension
    ├── src/PayPal/
    └── tests/PayPal/
        ├── Unit/
        ├── Integration/
        └── Support/
```

---

## 7. Test Execution Strategy

### Running Component Tests Only

```bash
# Fast feedback - no provider dependencies
composer test:component

# Equivalent to:
vendor/bin/phpunit --testsuite Component
```

**Characteristics**:
- ⚡ Fast (< 1 minute)
- 🔄 Run on every commit
- 🚫 No external dependencies
- ✅ Must pass before push

### Running Provider Tests (Per Provider)

```bash
# Stripe tests only
composer test:stripe

# Unzer tests only
composer test:unzer

# PayPal tests only
composer test:paypal

# All provider tests
composer test:providers
```

**Characteristics**:
- 🐌 Slower (2-5 minutes per provider)
- 🔑 Requires API keys
- 🌐 May call external APIs
- ✅ Run before release

### Running All Tests

```bash
# Everything
composer test:all

# Equivalent to:
composer test:component && composer test:providers
```

### PHPUnit Configuration

```xml
<!-- phpunit.xml -->
<phpunit>
    <testsuites>
        <!-- Component tests - NO provider dependencies -->
        <testsuite name="Component">
            <directory>tests/Component/Unit</directory>
            <directory>tests/Component/Integration</directory>
        </testsuite>

        <!-- Provider-specific tests -->
        <testsuite name="Stripe">
            <directory>tests/Stripe</directory>
        </testsuite>

        <testsuite name="Unzer">
            <directory>tests/Unzer</directory>
        </testsuite>

        <testsuite name="PayPal">
            <directory>tests/PayPal</directory>
        </testsuite>

        <!-- All provider tests -->
        <testsuite name="Providers">
            <directory>tests/Stripe</directory>
            <directory>tests/Unzer</directory>
            <directory>tests/PayPal</directory>
        </testsuite>
    </testsuites>

    <php>
        <!-- Component tests - no API keys needed -->
        <env name="DB_CONNECTION" value="sqlite::memory:"/>

        <!-- Provider tests - require API keys -->
        <env name="STRIPE_TEST_KEY" value="sk_test_..."/>
        <env name="UNZER_TEST_KEY" value="s-priv-..."/>
        <env name="PAYPAL_CLIENT_ID" value="..."/>
        <env name="PAYPAL_CLIENT_SECRET" value="..."/>
    </php>

    <coverage>
        <include>
            <directory>src/Component</directory>
            <directory>src/Stripe</directory>
            <directory>src/Unzer</directory>
            <directory>src/PayPal</directory>
        </include>

        <report>
            <html outputDirectory="coverage/html"/>
            <clover outputFile="coverage/clover.xml"/>
        </report>
    </coverage>
</phpunit>
```

---

## 8. Coverage Requirements

### Component Coverage (95%+)

| Component | Target Coverage | Rationale |
|-----------|----------------|-----------|
| Adapter Interfaces | 100% | Contracts must be fully covered |
| DTOs (Request/Response) | 100% | Simple objects, easy to test |
| Domain Models | 95% | Core business logic |
| Repositories | 90% | DB integration |
| Services | 95% | Critical business logic |
| Event System | 100% | Core infrastructure |
| Utilities | 100% | Pure functions, easy to test |
| Exceptions | 100% | Error handling is critical |

### Provider Coverage (90%+)

| Provider Code | Target Coverage | Rationale |
|---------------|----------------|-----------|
| Adapter Implementation | 90% | Critical integration point |
| Status Mappers | 100% | Must map all statuses |
| Customer Mappers | 85% | Complex mapping logic |
| Basket Mappers | 85% | Complex mapping logic |
| Webhook Handlers | 90% | Critical for async processing |
| Error Translation | 100% | Must handle all errors |

---

## 9. CI/CD Integration

### GitHub Actions Workflow

```yaml
# .github/workflows/tests.yml

name: Tests

on: [push, pull_request]

jobs:
  component-tests:
    name: Component Tests (Provider-Agnostic)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, xml, sqlite3

      - name: Install Dependencies
        run: composer install --prefer-dist

      - name: Run Component Tests
        run: composer test:component

      - name: Upload Component Coverage
        uses: codecov/codecov-action@v3
        with:
          files: ./coverage/component-clover.xml
          flags: component

  stripe-tests:
    name: Stripe Provider Tests
    runs-on: ubuntu-latest
    needs: component-tests  # Only run if component tests pass
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install Dependencies
        run: composer install --prefer-dist

      - name: Run Stripe Tests
        env:
          STRIPE_TEST_KEY: ${{ secrets.STRIPE_TEST_KEY }}
        run: composer test:stripe

      - name: Upload Stripe Coverage
        uses: codecov/codecov-action@v3
        with:
          files: ./coverage/stripe-clover.xml
          flags: stripe

  unzer-tests:
    name: Unzer Provider Tests
    runs-on: ubuntu-latest
    needs: component-tests
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install Dependencies
        run: composer install --prefer-dist

      - name: Run Unzer Tests
        env:
          UNZER_TEST_KEY: ${{ secrets.UNZER_TEST_KEY }}
        run: composer test:unzer

      - name: Upload Unzer Coverage
        uses: codecov/codecov-action@v3
        with:
          files: ./coverage/unzer-clover.xml
          flags: unzer
```

### Composer Scripts

```json
{
  "scripts": {
    "test:component": [
      "phpunit --testsuite Component --coverage-clover coverage/component-clover.xml"
    ],
    "test:stripe": [
      "phpunit --testsuite Stripe --coverage-clover coverage/stripe-clover.xml"
    ],
    "test:unzer": [
      "phpunit --testsuite Unzer --coverage-clover coverage/unzer-clover.xml"
    ],
    "test:paypal": [
      "phpunit --testsuite PayPal --coverage-clover coverage/paypal-clover.xml"
    ],
    "test:providers": [
      "@test:stripe",
      "@test:unzer",
      "@test:paypal"
    ],
    "test:all": [
      "@test:component",
      "@test:providers"
    ],
    "test": "@test:component"
  }
}
```

---

## Summary

### Key Takeaways

✅ **Clear Separation**: Component tests never depend on provider SDKs
✅ **Fast Feedback**: Component tests run quickly without external dependencies
✅ **Isolated Execution**: Tests can run independently
✅ **Reusable Patterns**: Component test patterns reused across providers
✅ **Comprehensive Coverage**: Both unit and integration tests for each layer

### Test Organization Benefits

| Benefit | Component Tests | Provider Tests |
|---------|----------------|----------------|
| **Speed** | ⚡ Fast (< 1 min) | 🐌 Slower (2-5 min) |
| **Dependencies** | 🚫 None | 🔑 API keys required |
| **Frequency** | 🔄 Every commit | 📅 Pre-release |
| **Coverage** | 95%+ | 90%+ |
| **Purpose** | Business logic | SDK integration |
| **Reusability** | 100% | 30% code, 100% pattern |

---

**Related Documentation:**
- [TDD Strategy](./08-tdd-strategy.md)
- [SDK Integration Patterns](./05-sdk-integration-patterns.md)
- [Architecture Layers](./01-architecture-layers.md)
- [Implementation Tickets](./IMPLEMENTATION-TICKETS-SPRINT-1.md)
