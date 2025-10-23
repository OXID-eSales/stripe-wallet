[← Previous: TICKET-004](SPRINT-1-TICKET-04-repositories.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md)

---

# TICKET-005: SDK-Adapter Layer (Provider Abstraction)

## Summary
Implement SDK-Adapter layer in `src/Component/Adapter/` that provides a unified, provider-agnostic interface for payment provider SDK integration.

## Priority
**P1 - High** (Blocks TICKET-006)

## Story Points
**8 points** (2 days)

## Business Value
Provides a clean abstraction layer between the payment component and provider-specific SDKs (Stripe, Unzer, PayPal), making it easy to add new providers and switch between them.

---

## Description

Create SDK-Adapter layer:
- PaymentAdapterInterface (provider-agnostic contract)
- Request/Response DTOs (normalized data structures)
- StripeAdapter (Stripe SDK implementation)
- AdapterFactory (configuration-driven adapter creation)
- Unified exception handling

All in `src/Component/Adapter/` namespace (100% reusable), with provider-specific adapters in respective provider namespaces.

---

## Acceptance Criteria

### Must Have
- [ ] PaymentAdapterInterface in `src/Component/Contract/`
- [ ] Request objects in `src/Component/Adapter/Request/`
  - [ ] CreatePaymentRequest
  - [ ] CapturePaymentRequest
  - [ ] RefundPaymentRequest
  - [ ] VoidPaymentRequest
- [ ] Response objects in `src/Component/Adapter/Response/`
  - [ ] PaymentResponse
  - [ ] CaptureResponse
  - [ ] RefundResponse
  - [ ] PaymentDetailsResponse
- [ ] WebhookEvent interface in `src/Component/Adapter/`
- [ ] PaymentAdapterException hierarchy in `src/Component/Adapter/Exception/`
- [ ] StripeAdapter in `src/Stripe/Adapter/`
- [ ] AdapterFactory in `src/Component/Adapter/`
- [ ] 100% unit test coverage for interface & DTOs
- [ ] 90% unit test coverage for StripeAdapter (with mocked Stripe SDK)
- [ ] Integration tests with real Stripe API in sandbox mode

### Should Have
- [ ] UnzerAdapter stub in `src/Unzer/Adapter/`
- [ ] PayPalAdapter stub in `src/PayPal/Adapter/`
- [ ] Adapter feature detection (`supportsFeature()`)

---

## Technical Details

### PaymentAdapterInterface

```php
<?php
// src/Component/Contract/PaymentAdapterInterface.php

namespace Osc\Payment\Component\Contract;

use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use Osc\Payment\Component\Adapter\Request\CapturePaymentRequest;
use Osc\Payment\Component\Adapter\Request\RefundPaymentRequest;
use Osc\Payment\Component\Adapter\Request\VoidPaymentRequest;
use Osc\Payment\Component\Adapter\Response\PaymentResponse;
use Osc\Payment\Component\Adapter\Response\CaptureResponse;
use Osc\Payment\Component\Adapter\Response\RefundResponse;
use Osc\Payment\Component\Adapter\Response\VoidResponse;
use Osc\Payment\Component\Adapter\Response\PaymentDetailsResponse;
use Osc\Payment\Component\Adapter\WebhookEvent;

/**
 * Payment Adapter Interface
 *
 * Provider-agnostic contract for payment provider integration.
 * All providers (Stripe, Unzer, PayPal, etc.) implement this interface.
 */
interface PaymentAdapterInterface
{
    /**
     * Create a payment (authorization or direct capture)
     */
    public function createPayment(CreatePaymentRequest $request): PaymentResponse;

    /**
     * Capture an authorized payment
     */
    public function capturePayment(CapturePaymentRequest $request): CaptureResponse;

    /**
     * Refund a captured payment
     */
    public function refundPayment(RefundPaymentRequest $request): RefundResponse;

    /**
     * Void/cancel an authorized payment
     */
    public function voidPayment(VoidPaymentRequest $request): VoidResponse;

    /**
     * Get payment details by provider payment ID
     */
    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse;

    /**
     * Get supported payment methods
     * @return array<string, array> [method_id => ['name' => '...', 'type' => '...']]
     */
    public function getSupportedPaymentMethods(): array;

    /**
     * Parse and verify webhook payload
     * @throws PaymentAdapterException on invalid signature
     */
    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent;

    /**
     * Get provider name (stripe, unzer, paypal, etc.)
     */
    public function getProviderName(): string;

    /**
     * Check if provider supports a specific feature
     * @param string $feature (e.g., 'separate_authorization', 'refunds', 'recurring')
     */
    public function supportsFeature(string $feature): bool;
}
```

