# Provider Abstraction Architecture

**Date:** 2026-02-04
**Based on:** Actual code analysis

---

## Overview

The system implements a **provider-agnostic design** where:
- `payment-component` defines interfaces and DTOs
- `stripe` (and future providers) implement those interfaces

This allows adding new payment providers without modifying core code.

---

## Adapter Pattern

```
┌─────────────────────────────────────────────────────────────────┐
│                        payment-component                         │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              PaymentAdapterInterface                      │   │
│  │  - createPayment()    - refundPayment()                   │   │
│  │  - authorizePayment() - voidPayment()                     │   │
│  │  - capturePayment()   - createPaymentMethod()             │   │
│  └──────────────────────────────────────────────────────────┘   │
│                              ▲                                   │
│                              │ implements                        │
└──────────────────────────────┼───────────────────────────────────┘
                               │
┌──────────────────────────────┼───────────────────────────────────┐
│                          stripe                                  │
│                              │                                   │
│  ┌───────────────────────────┴──────────────────────────────┐   │
│  │                    StripeAdapter                          │   │
│  │  - Uses Stripe PHP SDK                                    │   │
│  │  - Translates to/from Stripe API formats                  │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

---

## PaymentAdapterInterface

**Location:** `payment-component/src/Adapter/PaymentAdapterInterface.php`

```php
interface PaymentAdapterInterface
{
    // Basic Operations
    public function createPayment(CreatePaymentRequest $request): PaymentResponse;
    public function capturePayment(CapturePaymentRequest $request): CaptureResponse;
    public function refundPayment(RefundPaymentRequest $request): RefundResponse;
    public function voidPayment(VoidPaymentRequest $request): VoidResponse;

    // Two-Step Authorization
    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse;
    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse;
    public function voidAuthorization(VoidAuthorizationRequest $request): VoidResponse;
    public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse;

    // Payment Methods (Vaulting)
    public function createPaymentMethod(CreatePaymentMethodRequest $request): PaymentMethodResponse;
    public function listPaymentMethods(string $customerId): array;
    public function deletePaymentMethod(string $paymentMethodId): bool;

    // 3D Secure
    public function initiate3DSecure(ThreeDSecureRequest $request): ThreeDSecureResponse;
    public function verify3DSecureResult(string $resultToken): ThreeDSecureResponse;

    // Metadata
    public function getSupportedPaymentMethods(): array;
    public function getProviderName(): string;
    public function supportsFeature(string $feature): bool;

    // Webhooks
    public function parseWebhook(string $payload, array $headers): WebhookEvent;
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool;

    // Details
    public function getPaymentDetails(string $paymentId): PaymentDetailsResponse;
}
```

---

## Request/Response DTOs

### Request Classes

All requests are immutable value objects:

```php
// payment-component/src/Adapter/Request/

class CreatePaymentRequest
{
    public function __construct(
        private string $amount,
        private string $currency,
        private string $description,
        private ?string $customerId = null,
        private ?string $paymentMethodId = null,
        private array $metadata = []
    ) {}

    public function getAmount(): string { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    // ...
}

class AuthorizePaymentRequest
{
    public function __construct(
        private string $amount,
        private string $currency,
        private string $description,
        private bool $captureNow = false,
        // ...
    ) {}
}

class CapturePaymentRequest
{
    public function __construct(
        private string $paymentId,
        private ?string $amount = null  // null = full amount
    ) {}
}

class RefundPaymentRequest
{
    public function __construct(
        private string $paymentId,
        private ?string $amount = null,  // null = full amount
        private ?string $reason = null
    ) {}
}
```

### Response Classes

All responses carry provider-agnostic data:

```php
// payment-component/src/Adapter/Response/

class PaymentResponse
{
    public function __construct(
        private string $providerPaymentId,
        private string $status,
        private string $amount,
        private string $currency,
        private bool $requiresAction = false,
        private ?string $clientSecret = null,
        private ?string $redirectUrl = null,
        private array $metadata = []
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }

