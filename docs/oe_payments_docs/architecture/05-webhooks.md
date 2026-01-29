# SDK Integration Patterns - Unified Approach for Payment Provider Extensions

**Document Version:** 1.1
**Last Updated:** 2025-10-16
**Status:** Architecture Definition
**Test Organization:** [09-test-organization.md](09-test-organization.md)

---

## Table of Contents

1. [Overview](#overview)
2. [Test Organization for SDK Adapters](#test-organization-for-sdk-adapters)
3. [Analysis of Existing Provider SDKs](#analysis-of-existing-provider-sdks)
4. [Common Integration Requirements](#common-integration-requirements)
5. [Unified SDK-Adapter Architecture](#unified-sdk-adapter-architecture)
6. [Component Responsibilities](#component-responsibilities)
7. [Provider Extension Responsibilities](#provider-extension-responsibilities)
8. [Implementation Examples](#implementation-examples)
9. [Migration Path](#migration-path)

---

## 1. Overview

### Purpose

This document defines a **unified approach for integrating payment provider SDKs** with the OXID payment component. It ensures that:

1. **The payment component provides all necessary abstract and reusable code** for SDK integration
2. **Provider-specific extensions focus only on SDK translation logic** (30% provider-specific code)
3. **Maximum code reuse** across all payment providers (Stripe, Unzer, PayPal, Adyen, TeleCash, etc.)

### Scope

- **In Scope**: SDK client initialization, request/response normalization, error handling, webhook processing, transaction state mapping
- **Out of Scope**: Provider-specific business logic, UI components, admin configuration (covered in separate documents)

---

## 2. Test Organization for SDK Adapters

### 2.1 Test Separation Strategy

The SDK-Adapter architecture requires **clear separation between component tests and provider tests**. For complete details, see [09-test-organization.md](09-test-organization.md).

**Key Testing Principles:**

| Test Type | Location | What to Mock | Coverage | Execution Speed |
|-----------|----------|--------------|----------|-----------------|
| **Component Tests** | `tests/Component/` | Mock `PaymentAdapterInterface` | 95%+ | Fast (<1 min) |
| **Provider Tests** | `tests/Stripe/`, `tests/Unzer/` | Mock or use real provider SDKs | 90%+ | Slower (2-5 min) |

### 2.2 Component Tests (Provider-Agnostic)

**What to Test:**
- ✅ Business logic in `PaymentService` using mocked adapter interface
- ✅ Adapter interface contracts (request/response DTOs)
- ✅ Adapter factory (provider selection)
- ✅ Abstract base classes (customer/basket mappers)
- ✅ Utility classes (amount/currency conversion)

**What NOT to Test:**
- ❌ Provider SDK integration
- ❌ Actual API calls to Stripe/Unzer/PayPal
- ❌ Provider-specific error codes

**Example Component Test:**
```php
// tests/Component/Unit/Service/PaymentServiceTest.php

class PaymentServiceTest extends TestCase
{
    /** @test */
    public function it_initiates_payment_using_adapter_interface(): void
    {
        // Arrange - Mock the adapter INTERFACE (not a provider SDK)
        $adapterMock = Mockery::mock(PaymentAdapterInterface::class);

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

        $transactionRepo = Mockery::mock(PaymentTransactionRepositoryInterface::class);
        $transactionRepo->shouldReceive('save')->once();

        $service = new PaymentService($adapterMock, $transactionRepo, $eventDispatcher);

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
}
```

### 2.3 Provider Tests (Provider-Specific)

**What to Test:**
- ✅ Adapter implementation (request/response translation)
- ✅ Amount conversion (float → cents for Stripe, float → float for Unzer)
- ✅ Currency normalization (EUR → eur for Stripe)
- ✅ Customer/basket mapping (OXID → Provider format)
- ✅ Status mapping (Provider states → Component states)
- ✅ Error handling (Provider exceptions → Component exceptions)
- ✅ Webhook parsing and verification

**What NOT to Test:**
- ❌ Component business logic (tested in component tests)
- ❌ Transaction persistence (tested in component tests)
- ❌ Order state management (tested in component tests)

**Example Provider Unit Test (Mock SDK):**
```php
// tests/Stripe/Unit/StripeAdapterTest.php

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
                // Verify amount conversion: 99.99 EUR → 9999 cents
                return $params['amount'] === 9999
                    && $params['currency'] === 'eur'; // Verify lowercase
            }))
            ->andReturn((object)[
                'id' => 'pi_123',
                'status' => 'requires_capture',
                'amount' => 9999,
                'currency' => 'eur',
            ]);

        $adapter = new StripeAdapter('sk_test_123');
        $adapter->setClient($stripeMock); // Inject mocked client

        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order-123',
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        // Act
        $response = $adapter->createPayment($request);

        // Assert
        $this->assertEquals('authorized', $response->getStatus());
        $this->assertEquals(99.99, $response->getAmount()); // Converted back
        $this->assertEquals('EUR', $response->getCurrency()); // Uppercase
    }
}
```

**Example Provider Integration Test (Real SDK):**
```php
// tests/Stripe/Integration/StripeAdapterIntegrationTest.php

class StripeAdapterIntegrationTest extends TestCase
{
    /** @test */
    public function it_creates_payment_with_real_stripe_api(): void
    {
        // Arrange - Use real Stripe API in sandbox mode
        $adapter = new StripeAdapter(
            apiKey: getenv('STRIPE_TEST_SECRET_KEY'),
            sandbox: true
        );

        $request = new CreatePaymentRequest(
            amount: 10.00, // Minimum test amount
            currency: 'EUR',
            orderId: 'test-order-' . uniqid(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        // Act
        $response = $adapter->createPayment($request);

        // Assert
        $this->assertNotEmpty($response->getProviderPaymentId());
        $this->assertEquals('authorized', $response->getStatus());
        $this->assertEquals(10.00, $response->getAmount());
        $this->assertEquals('EUR', $response->getCurrency());

        // Cleanup - cancel payment intent
        $this->cleanupStripePayment($response->getProviderPaymentId());
    }
}
```

### 2.4 Test Execution

**Run component tests only (fast):**
```bash
vendor/bin/phpunit --testsuite=Component
# Expected time: < 1 minute
# Coverage requirement: 95%+
```

**Run Stripe adapter tests only:**
```bash
vendor/bin/phpunit --testsuite=Stripe
# Expected time: 2-5 minutes (includes API calls)
# Coverage requirement: 90%+
```

**Run all tests:**
```bash
vendor/bin/phpunit
# Expected time: 5-10 minutes
```

### 2.5 CI/CD Integration

**Separate CI jobs for component and provider tests:**

```yaml
# .github/workflows/tests.yml

jobs:
  component-tests:
    name: Component Tests (Provider-Agnostic)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Run component tests
        run: vendor/bin/phpunit --testsuite=Component --coverage-clover=coverage-component.xml
      - name: Check coverage threshold (95%)
        run: php coverage-check.php coverage-component.xml 95

  stripe-adapter-tests:
    name: Stripe Adapter Tests
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Run Stripe adapter tests
        env:
          STRIPE_SECRET_KEY: ${{ secrets.STRIPE_TEST_SECRET_KEY }}
        run: vendor/bin/phpunit --testsuite=Stripe --coverage-clover=coverage-stripe.xml
      - name: Check coverage threshold (90%)
        run: php coverage-check.php coverage-stripe.xml 90

  unzer-adapter-tests:
    name: Unzer Adapter Tests
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Run Unzer adapter tests
        env:
          UNZER_PRIVATE_KEY: ${{ secrets.UNZER_TEST_PRIVATE_KEY }}
        run: vendor/bin/phpunit --testsuite=Unzer --coverage-clover=coverage-unzer.xml
      - name: Check coverage threshold (90%)
        run: php coverage-check.php coverage-unzer.xml 90
```

---

## 3. Analysis of Existing Provider SDKs

### 2.1 Current Implementations

We analyzed three existing OXID 7 payment provider modules:

| Provider | SDK Type | Key Characteristics |
|----------|----------|---------------------|
| **Stripe** | `stripe/stripe-php` v13 | Official SDK, simple API key init, amounts in cents (int) |
| **Unzer** | `unzerdev/php-sdk` v3.6 | Official SDK, context-aware init (currency, customer type), amounts in EUR (float) |
| **TeleCash** | Custom SOAP client | No SDK, custom XML/SOAP implementation, amounts in floats |

### 2.2 Common SDK Integration Patterns

After analyzing all three implementations, we identified **8 common integration patterns**:

#### Pattern 1: SDK Client Initialization

**Stripe:**
```php
$client = new \Stripe\StripeClient($apiKey);
```

**Unzer:**
```php
$sdk = new \UnzerSDK\Unzer($privateKey);
$sdk->setDebugMode(true)->setDebugHandler($debugHandler);
```

**TeleCash:**
```php
$client = new SoapClientCurl($curlOptions, $username, $password);
```

**Common Need**: Factory pattern for SDK client creation with credentials and mode (test/live).

#### Pattern 2: Amount Normalization

**Stripe**: Amounts in cents (integer)
```php
$amount = (int) round($floatAmount * 100); // 99.99 → 9999
```

**Unzer**: Amounts in euros (float)
```php
$amount = $floatAmount; // 99.99 → 99.99
```

**TeleCash**: Amounts in floats with XML formatting
```php
$amount = number_format($floatAmount, 2, '.', ''); // 99.99 → "99.99"
```

**Common Need**: Adapter method to convert between component format (float) and provider format.

#### Pattern 3: Customer Data Transformation

**Unzer Example:**
```php
$customer = CustomerFactory::createCustomer($firstName, $lastName);
$customer->setBirthDate($birthdate !== "0000-00-00" ? $birthdate : '');
$customer->setSalutation(strtolower($salutation ?? Salutations::UNKNOWN));
$customer->setEmail($email);
$customer->setBillingAddress($billingAddress);
$customer->setShippingAddress($shippingAddress);
```

**Common Need**: Transform OXID `User` object → Provider customer format.

#### Pattern 4: Basket/Order Data Transformation

**Unzer Example:**
```php
$basket = new Basket();
$basket->setOrderId($unzerOrderId)
    ->setAmountTotalGross($basketModel->getPrice()->getBruttoPrice())
    ->setCurrencyCode($basketModel->getBasketCurrency()->name);

foreach ($shopBasketContents as $basketItem) {
    $unzerBasketItem = new BasketItem();
    $unzerBasketItem->setTitle($basketItem->getTitle())
        ->setQuantity((int)$basketItem->getAmount())
        ->setAmountGross($basketItem->getUnitPrice()->getBruttoPrice());
    $basket->addBasketItem($unzerBasketItem);
}
```

**Common Need**: Transform OXID `Basket` → Provider order/basket format.

#### Pattern 5: Payment Request Creation

**Stripe Example:**
```php
$intent = $client->paymentIntents->create([
    'amount' => $amountInCents,
    'currency' => strtolower($currency), // EUR → eur
    'capture_method' => $directCapture ? 'automatic' : 'manual',
    'metadata' => ['order_id' => $orderId, 'shop_id' => $shopId],
]);
```

**Unzer Example:**
```php
$charge = $sdk->charge(
    $amount,
    $currency,
    $paymentType,
    $returnUrl,
    $customer,
    $orderId,
    $metadata,
    $basket
);
```

**Common Need**: Translate component `CreatePaymentRequest` → Provider API call.

#### Pattern 6: Response Normalization

**Stripe:**
```php
return new PaymentResponse(
    providerPaymentId: $intent->id,              // pi_xxx
    status: $this->mapStripeStatus($intent->status), // requires_capture → authorized
    amount: $this->convertCentsToAmount($intent->amount),
    currency: strtoupper($intent->currency),     // eur → EUR
);
```

**Unzer:**
```php
return new PaymentResponse(
    providerPaymentId: $charge->getPaymentId(),
    status: $this->mapUnzerStatus($charge->getState()),
    amount: $charge->getAmount(),
    currency: $charge->getCurrency(),
);
```

**Common Need**: Translate provider response → Component `PaymentResponse`.

#### Pattern 7: Webhook Processing

**Stripe:**
```php
$event = \Stripe\Webhook::constructEvent(
    $payload,
    $signature,
    $webhookSecret
);

switch ($event->type) {
    case 'payment_intent.succeeded':
        // Handle success
        break;
    case 'payment_intent.payment_failed':
        // Handle failure
        break;
}
```

**Unzer:**
```php
$event = $sdk->fetchResourceFromEvent($payload);
if ($event instanceof Payment) {
    $payment = $sdk->fetchPayment($event->getPaymentId());
    // Process payment state
}
```

**Common Need**: Verify webhook signature, parse event, dispatch to component event system.

#### Pattern 8: Error Handling

**Stripe:**
```php
try {
    $intent = $client->paymentIntents->create([...]);
} catch (\Stripe\Exception\CardException $e) {
    throw new CardDeclinedException($e->getMessage(), 'stripe', $e);
} catch (\Stripe\Exception\ApiErrorException $e) {
    throw PaymentAdapterException::fromProviderError('stripe', $e->getMessage(), $e->getCode(), $e);
}
```

**Unzer:**
```php
try {
    $charge = $sdk->charge(...);
} catch (UnzerApiException $e) {
    throw PaymentAdapterException::fromProviderError(
        'unzer',
        $this->translator->translateCode($e->getErrorId(), $e->getClientMessage()),
        $e->getErrorId(),
        $e
    );
}
```

**Common Need**: Catch provider exceptions → Throw unified component exceptions.

---

## 3. Common Integration Requirements

### 3.1 What Every Provider Needs

Based on the analysis, **every payment provider extension needs**:

| Requirement | Description | Reusability |
|-------------|-------------|-------------|
| **SDK Client Factory** | Initialize SDK with credentials (API key, mode) | 100% pattern |
| **Configuration Service** | Manage API keys, webhooks, mode (test/live) | 100% abstract class |
| **Amount Conversion** | Convert between component format (float) and provider format | 100% pattern, provider-specific implementation |
| **Currency Normalization** | Handle EUR vs eur, case sensitivity | 100% pattern |
| **Customer Mapper** | OXID User → Provider Customer | 100% abstract class with provider overrides |
| **Basket Mapper** | OXID Basket → Provider Order/Basket | 100% abstract class with provider overrides |
| **Request Builder** | Build provider-specific API requests | 100% interface, provider implementation |
| **Response Normalizer** | Provider response → Component DTO | 100% interface, provider implementation |
| **Status Mapper** | Provider payment states → Component states | 100% abstract with provider mapping |
| **Webhook Verifier** | Signature verification | 100% interface, provider implementation |
| **Webhook Parser** | Parse webhook payload → Component event | 100% interface, provider implementation |
| **Error Handler** | Provider exceptions → Component exceptions | 100% pattern |
| **Transaction Logger** | Log SDK requests/responses for debugging | 100% reusable |

### 3.2 What the Component Should Provide

The **payment component** should provide:

✅ **Abstract base classes**:
- `AbstractSDKClientFactory`
- `AbstractConfigurationService`
- `AbstractCustomerMapper`
- `AbstractBasketMapper`
- `AbstractStatusMapper`

✅ **Interfaces**:
- `PaymentAdapterInterface`
- `WebhookVerifierInterface`
- `WebhookParserInterface`
- `SDKClientInterface`

✅ **Request/Response DTOs**:
- `CreatePaymentRequest`, `CapturePaymentRequest`, `RefundPaymentRequest`, `VoidPaymentRequest`
- `PaymentResponse`, `CaptureResponse`, `RefundResponse`, `PaymentDetailsResponse`
- `WebhookEvent`

✅ **Utilities**:
- Amount conversion helpers (float ↔ cents)
- Currency normalizers (EUR ↔ eur)
- Debugging/logging infrastructure

✅ **Exception hierarchy**:
- `PaymentAdapterException` (base)
- `CardDeclinedException`
- `AuthenticationRequiredException`
- `NetworkException`
- `WebhookVerificationException`

### 3.3 What Provider Extensions Implement

Provider extensions (Stripe, Unzer, etc.) implement **only**:

❌ **Provider-specific translation logic** (~30% of code):
- Concrete `StripeAdapter implements PaymentAdapterInterface`
- SDK-specific request formatting
- SDK-specific response parsing
- Status code mapping
- Error code translation

---

## 4. Unified SDK-Adapter Architecture

### 4.1 Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         PAYMENT COMPONENT (Reusable)                         │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │                    SDK-Adapter Abstraction Layer                      │  │
│  ├──────────────────────────────────────────────────────────────────────┤  │
│  │                                                                        │  │
│  │  Interfaces:                                                          │  │
│  │  • PaymentAdapterInterface (create, capture, refund, void)           │  │
│  │  • SDKClientInterface (initialize, getClient, testConnection)        │  │
│  │  • WebhookVerifierInterface (verify signature)                       │  │
│  │  • WebhookParserInterface (parse payload → WebhookEvent)            │  │
│  │                                                                        │  │
│  │  Abstract Classes:                                                    │  │
│  │  • AbstractSDKClientFactory (credential management)                  │  │
│  │  • AbstractConfigurationService (settings, keys, mode)               │  │
│  │  • AbstractCustomerMapper (OXID User → Provider Customer)           │  │
│  │  • AbstractBasketMapper (OXID Basket → Provider Order)              │  │
│  │  • AbstractStatusMapper (Provider states → Component states)        │  │
│  │                                                                        │  │
│  │  DTOs:                                                                 │  │
│  │  • CreatePaymentRequest, CapturePaymentRequest, ...                  │  │
│  │  • PaymentResponse, CaptureResponse, ...                             │  │
│  │  • WebhookEvent (normalized webhook data)                            │  │
│  │                                                                        │  │
│  │  Utilities:                                                            │  │
│  │  • AmountConverter (float ↔ cents, currency rounding)                │  │
│  │  • CurrencyNormalizer (EUR ↔ eur)                                    │  │
│  │  • TransactionLogger (debug SDK calls)                               │  │
│  │                                                                        │  │
│  │  Exceptions:                                                           │  │
│  │  • PaymentAdapterException, CardDeclinedException, ...               │  │
│  │                                                                        │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                    ▲
                                    │ implements
                                    │
┌─────────────────────────────────────────────────────────────────────────────┐
│                    PROVIDER EXTENSIONS (Provider-Specific)                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Stripe Extension:                Unzer Extension:               PayPal:    │
│  ┌────────────────────┐           ┌─────────────────────┐      ┌──────────┐│
│  │ StripeAdapter      │           │ UnzerAdapter        │      │ PayPal...││
│  │ StripeClientFactory│           │ UnzerSDKLoader      │      │          ││
│  │ StripeConfig       │           │ UnzerConfig         │      │          ││
│  │ StripeCustomerMap  │           │ UnzerCustomerMapper │      │          ││
│  │ StripeBasketMap    │           │ UnzerBasketMapper   │      │          ││
│  │ StripeWebhookVerif │           │ UnzerWebhookVerifier│      │          ││
│  │ StripeStatusMapper │           │ UnzerStatusMapper   │      │          ││
│  └────────────────────┘           └─────────────────────┘      └──────────┘│
│         │                                  │                         │       │
│         │ uses                             │ uses                    │ uses  │
│         ▼                                  ▼                         ▼       │
│  ┌────────────────────┐           ┌─────────────────────┐      ┌──────────┐│
│  │ Stripe SDK         │           │ Unzer SDK           │      │ PayPal   ││
│  │ stripe/stripe-php  │           │ unzerdev/php-sdk    │      │ SDK      ││
│  └────────────────────┘           └─────────────────────┘      └──────────┘│
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 4.2 Layer Responsibilities

#### Component SDK-Adapter Layer (100% Reusable)

**Provides**:
- Interfaces all providers must implement
- Abstract base classes with common logic
- Request/Response DTOs for all payment operations
- Utility classes for amount/currency conversion
- Exception hierarchy
- Debugging/logging infrastructure

**Does NOT contain**:
- Any provider-specific code
- Direct SDK dependencies
- Provider API calls

#### Provider Extension Layer (30% Code, 100% Pattern)

**Implements**:
- Concrete adapter for specific provider SDK
- SDK client initialization logic
- Request translation (Component DTO → Provider API format)
- Response translation (Provider API → Component DTO)
- Status mapping (Provider states → Component states)
- Webhook verification and parsing
- Error translation (Provider exceptions → Component exceptions)

**Does NOT implement**:
- Business logic (handled by component `PaymentService`)
- Transaction persistence (handled by component repositories)
- Order state management (handled by component state machine)

---

## 5. Component Responsibilities

### 5.1 Interfaces

**File:** `src/Component/Contract/PaymentAdapterInterface.php`

```php
interface PaymentAdapterInterface
{
    // Payment operations
    public function createPayment(CreatePaymentRequest $request): PaymentResponse;
    public function capturePayment(CapturePaymentRequest $request): CaptureResponse;
    public function refundPayment(RefundPaymentRequest $request): RefundResponse;
    public function voidPayment(VoidPaymentRequest $request): VoidResponse;
    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse;

    // Metadata
    public function getSupportedPaymentMethods(): array;
    public function getProviderName(): string;
    public function supportsFeature(string $feature): bool;

    // Webhook handling
    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent;
}
```

**File:** `src/Component/Contract/SDKClientInterface.php`

```php
interface SDKClientInterface
{
    /**
     * Initialize SDK client with credentials
     */
    public function initialize(array $credentials, bool $sandbox = false): void;

    /**
     * Get initialized SDK client instance
     */
    public function getClient(): object;

    /**
     * Test connection with credentials
     */
    public function testConnection(): bool;

    /**
     * Get SDK version
     */
    public function getSDKVersion(): string;
}
```

### 5.2 Abstract Base Classes

**File:** `src/Component/Adapter/AbstractSDKClientFactory.php`

```php
abstract class AbstractSDKClientFactory implements SDKClientInterface
{
    protected array $credentials = [];
    protected bool $sandbox = false;
    protected ?object $client = null;

    public function __construct(array $credentials, bool $sandbox = false)
    {
        $this->credentials = $credentials;
        $this->sandbox = $sandbox;
    }

    /**
     * Initialize SDK client - implemented by each provider
     */
    abstract protected function createSDKClient(): object;

    /**
     * Validate credentials - implemented by each provider
     */
    abstract protected function validateCredentials(array $credentials): void;

    public function initialize(array $credentials, bool $sandbox = false): void
    {
        $this->validateCredentials($credentials);
        $this->credentials = $credentials;
        $this->sandbox = $sandbox;
        $this->client = $this->createSDKClient();
    }

    public function getClient(): object
    {
        if ($this->client === null) {
            $this->client = $this->createSDKClient();
        }
        return $this->client;
    }
}
```

**File:** `src/Component/Adapter/AbstractCustomerMapper.php`

```php
abstract class AbstractCustomerMapper
{
    /**
     * Map OXID User to provider customer format
     *
     * @param User $oxidUser OXID user object
     * @param Order|null $order Optional order for delivery address
     * @return mixed Provider-specific customer object
     */
    abstract public function mapToProviderCustomer(User $oxidUser, ?Order $order = null): mixed;

    /**
     * Get customer salutation in provider format
     */
    protected function normalizeSalutation(?string $salutation): string
    {
        return match(strtolower($salutation ?? '')) {
            'mr', 'herr' => 'mr',
            'mrs', 'ms', 'frau' => 'mrs',
            default => 'unknown',
        };
    }

    /**
     * Format birthdate for provider
     */
    protected function formatBirthdate(?string $birthdate): string
    {
        if (!$birthdate || $birthdate === '0000-00-00') {
            return '';
        }
        return $birthdate;
    }
}
```

**File:** `src/Component/Adapter/AbstractBasketMapper.php`

```php
abstract class AbstractBasketMapper
{
    /**
     * Map OXID Basket to provider order/basket format
     *
     * @param Basket $oxidBasket OXID basket object
     * @param string $orderId Order/transaction ID
     * @return mixed Provider-specific basket/order object
     */
    abstract public function mapToProviderBasket(Basket $oxidBasket, string $orderId): mixed;

    /**
     * Calculate basket total with rounding
     */
    protected function calculateTotal(Basket $basket): float
    {
        return round($basket->getPrice()->getBruttoPrice(), 2);
    }

    /**
     * Get basket currency code
     */
    protected function getCurrencyCode(Basket $basket): string
    {
        return $basket->getBasketCurrency()->name;
    }
}
```

**File:** `src/Component/Adapter/AbstractStatusMapper.php`

```php
abstract class AbstractStatusMapper
{
    /**
     * Map provider-specific status to component status
     *
     * Component statuses: pending, authorized, captured, failed, canceled
     */
    abstract public function mapProviderStatus(string $providerStatus): string;

    /**
     * Check if provider status indicates success
     */
    public function isSuccessStatus(string $providerStatus): bool
    {
        $componentStatus = $this->mapProviderStatus($providerStatus);
        return in_array($componentStatus, ['authorized', 'captured']);
    }

    /**
     * Check if provider status indicates failure
     */
    public function isFailureStatus(string $providerStatus): bool
    {
        $componentStatus = $this->mapProviderStatus($providerStatus);
        return in_array($componentStatus, ['failed', 'canceled']);
    }
}
```

### 5.3 Utility Classes

**File:** `src/Component/Adapter/Util/AmountConverter.php`

```php
final class AmountConverter
{
    /**
     * Convert float amount to cents (integer)
     * Used by: Stripe, PayPal
     */
    public static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Convert cents (integer) to float amount
     */
    public static function fromCents(int $cents): float
    {
        return $cents / 100.0;
    }

    /**
     * Round amount to 2 decimal places
     * Used by: Unzer, TeleCash
     */
    public static function roundAmount(float $amount): float
    {
        return round($amount, 2);
    }

    /**
     * Format amount as string with 2 decimals
     * Used by: TeleCash XML formatting
     */
    public static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
```

**File:** `src/Component/Adapter/Util/CurrencyNormalizer.php`

```php
final class CurrencyNormalizer
{
    /**
     * Convert currency to uppercase (EUR, USD, GBP)
     * Component format
     */
    public static function toUppercase(string $currency): string
    {
        return strtoupper($currency);
    }

    /**
     * Convert currency to lowercase (eur, usd, gbp)
     * Used by: Stripe
     */
    public static function toLowercase(string $currency): string
    {
        return strtolower($currency);
    }

    /**
     * Validate ISO 4217 currency code
     */
    public static function isValid(string $currency): bool
    {
        return strlen($currency) === 3 && ctype_alpha($currency);
    }
}
```

---

## 6. Provider Extension Responsibilities

### 6.1 Stripe Adapter Example

**File:** `src/Stripe/Adapter/StripeAdapter.php`

```php
final class StripeAdapter implements PaymentAdapterInterface
{
    private StripeClient $client;
    private StripeStatusMapper $statusMapper;

    public function __construct(string $apiKey, bool $sandbox = false)
    {
        $this->client = new StripeClient($apiKey);
        $this->statusMapper = new StripeStatusMapper();
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        try {
            // Translate component request → Stripe format
            $intent = $this->client->paymentIntents->create([
                'amount' => AmountConverter::toCents($request->getAmount()),
                'currency' => CurrencyNormalizer::toLowercase($request->getCurrency()),
                'capture_method' => $request->isDirectCapture() ? 'automatic' : 'manual',
                'metadata' => [
                    'order_id' => $request->getOrderId(),
                    'shop_id' => $request->getShopId(),
                ],
            ]);

            // Translate Stripe response → component format
            return new PaymentResponse(
                providerPaymentId: $intent->id,
                status: $this->statusMapper->mapProviderStatus($intent->status),
                amount: AmountConverter::fromCents($intent->amount),
                currency: CurrencyNormalizer::toUppercase($intent->currency),
                clientSecret: $intent->client_secret,
            );

        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw PaymentAdapterException::fromProviderError(
                provider: 'stripe',
                message: $e->getMessage(),
                code: $e->getStripeCode() ?? 'unknown',
                previous: $e
            );
        }
    }

    public function getProviderName(): string
    {
        return 'stripe';
    }

    public function getSupportedPaymentMethods(): array
    {
        return ['card', 'sepa_debit', 'giropay', 'sofort'];
    }
}
```

**File:** `src/Stripe/Adapter/StripeStatusMapper.php`

```php
final class StripeStatusMapper extends AbstractStatusMapper
{
    public function mapProviderStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'requires_payment_method', 'requires_confirmation' => 'pending',
            'requires_action' => 'requires_action',
            'requires_capture' => 'authorized',
            'succeeded' => 'captured',
            'canceled' => 'canceled',
            'payment_failed' => 'failed',
            default => 'unknown',
        };
    }
}
```

**File:** `src/Stripe/Adapter/StripeCustomerMapper.php`

```php
final class StripeCustomerMapper extends AbstractCustomerMapper
{
    public function mapToProviderCustomer(User $oxidUser, ?Order $order = null): array
    {
        return [
            'name' => $oxidUser->getFieldData('oxfname') . ' ' . $oxidUser->getFieldData('oxlname'),
            'email' => $oxidUser->getFieldData('oxusername'),
            'phone' => $oxidUser->getFieldData('oxfon'),
            'address' => [
                'line1' => trim($oxidUser->getFieldData('oxstreet') . ' ' . $oxidUser->getFieldData('oxstreetnr')),
                'city' => $oxidUser->getFieldData('oxcity'),
                'postal_code' => $oxidUser->getFieldData('oxzip'),
                'country' => $this->getCountryCode($oxidUser->getFieldData('oxcountryid')),
            ],
        ];
    }

    private function getCountryCode(string $countryId): string
    {
        $country = oxNew(\OxidEsales\Eshop\Application\Model\Country::class);
        return $country->load($countryId) ? $country->getFieldData('oxisoalpha2') : '';
    }
}
```

### 6.2 Unzer Adapter Example

**File:** `src/Unzer/Adapter/UnzerAdapter.php`

```php
final class UnzerAdapter implements PaymentAdapterInterface
{
    private \UnzerSDK\Unzer $sdk;
    private UnzerStatusMapper $statusMapper;
    private UnzerCustomerMapper $customerMapper;
    private UnzerBasketMapper $basketMapper;

    public function __construct(string $privateKey, bool $sandbox = false)
    {
        $this->sdk = new \UnzerSDK\Unzer($privateKey);
        $this->statusMapper = new UnzerStatusMapper();
        $this->customerMapper = new UnzerCustomerMapper();
        $this->basketMapper = new UnzerBasketMapper();
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        try {
            // Get OXID objects (passed via metadata or context)
            $oxidUser = $this->getOxidUser($request);
            $oxidBasket = $this->getOxidBasket($request);

            // Map to Unzer format
            $unzerCustomer = $this->customerMapper->mapToProviderCustomer($oxidUser);
            $unzerBasket = $this->basketMapper->mapToProviderBasket($oxidBasket, $request->getOrderId());

            // Create payment via Unzer SDK
            if ($request->isDirectCapture()) {
                $charge = $this->sdk->charge(
                    AmountConverter::roundAmount($request->getAmount()),
                    $request->getCurrency(), // Unzer uses uppercase
                    $request->getPaymentMethod(),
                    $request->getReturnUrl(),
                    $unzerCustomer,
                    $request->getOrderId(),
                    null, // metadata
                    $unzerBasket
                );
                $providerPaymentId = $charge->getPaymentId();
                $status = $this->statusMapper->mapProviderStatus($charge->getState());
            } else {
                $authorization = $this->sdk->authorize(
                    AmountConverter::roundAmount($request->getAmount()),
                    $request->getCurrency(),
                    $request->getPaymentMethod(),
                    $request->getReturnUrl(),
                    $unzerCustomer,
                    $request->getOrderId(),
                    null,
                    $unzerBasket
                );
                $providerPaymentId = $authorization->getPaymentId();
                $status = $this->statusMapper->mapProviderStatus($authorization->getState());
            }

            return new PaymentResponse(
                providerPaymentId: $providerPaymentId,
                status: $status,
                amount: AmountConverter::roundAmount($request->getAmount()),
                currency: $request->getCurrency(),
            );

        } catch (\UnzerSDK\Exceptions\UnzerApiException $e) {
            throw PaymentAdapterException::fromProviderError(
                provider: 'unzer',
                message: $e->getClientMessage(),
                code: $e->getErrorId(),
                previous: $e
            );
        }
    }

    public function getProviderName(): string
    {
        return 'unzer';
    }
}
```

---

## 7. Implementation Examples

### 7.1 PaymentService Using Adapter

**File:** `src/Component/Service/PaymentService.php`

```php
final class PaymentService
{
    public function __construct(
        private readonly PaymentAdapterInterface $adapter,
        private readonly PaymentTransactionRepositoryInterface $transactionRepo,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    public function initiatePayment(
        string $orderId,
        string $shopId,
        float $amount,
        string $currency,
        string $paymentMethod,
        bool $directCapture = true
    ): PaymentResponse {
        // Create provider-agnostic request
        $request = new CreatePaymentRequest(
            amount: $amount,
            currency: $currency,
            orderId: $orderId,
            shopId: $shopId,
            paymentMethod: $paymentMethod,
            directCapture: $directCapture
        );

        try {
            // Call adapter (works with ANY provider)
            $response = $this->adapter->createPayment($request);

            // Save transaction
            $transaction = new PaymentTransaction(
                shopId: $shopId,
                orderId: $orderId,
                providerOrderId: $response->getProviderPaymentId(),
                status: $response->getStatus(),
                paymentMethodId: $paymentMethod,
                transactionType: $directCapture ? 'capture' : 'authorization'
            );
            $this->transactionRepo->save($transaction);

            // Dispatch event
            $this->eventDispatcher->dispatch(
                new PaymentInitiatedEvent($orderId, $response->getProviderPaymentId())
            );

            return $response;

        } catch (PaymentAdapterException $e) {
            throw new PaymentException(
                "Payment initiation failed: {$e->getMessage()}",
                previous: $e
            );
        }
    }
}
```

### 7.2 Provider Switching via Configuration

**File:** `src/Component/Adapter/AdapterFactory.php`

```php
final class AdapterFactory
{
    public function __construct(
        private readonly ModuleSettings $settings
    ) {}

    public function createAdapter(string $providerName): PaymentAdapterInterface
    {
        return match($providerName) {
            'stripe' => $this->createStripeAdapter(),
            'unzer' => $this->createUnzerAdapter(),
            'paypal' => $this->createPayPalAdapter(),
            default => throw new \InvalidArgumentException("Unknown provider: $providerName"),
        };
    }

    public function createDefaultAdapter(): PaymentAdapterInterface
    {
        $defaultProvider = $this->settings->getDefaultProvider();
        return $this->createAdapter($defaultProvider);
    }

    private function createStripeAdapter(): StripeAdapter
    {
        $apiKey = $this->settings->getProviderCredential('stripe', 'api_key');
        $sandbox = $this->settings->isSandboxMode('stripe');
        return new StripeAdapter($apiKey, $sandbox);
    }

    private function createUnzerAdapter(): UnzerAdapter
    {
        $privateKey = $this->settings->getProviderCredential('unzer', 'private_key');
        $sandbox = $this->settings->isSandboxMode('unzer');
        return new UnzerAdapter($privateKey, $sandbox);
    }
}
```

---

## 8. Migration Path

### 8.1 For Existing Stripe Module

**Current State:**
```php
// Direct SDK usage in payment logic
$client = new StripeClient($apiKey);
$intent = $client->paymentIntents->create([...]);
```

**Target State:**
```php
// Use adapter interface
$adapter = $this->adapterFactory->createAdapter('stripe');
$request = new CreatePaymentRequest(...);
$response = $adapter->createPayment($request);
```

**Migration Steps:**
1. Extract `StripeAdapter` implementing `PaymentAdapterInterface`
2. Move SDK initialization to `StripeClientFactory`
3. Create `StripeStatusMapper`, `StripeCustomerMapper`, `StripeBasketMapper`
4. Update `PaymentService` to use adapter instead of direct SDK calls
5. Add adapter tests (unit + integration)

### 8.2 For Existing Unzer Module

**Current State:**
```php
// Complex SDK initialization
$sdk = $this->unzerSDKLoader->getUnzerSDK($paymentId, $currency, $customerType);
$charge = $sdk->charge($amount, $currency, ...);
```

**Target State:**
```php
// Use adapter interface
$adapter = $this->adapterFactory->createAdapter('unzer');
$request = new CreatePaymentRequest(...);
$response = $adapter->createPayment($request);
```

**Migration Steps:**
1. Extract `UnzerAdapter` implementing `PaymentAdapterInterface`
2. Simplify `UnzerSDKLoader` to `UnzerClientFactory`
3. Move customer/basket transformation to mappers
4. Update payment service to use adapter
5. Maintain backward compatibility during transition

---

## Conclusion

This unified SDK-Adapter approach ensures:

1. ✅ **Maximum Code Reuse**: Component provides 100% of abstract/interface code
2. ✅ **Provider Independence**: Business logic never touches SDK directly
3. ✅ **Easy Provider Addition**: New provider = implement interface (~30% code)
4. ✅ **Easy Provider Switching**: Change configuration, not code
5. ✅ **Consistent Testing**: Mock adapter interface, not provider SDKs
6. ✅ **SDK Updates**: Provider SDK changes don't affect component

**Next Steps:**
1. Implement component SDK-Adapter layer (TICKET-005)
2. Migrate Stripe module to use adapter (TICKET-006)
3. Migrate Unzer module to use adapter
4. Create adapters for PayPal, Adyen, TeleCash

---

**Document Status:** Ready for Implementation
**Review Required:** Yes
**Approval:** Pending