### Request/Response DTOs

```php
<?php
// src/Component/Adapter/Request/CreatePaymentRequest.php

namespace Osc\Payment\Component\Adapter\Request;

/**
 * Create Payment Request
 *
 * Provider-agnostic request for creating a payment.
 * Adapters translate this to provider-specific formats.
 */
final readonly class CreatePaymentRequest
{
    public function __construct(
        public float $amount,
        public string $currency,
        public string $orderId,
        public string $shopId,
        public string $paymentMethod,
        public bool $directCapture = true,
        public ?string $paymentMethodId = null,
        public ?string $customerId = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public array $metadata = []
    ) {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('Currency must be 3-letter ISO code');
        }
    }

    public function getAmount(): float { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getOrderId(): string { return $this->orderId; }
    public function getShopId(): string { return $this->shopId; }
    public function getPaymentMethod(): string { return $this->paymentMethod; }
    public function isDirectCapture(): bool { return $this->directCapture; }
    public function getPaymentMethodId(): ?string { return $this->paymentMethodId; }
    public function getCustomerId(): ?string { return $this->customerId; }
    public function getReturnUrl(): ?string { return $this->returnUrl; }
    public function getCancelUrl(): ?string { return $this->cancelUrl; }
    public function getMetadata(): array { return $this->metadata; }
}
```

```php
<?php
// src/Component/Adapter/Response/PaymentResponse.php

namespace Osc\Payment\Component\Adapter\Response;

/**
 * Payment Response
 *
 * Provider-agnostic response after creating a payment.
 * Adapters translate from provider-specific formats to this.
 */
final readonly class PaymentResponse
{
    public function __construct(
        public string $providerPaymentId,
        public string $status,
        public float $amount,
        public string $currency,
        public ?string $clientSecret = null,
        public bool $requiresAction = false,
        public ?string $nextActionUrl = null,
        public array $metadata = []
    ) {}

    public function getProviderPaymentId(): string { return $this->providerPaymentId; }
    public function getStatus(): string { return $this->status; }
    public function getAmount(): float { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getClientSecret(): ?string { return $this->clientSecret; }
    public function requiresAction(): bool { return $this->requiresAction; }
    public function getNextActionUrl(): ?string { return $this->nextActionUrl; }
    public function getMetadata(): array { return $this->metadata; }

    // Status helpers
    public function isPending(): bool { return $this->status === 'pending'; }
    public function isAuthorized(): bool { return $this->status === 'authorized'; }
    public function isCaptured(): bool { return $this->status === 'captured'; }
    public function isFailed(): bool { return $this->status === 'failed'; }
}
```

### StripeAdapter Implementation