    public function requiresRedirect(): bool
    {
        return $this->requiresAction && $this->redirectUrl !== null;
    }
}

class CaptureResponse
{
    public function __construct(
        private string $captureId,
        private string $status,
        private string $amount,
        private string $currency,
        private ?\DateTimeInterface $capturedAt = null
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === 'captured';
    }
}

class RefundResponse
{
    public function __construct(
        private string $refundId,
        private string $status,
        private string $amount,
        private string $currency,
        private ?string $reason = null
    ) {}
}
```

---

## StripeAdapter Implementation

**Location:** `stripe/src/Stripe/Adapter/StripeAdapter.php`

```php
class StripeAdapter implements StripeAdapterInterface
{
    public function __construct(
        private StripeClient $stripeClient,
        private ModuleConfigurationService $config,
        private LoggerInterface $logger
    ) {}

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        $paymentIntent = $this->stripeClient->paymentIntents->create([
            'amount' => $this->convertToCents($request->getAmount()),
            'currency' => strtolower($request->getCurrency()),
            'description' => $request->getDescription(),
            'metadata' => $request->getMetadata(),
        ]);

        return new PaymentResponse(
            providerPaymentId: $paymentIntent->id,
            status: $this->mapStatus($paymentIntent->status),
            amount: $request->getAmount(),
            currency: $request->getCurrency(),
            requiresAction: $paymentIntent->status === 'requires_action',
            clientSecret: $paymentIntent->client_secret
        );
    }

    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse
    {
        $paymentIntent = $this->stripeClient->paymentIntents->create([
            'amount' => $this->convertToCents($request->getAmount()),
            'currency' => strtolower($request->getCurrency()),
            'capture_method' => 'manual',  // Key difference for authorization
            // ...
        ]);

        return new AuthorizationResponse(
            authorizationId: $paymentIntent->id,
            status: $this->mapStatus($paymentIntent->status),
            // ...
        );
    }

    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse
    {
        $paymentIntent = $this->stripeClient->paymentIntents->capture(
            $request->getAuthorizationId(),
            $request->getAmount() ? ['amount_to_capture' => $this->convertToCents($request->getAmount())] : []
        );

        return new CaptureResponse(
            captureId: $paymentIntent->id,
            status: 'captured',
            amount: $this->convertFromCents($paymentIntent->amount_received),
            currency: $paymentIntent->currency,
            capturedAt: new \DateTimeImmutable()
        );
    }

    public function refundPayment(RefundPaymentRequest $request): RefundResponse
    {
        $refund = $this->stripeClient->refunds->create([
            'payment_intent' => $request->getPaymentId(),
            'amount' => $request->getAmount() ? $this->convertToCents($request->getAmount()) : null,
            'reason' => $request->getReason(),
        ]);

        return new RefundResponse(
            refundId: $refund->id,
            status: $refund->status,
            amount: $this->convertFromCents($refund->amount),
            currency: $refund->currency,
            reason: $refund->reason
        );
    }

    private function convertToCents(string $amount): int
    {
        return (int) bcmul($amount, '100', 0);
    }

    private function convertFromCents(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }
}
```

---

## StripeAdapterInterface Extension

Stripe-specific operations that go beyond the base interface:

**Location:** `stripe/src/Stripe/Adapter/StripeAdapterInterface.php`

```php
interface StripeAdapterInterface extends PaymentAdapterInterface
{
    // Checkout Sessions
    public function createCheckoutSession(array $params): \Stripe\Checkout\Session;
    public function retrieveCheckoutSession(string $sessionId): \Stripe\Checkout\Session;

    // PaymentIntent operations
    public function retrievePaymentIntent(string $paymentIntentId): \Stripe\PaymentIntent;
    public function cancelPaymentIntent(string $paymentIntentId): \Stripe\PaymentIntent;

    // Charge operations
    public function retrieveCharge(string $chargeId): \Stripe\Charge;
    public function createRefundByCharge(string $chargeId, ?int $amount = null): \Stripe\Refund;

    // Fraud check
    public function getPaymentIntentRiskScore(string $paymentIntentId): ?int;