```php
<?php
// src/Stripe/Adapter/StripeAdapter.php

namespace Osc\Payment\Stripe\Adapter;

use Osc\Payment\Component\Contract\PaymentAdapterInterface;
use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use Osc\Payment\Component\Adapter\Response\PaymentResponse;
use Osc\Payment\Component\Adapter\Exception\PaymentAdapterException;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe Adapter
 *
 * Translates between component requests/responses and Stripe SDK.
 */
final class StripeAdapter implements PaymentAdapterInterface
{
    private StripeClient $client;
    private bool $sandbox;

    public function __construct(string $apiKey, bool $sandbox = false)
    {
        $this->client = new StripeClient($apiKey);
        $this->sandbox = $sandbox;
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        try {
            // Translate component request → Stripe format
            $intent = $this->client->paymentIntents->create([
                'amount' => $this->convertAmountToCents($request->getAmount()),
                'currency' => strtolower($request->getCurrency()),
                'capture_method' => $request->isDirectCapture() ? 'automatic' : 'manual',
                'payment_method' => $request->getPaymentMethodId(),
                'customer' => $request->getCustomerId(),
                'metadata' => array_merge(
                    $request->getMetadata(),
                    [
                        'order_id' => $request->getOrderId(),
                        'shop_id' => $request->getShopId(),
                    ]
                ),
            ]);

            // Translate Stripe response → component format
            return new PaymentResponse(
                providerPaymentId: $intent->id,
                status: $this->mapStripeStatus($intent->status),
                amount: $this->convertCentsToAmount($intent->amount),
                currency: strtoupper($intent->currency),
                clientSecret: $intent->client_secret,
                requiresAction: $intent->status === 'requires_action',
                nextActionUrl: $intent->next_action->redirect_to_url->url ?? null
            );

        } catch (ApiErrorException $e) {
            throw PaymentAdapterException::fromProviderError(
                provider: 'stripe',
                message: $e->getMessage(),
                code: $e->getStripeCode() ?? 'unknown',
                previous: $e
            );
        }
    }

    public function capturePayment(CapturePaymentRequest $request): CaptureResponse
    {
        try {
            $intent = $this->client->paymentIntents->capture(
                $request->getProviderPaymentId(),
                ['amount_to_capture' => $this->convertAmountToCents($request->getAmount())]
            );

            return new CaptureResponse(
                providerPaymentId: $intent->id,
                captureId: $intent->charges->data[0]->id ?? $intent->id,
                status: $this->mapStripeStatus($intent->status),
                amount: $this->convertCentsToAmount($intent->amount_received),
                currency: strtoupper($intent->currency)
            );

        } catch (ApiErrorException $e) {
            throw PaymentAdapterException::fromProviderError('stripe', $e->getMessage(), $e->getStripeCode() ?? 'unknown', $e);
        }
    }

    public function getProviderName(): string
    {
        return 'stripe';
    }

    public function supportsFeature(string $feature): bool
    {
        return match($feature) {
            'separate_authorization', 'refunds', 'recurring', 'webhooks' => true,
            default => false,
        };
    }

    // Private translation methods

    private function convertAmountToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function convertCentsToAmount(int $cents): float
    {
        return $cents / 100.0;
    }

    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'requires_payment_method', 'requires_confirmation' => 'pending',
            'requires_action' => 'requires_action',
            'requires_capture' => 'authorized',
            'succeeded' => 'captured',
            'canceled' => 'canceled',
            default => 'unknown',
        };
    }
}
```

---

## TDD Workflow

### Step 1: RED - Write Interface Tests

```php
<?php
// tests/Component/Unit/Component/Adapter/Request/CreatePaymentRequestTest.php

namespace Osc\Payment\Tests\Unit\Component\Adapter\Request;

use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use PHPUnit\Framework\TestCase;

class CreatePaymentRequestTest extends TestCase
{
    /** @test */
    public function it_creates_valid_request(): void
    {
        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order123',
            shopId: '1',
            paymentMethod: 'card'
        );

        $this->assertEquals(99.99, $request->getAmount());
        $this->assertEquals('EUR', $request->getCurrency());
        $this->assertTrue($request->isDirectCapture());
    }

    /** @test */
    public function it_validates_amount_is_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        new CreatePaymentRequest(
            amount: -10.00,
            currency: 'EUR',
            orderId: 'order123',
            shopId: '1',
            paymentMethod: 'card'
        );
    }

    /** @test */
    public function it_validates_currency_is_three_letters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EURO',
            orderId: 'order123',
            shopId: '1',
            paymentMethod: 'card'
        );
    }

    /** @test */
    public function it_is_immutable_after_creation(): void
    {
        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order123',
            shopId: '1',
            paymentMethod: 'card'
        );

        // Should not have setters
        $this->assertFalse(method_exists($request, 'setAmount'));
        $this->assertFalse(method_exists($request, 'setCurrency'));
    }
}
```

### Step 2: GREEN - Implement DTOs

Implement all request/response objects with validation.

### Step 3: RED - Write Adapter Tests

```php
<?php
// tests/Component/Unit/Stripe/Adapter/StripeAdapterTest.php

namespace Osc\Payment\Tests\Unit\Stripe\Adapter;

use Mockery;
use Osc\Payment\Stripe\Adapter\StripeAdapter;
use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;

class StripeAdapterTest extends TestCase
{
    /** @test */
    public function it_creates_payment_and_translates_to_component_format(): void
    {
        // Mock Stripe SDK
        $stripeMock = Mockery::mock(StripeClient::class);
        $stripeMock->paymentIntents = Mockery::mock();

        $stripeMock->paymentIntents
            ->shouldReceive('create')
            ->once()
            ->with([
                'amount' => 9999, // cents
                'currency' => 'eur', // lowercase
                'capture_method' => 'automatic',
                'payment_method' => null,
                'customer' => null,
                'metadata' => ['order_id' => 'order123', 'shop_id' => '1'],
            ])
            ->andReturn((object)[
                'id' => 'pi_123',
                'status' => 'requires_capture',
                'amount' => 9999,
                'currency' => 'eur',
                'client_secret' => 'pi_123_secret',
            ]);

        $adapter = new StripeAdapter('sk_test_123');
        // Inject mock (need to add setter for testing)

        $request = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: 'order123',
            shopId: '1',
            paymentMethod: 'card',
            directCapture: true
        );

        $response = $adapter->createPayment($request);

        $this->assertEquals('pi_123', $response->getProviderPaymentId());
        $this->assertEquals('authorized', $response->getStatus());
        $this->assertEquals(99.99, $response->getAmount());
        $this->assertEquals('EUR', $response->getCurrency());
    }
}
```

### Step 4: GREEN - Implement StripeAdapter

### Step 5: Integration Tests with Real Stripe API

```php
<?php
// tests/Component/Integration/Stripe/Adapter/StripeAdapterIntegrationTest.php

namespace Osc\Payment\Tests\Integration\Stripe\Adapter;

use Osc\Payment\Stripe\Adapter\StripeAdapter;
use Osc\Payment\Component\Adapter\Request\CreatePaymentRequest;
use PHPUnit\Framework\TestCase;

class StripeAdapterIntegrationTest extends TestCase
{
    private StripeAdapter $adapter;

    protected function setUp(): void
    {
        $apiKey = $_ENV['STRIPE_TEST_KEY'] ?? $this->markTestSkipped('Stripe test key not configured');
        $this->adapter = new StripeAdapter($apiKey, sandbox: true);
    }

    /** @test */
    public function it_creates_payment_with_real_stripe_api(): void
    {
        $request = new CreatePaymentRequest(
            amount: 10.00,
            currency: 'EUR',
            orderId: 'test_order_' . time(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $response = $this->adapter->createPayment($request);

        $this->assertStringStartsWith('pi_', $response->getProviderPaymentId());
        $this->assertEquals('authorized', $response->getStatus());
        $this->assertEquals(10.00, $response->getAmount());
    }
}
```

---

## Tasks Breakdown

1. **Adapter Interface & Request/Response DTOs** (3 hours)
   - [ ] Define PaymentAdapterInterface
   - [ ] Create all request objects (CreatePayment, Capture, Refund, Void)
   - [ ] Create all response objects (Payment, Capture, Refund, PaymentDetails)
   - [ ] Write comprehensive unit tests
   - [ ] Test immutability and validation

2. **Exception Handling** (1 hour)
   - [ ] Create PaymentAdapterException base class
   - [ ] Create specific exceptions (CardDeclined, AuthenticationRequired, NetworkException)
   - [ ] Write exception tests

3. **StripeAdapter Implementation** (4 hours)
   - [ ] Implement all PaymentAdapterInterface methods
   - [ ] Add private translation methods (amount, currency, status mapping)
   - [ ] Write unit tests with mocked Stripe SDK
   - [ ] Test error handling and exception mapping

4. **AdapterFactory** (2 hours)
   - [ ] Create AdapterFactory with configuration-driven creation
   - [ ] Support multiple providers (Stripe, Unzer, PayPal)
   - [ ] Write factory tests
   - [ ] Test default adapter selection

5. **Integration Tests** (2 hours)
   - [ ] Test StripeAdapter with real Stripe API (sandbox)
   - [ ] Test full payment flow: create → capture → refund
   - [ ] Test webhook parsing with real Stripe events
   - [ ] Document test API keys setup

6. **Documentation** (1 hour)
   - [ ] Document adapter pattern benefits
   - [ ] Create examples of adding new providers
   - [ ] Document testing strategy

---

## Definition of Done

- [ ] All acceptance criteria met
- [ ] PaymentAdapterInterface and DTOs implemented
- [ ] StripeAdapter fully implemented
- [ ] AdapterFactory with configuration support
- [ ] 100% unit test coverage for interface & DTOs
- [ ] 90% unit test coverage for StripeAdapter
- [ ] Integration tests pass with real Stripe API (sandbox)
- [ ] PHPStan level 6 passes
- [ ] Documentation complete
- [ ] PR reviewed and approved

---

## Dependencies

- TICKET-004 (needs repositories for transaction persistence)

---

## Related Tickets

- Blocks TICKET-006 (Stripe Payment Service will use adapter)

---


---

[← Previous: TICKET-004](SPRINT-1-TICKET-04-repositories.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md)