    // Connection test
    public function testConnection(): bool;
}
```

---

## Adapter Factory

Creates adapters based on provider configuration:

```php
// payment-component
interface PaymentAdapterFactoryInterface
{
    public function createAdapter(string $provider): PaymentAdapterInterface;
    public function createDefaultAdapter(): PaymentAdapterInterface;
    public function isProviderSupported(string $provider): bool;
    public function getSupportedProviders(): array;
}

// stripe
class StripeAdapterFactory extends PaymentAdapterFactory
{
    public function __construct(
        private StripeClientFactory $clientFactory,
        private ModuleConfigurationService $config,
        private LoggerInterface $logger
    ) {}

    public function createAdapter(string $provider): PaymentAdapterInterface
    {
        if ($provider !== 'stripe') {
            throw new \InvalidArgumentException("Unsupported provider: $provider");
        }

        return new StripeAdapter(
            $this->clientFactory->create(),
            $this->config,
            $this->logger
        );
    }
}
```

---

## Lazy Loading Pattern

The `LazyStripeAdapter` defers adapter creation until first use:

```php
class LazyStripeAdapter implements StripeAdapterInterface
{
    private ?StripeAdapter $adapter = null;

    public function __construct(
        private StripeAdapterFactory $factory
    ) {}

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        return $this->getAdapter()->createPayment($request);
    }

    private function getAdapter(): StripeAdapter
    {
        if ($this->adapter === null) {
            $this->adapter = $this->factory->createAdapter('stripe');
        }
        return $this->adapter;
    }
}
```

---

## Shop Adapters

Additional adapters for shop-specific operations:

### ShopAdapterInterface

```php
interface ShopAdapterInterface
{
    public function translate(string $key, array $params = []): string;
    public function getConfig(string $key): mixed;
    public function getLanguageId(): string;
    public function getCurrencyCode(): string;
    public function getShopUrl(): string;
    public function getShopId(): int;
}
```

### SessionAdapterInterface

```php
interface SessionAdapterInterface
{
    public function getVariable(string $key): mixed;
    public function setVariable(string $key, mixed $value): void;
    public function deleteVariable(string $key): void;
    public function getBasket(): BasketInterface;
    public function getUserId(): ?string;
}
```

### OXID Implementations

```php
// stripe/src/Stripe/Adapter/OxidShopAdapter.php
class OxidShopAdapter implements ShopAdapterInterface
{
    public function translate(string $key, array $params = []): string
    {
        return Registry::getLang()->translateString($key, null, false);
    }

    public function getConfig(string $key): mixed
    {
        return Registry::getConfig()->getConfigParam($key);
    }
}

// stripe/src/Stripe/Adapter/OxidSessionAdapter.php
class OxidSessionAdapter implements SessionAdapterInterface
{
    public function getVariable(string $key): mixed
    {
        return Registry::getSession()->getVariable($key);
    }

    public function getBasket(): BasketInterface
    {
        return Registry::getSession()->getBasket();
    }
}
```

---

## Adding a New Provider

To add a new payment provider (e.g., PayPal):

1. **Create Adapter:**
```php
class PayPalAdapter implements PaymentAdapterInterface
{
    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        // PayPal SDK calls
    }
}
```

2. **Create Factory:**
```php
class PayPalAdapterFactory extends PaymentAdapterFactory
{
    public function createAdapter(string $provider): PaymentAdapterInterface
    {
        return new PayPalAdapter(/* ... */);
    }
}
```

3. **Create Event Handlers:**
```php
class PayPalContractCreationHandler extends ContractCreationHandler
{
    protected function afterContractCreated(...): void
    {
        // PayPal-specific logic
    }
}
```

4. **Register in services.yaml:**
```yaml
PayPal\Adapter\PayPalAdapter:
    arguments:
        - '@paypal.client'

PayPal\Handler\PayPalContractCreationHandler:
    tags:
        - { name: 'payment.event_handler', priority: 100 }
```

The core `payment-component` code requires **zero modifications**.
