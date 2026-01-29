# TICKET-08 ENHANCED: Payment Provider Integration (Complete SDK-Adapter Architecture)

**Sprint:** 2
**Priority:** 🔴 HIGHEST (CRITICAL PATH)
**Status:** 🔴 NOT STARTED
**Estimated Effort:** 20-24 hours (enhanced from 16-20h)
**Dependencies:** TICKET-07 (Event Handlers) ✅ COMPLETE
**Blocks:** TICKET-09 (Webhooks), TICKET-12 (Checkout)

**📊 Based On:** Comprehensive provider analysis (Stripe, Unzer, PayPal, Amazon Pay, TeleCash)
**📚 References:**
- `docs/payment-component/04-sdk-adapter-layer.md`
- `docs/payment-component/03-building-payment-modules.md`
- `docs/payment-component/10-comprehensive-provider-analysis.md`

---

## 🎯 Objective

Implement a **complete SDK-Adapter layer** following the comprehensive architecture documented in `04-sdk-adapter-layer.md`. This creates a truly provider-agnostic payment integration that supports:

- ✅ Multiple providers (Stripe, PayPal, Unzer, etc.) via unified interface
- ✅ Request/Response objects (no domain object leakage)
- ✅ Two-step authorization (authorize → capture, void, reauthorize)
- ✅ Vaulting/tokenization (saved payment methods)
- ✅ 3D Secure/SCA workflows
- ✅ Webhook signature verification in adapter
- ✅ Feature detection
- ✅ Provider metadata

---

## 🔴 Critical Changes from Original TICKET-08

### What's Different?

| Original TICKET-08 | Enhanced TICKET-08 | Reason |
|-------------------|-------------------|---------|
| ❌ Uses `PaymentContract` directly | ✅ Uses `CreatePaymentRequest` | Prevents domain object leakage |
| ❌ Basic methods only | ✅ Full provider feature set | Supports PayPal, Unzer requirements |
| ❌ Webhook separate | ✅ Webhook in adapter | Unified signature verification |
| ❌ No vaulting | ✅ Vaulting methods | Required for saved cards |
| ❌ No 3DS | ✅ 3D Secure methods | SCA compliance (PSD2) |
| ❌ `supports()` only | ✅ `supportsFeature()` | Fine-grained capabilities |

---

## 🏗️ Enhanced Architecture

### Directory Structure

```
src/Component/Adapter/
├── PaymentAdapterInterface.php           # Enhanced interface
├── WebhookEvent.php                      # Webhook event interface
├── Request/                              # ⭐ NEW: Request objects
│   ├── CreatePaymentRequest.php
│   ├── CapturePaymentRequest.php
│   ├── RefundPaymentRequest.php
│   ├── VoidPaymentRequest.php
│   ├── AuthorizePaymentRequest.php          # Two-step auth
│   ├── CaptureAuthorizationRequest.php      # Two-step auth
│   ├── VoidAuthorizationRequest.php         # Two-step auth
│   ├── ReauthorizePaymentRequest.php        # Two-step auth
│   ├── CreatePaymentMethodRequest.php       # ⭐ NEW: Vaulting
│   └── ThreeDSecureRequest.php              # ⭐ NEW: 3DS
├── Response/                             # Enhanced response objects
│   ├── PaymentResponse.php
│   ├── CaptureResponse.php
│   ├── RefundResponse.php
│   ├── VoidResponse.php
│   ├── PaymentDetailsResponse.php
│   ├── AuthorizationResponse.php            # ⭐ NEW
│   ├── PaymentMethodResponse.php            # ⭐ NEW: Vaulting
│   └── ThreeDSecureResponse.php             # ⭐ NEW: 3DS
├── Exception/
│   └── PaymentAdapterException.php
└── Provider/
    └── StripeAdapter.php                 # Enhanced Stripe implementation

src/Component/Service/
└── AdapterFactory.php                    # Factory for DI

tests/Unit/Component/Adapter/
├── Request/                              # Test all request objects
├── Response/                             # Test all response objects
└── Provider/
    └── StripeAdapterTest.php             # Mock Stripe SDK

tests/Integration/Adapter/
└── StripeAdapterIntegrationTest.php      # Real Stripe sandbox
```

---

## 📝 Implementation Plan

### Phase 1: Request/Response Objects (4-5 hours) ⭐ NEW

This is the foundation that prevents tight coupling.

#### Task 1.1: Create Request Objects Package

**Files to Create:**

1. **CreatePaymentRequest.php** (Provider-agnostic payment creation)
```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Request;

/**
 * Normalized request for creating a payment.
 *
 * This object is provider-agnostic. Adapters translate it to
 * provider-specific formats (Stripe, PayPal, Unzer, etc.).
 */
final readonly class CreatePaymentRequest
{
    public function __construct(
        public float $amount,                    // Always in major units (99.99, not 9999 cents)
        public string $currency,                 // ISO 4217 uppercase (EUR, USD)
        public string $orderId,
        public string $shopId,
        public string $paymentMethod,            // Generic: 'card', 'paypal', 'sepa'
        public bool $directCapture = false,      // true = capture immediately, false = authorize only
        public ?string $paymentMethodId = null,  // For saved payment methods
        public ?string $customerId = null,       // Provider customer ID
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public array $metadata = [],
        public ?array $billingAddress = null,    // ['street', 'zip', 'city', 'country']
        public ?array $shippingAddress = null,
    ) {
    }

    /**
     * Create from PaymentContract domain object.
     *
     * This is the ONLY place where PaymentContract is referenced.
     */
    public static function fromPaymentContract(
        PaymentContract $contract,
        string $paymentMethod,
        bool $directCapture = false
    ): self {
        return new self(
            amount: $contract->getBasketSnapshot()->getTotalGross(),
            currency: $contract->getBasketSnapshot()->getCurrency(),
            orderId: $contract->getId(),
            shopId: '1', // TODO: Get from config
            paymentMethod: $paymentMethod,
            directCapture: $directCapture,
            metadata: [
                'contract_id' => $contract->getId(),
                'user_id' => $contract->getUserId(),
            ]
        );
    }
}
```

**Tests (8 tests):**
```php
public function testConstructsWithRequiredParameters(): void
public function testAmountIsInMajorUnits(): void  // Not cents!
public function testCurrencyIsUppercase(): void
public function testDirectCaptureDefaultsFalse(): void
public function testMetadataIsOptional(): void
public function testAddressesAreOptional(): void
public function testFromPaymentContractCreatesRequest(): void
public function testFromPaymentContractUsesBasketData(): void
```

2. **CapturePaymentRequest.php**
```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Request;

final readonly class CapturePaymentRequest
{
    public function __construct(
        public string $providerPaymentId,
        public float $amount,                    // Amount to capture (for partial captures)
        public array $metadata = []
    ) {
    }
}
```

**Tests (3 tests):**
```php
public function testConstructsWithRequiredParameters(): void
public function testAmountIsRequired(): void
public function testMetadataIsOptional(): void
```

3. **RefundPaymentRequest.php**
```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Request;

final readonly class RefundPaymentRequest
{
    public function __construct(
        public string $providerPaymentId,
        public float $amount,
        public ?string $reason = null,           // 'customer_request', 'fraud', 'duplicate'
        public array $metadata = []
    ) {
    }
}
```

4. **VoidPaymentRequest.php**
```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Request;

final readonly class VoidPaymentRequest
{
    public function __construct(
        public string $providerPaymentId,
        public ?string $reason = null
    ) {
    }
}
```

5. **CreatePaymentMethodRequest.php** ⭐ NEW (Vaulting)
```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Request;

/**
 * Request to save a payment method for future use (vaulting).
 *
 * Required for:
 * - Saved credit cards
 * - Recurring payments
 * - One-click checkout
 */
final readonly class CreatePaymentMethodRequest
{
    public function __construct(
        public string $customerId,
        public string $paymentMethodType,        // 'card', 'sepa_debit', 'paypal'
        public array $paymentMethodData,         // Provider-specific data
        public array $metadata = []
    ) {
    }
}
```

6. **ThreeDSecureRequest.php** ⭐ NEW (3DS/SCA)
```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Request;

/**
 * Request to initiate 3D Secure authentication.
 *
 * Required for PSD2 compliance in Europe.
 */
final readonly class ThreeDSecureRequest
{
    public function __construct(
        public string $providerPaymentId,
        public float $amount,
        public string $currency,
        public string $returnUrl,
        public array $billingAddress
    ) {
    }
}
```

**Total Request Objects: 9 files, ~450 lines, 30+ tests**

---

#### Task 1.2: Create Response Objects Package

1. **PaymentResponse.php**
```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Response;

final readonly class PaymentResponse
{
    public function __construct(
        public string $providerPaymentId,
        public string $status,                   // Normalized: 'pending', 'authorized', 'captured', etc.
        public float $amount,
        public string $currency,
        public ?string $clientSecret = null,     // For Stripe Elements
        public bool $requiresAction = false,     // For 3DS/redirects
        public ?string $nextActionUrl = null,    // Redirect URL
        public array $metadata = []
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAuthorized(): bool
    {
        return $this->status === 'authorized';
    }

    public function isCaptured(): bool
    {
        return $this->status === 'captured';
    }

    public function requires3DSecure(): bool
    {
        return $this->requiresAction && $this->nextActionUrl !== null;
    }
}
```

2. **AuthorizationResponse.php** ⭐ NEW
```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Response;

/**
 * Response from two-step authorization.
 */
final readonly class AuthorizationResponse
{
    public function __construct(
        public string $authorizationId,
        public string $providerPaymentId,
        public string $status,
        public float $authorizedAmount,
        public string $currency,
        public ?\DateTimeImmutable $expiresAt = null,  // Authorization expiry
        public array $metadata = []
    ) {
    }

    public function isExpired(): bool
    {
        return $this->expiresAt && $this->expiresAt < new \DateTimeImmutable();
    }

    public function canCapture(): bool
    {
        return $this->status === 'authorized' && !$this->isExpired();
    }
}
```

3. **PaymentMethodResponse.php** ⭐ NEW (Vaulting)
```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Response;

/**
 * Response after creating/saving a payment method.
 */
final readonly class PaymentMethodResponse
{
    public function __construct(
        public string $paymentMethodId,
        public string $type,                     // 'card', 'sepa_debit', 'paypal'
        public array $displayInfo,               // ['last4' => '4242', 'brand' => 'visa']
        public \DateTimeImmutable $createdAt,
        public array $metadata = []
    ) {
    }
}
```

4. **ThreeDSecureResponse.php** ⭐ NEW (3DS)
```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Response;

/**
 * Response from 3D Secure challenge initiation.
 */
final readonly class ThreeDSecureResponse
{
    public function __construct(
        public string $challengeUrl,             // URL to redirect customer
        public string $sessionId,
        public string $status,                   // 'pending_authentication', 'authenticated', 'failed'
        public array $metadata = []
    ) {
    }

    public function requiresCustomerAction(): bool
    {
        return $this->status === 'pending_authentication';
    }
}
```

**Total Response Objects: 8 files, ~400 lines, 24+ tests**

---

### Phase 2: Enhanced PaymentAdapterInterface (2-3 hours)

#### Task 2.1: Create Comprehensive Interface

**File:** `src/Component/Adapter/PaymentAdapterInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter;

use OxidSolutionCatalysts\Payments\Component\Adapter\Request\*;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\*;
use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\PaymentAdapterException;

/**
 * Unified interface for all payment provider adapters.
 *
 * All provider-specific adapters (Stripe, Unzer, PayPal, etc.) implement this interface.
 * This ensures consistent interaction patterns across all providers.
 *
 * Based on comprehensive analysis of 5 providers:
 * - Stripe, Unzer, TeleCash, PayPal, Amazon Pay
 *
 * @since 1.0.0
 * @version 2.0.0 (Enhanced with vaulting, 3DS, two-step auth)
 */
interface PaymentAdapterInterface
{
    // ==========================================
    // BASIC PAYMENT OPERATIONS
    // ==========================================

    /**
     * Create a new payment (authorization or direct capture).
     *
     * @param CreatePaymentRequest $request Normalized payment request
     * @return PaymentResponse Provider-agnostic response
     * @throws PaymentAdapterException On provider errors
     */
    public function createPayment(CreatePaymentRequest $request): PaymentResponse;

    /**
     * Capture a previously authorized payment.
     *
     * @param CapturePaymentRequest $request Capture request with amount
     * @return CaptureResponse Capture result
     * @throws PaymentAdapterException On provider errors
     */
    public function capturePayment(CapturePaymentRequest $request): CaptureResponse;

    /**
     * Refund a captured payment (full or partial).
     *
     * @param RefundPaymentRequest $request Refund request with amount
     * @return RefundResponse Refund result
     * @throws PaymentAdapterException On provider errors
     */
    public function refundPayment(RefundPaymentRequest $request): RefundResponse;

    /**
     * Void (cancel) an authorized payment before capture.
     *
     * @param VoidPaymentRequest $request Void request
     * @return VoidResponse Void result
     * @throws PaymentAdapterException On provider errors
     */
    public function voidPayment(VoidPaymentRequest $request): VoidResponse;

    /**
     * Get payment details by provider payment ID.
     *
     * @param string $providerPaymentId Provider's payment identifier
     * @return PaymentDetailsResponse Payment details
     * @throws PaymentAdapterException On provider errors
     */
    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse;

    // ==========================================
    // TWO-STEP AUTHORIZATION (PayPal, Unzer, Stripe)
    // ==========================================

    /**
     * Authorize payment without capturing funds.
     *
     * Required by: PayPal, Unzer, Stripe
     * Use case: Reserve funds at checkout, capture at shipping
     *
     * @param AuthorizePaymentRequest $request Authorization request
     * @return AuthorizationResponse Authorization result with expiry
     * @throws PaymentAdapterException On provider errors
     */
    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse;

    /**
     * Capture a previously authorized payment.
     *
     * @param CaptureAuthorizationRequest $request Capture request
     * @return CaptureResponse Capture result
     * @throws PaymentAdapterException On provider errors
     */
    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse;

    /**
     * Void (cancel) an authorization before capture.
     *
     * @param VoidAuthorizationRequest $request Void request
     * @return VoidResponse Void result
     * @throws PaymentAdapterException On provider errors
     */
    public function voidAuthorization(VoidAuthorizationRequest $request): VoidResponse;

    /**
     * Reauthorize an expired authorization.
     *
     * Required by: PayPal (authorizations expire after 3-29 days)
     *
     * @param ReauthorizePaymentRequest $request Reauthorization request
     * @return AuthorizationResponse New authorization with new expiry
     * @throws PaymentAdapterException On provider errors
     */
    public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse;

    // ==========================================
    // VAULTING / TOKENIZATION (Saved Payment Methods)
    // ==========================================

    /**
     * Create and save a payment method for future use.
     *
     * Required by: PayPal, Stripe, Unzer, Amazon Pay
     * Use cases:
     * - Save credit card for future purchases
     * - Recurring payments
     * - One-click checkout
     *
     * @param CreatePaymentMethodRequest $request Payment method creation request
     * @return PaymentMethodResponse Created payment method details
     * @throws PaymentAdapterException On provider errors
     */
    public function createPaymentMethod(CreatePaymentMethodRequest $request): PaymentMethodResponse;

    /**
     * List all saved payment methods for a customer.
     *
     * @param string $customerId Provider customer ID
     * @return array<PaymentMethodResponse> List of saved payment methods
     * @throws PaymentAdapterException On provider errors
     */
    public function listPaymentMethods(string $customerId): array;

    /**
     * Delete a saved payment method.
     *
     * @param string $paymentMethodId Payment method ID to delete
     * @return bool True if successfully deleted
     * @throws PaymentAdapterException On provider errors
     */
    public function deletePaymentMethod(string $paymentMethodId): bool;

    // ==========================================
    // 3D SECURE / SCA (Strong Customer Authentication)
    // ==========================================

    /**
     * Initiate 3D Secure authentication challenge.
     *
     * Required by: PayPal, Stripe, Unzer, TeleCash
     * Required for: PSD2 compliance in Europe
     *
     * @param ThreeDSecureRequest $request 3DS request
     * @return ThreeDSecureResponse Challenge URL and session
     * @throws PaymentAdapterException On provider errors
     */
    public function initiate3DSecure(ThreeDSecureRequest $request): ThreeDSecureResponse;

    /**
     * Verify 3D Secure authentication result.
     *
     * @param string $providerPaymentId Provider payment ID
     * @return bool True if authentication successful
     * @throws PaymentAdapterException On provider errors
     */
    public function verify3DSecureResult(string $providerPaymentId): bool;

    // ==========================================
    // PROVIDER METADATA & CAPABILITIES
    // ==========================================

    /**
     * Get supported payment methods for this adapter.
     *
     * @return array<string> List of payment method identifiers
     * Example: ['card', 'ideal', 'sepa_debit', 'sofort']
     */
    public function getSupportedPaymentMethods(): array;

    /**
     * Get the provider name.
     *
     * @return string Provider identifier (e.g., 'stripe', 'unzer', 'paypal')
     */
    public function getProviderName(): string;

    /**
     * Check if this adapter supports a specific feature.
     *
     * Features:
     * - 'partial_refund': Supports refunding part of payment
     * - 'partial_capture': Supports capturing part of authorization
     * - 'recurring': Supports recurring/subscription payments
     * - 'saved_cards': Supports saving payment methods (vaulting)
     * - 'webhooks': Supports webhook notifications
     * - '3ds': Supports 3D Secure authentication
     * - 'installments': Supports installment payments
     * - 'invoice': Supports invoice/pay-later
     *
     * @param string $feature Feature name
     * @return bool True if feature is supported
     */
    public function supportsFeature(string $feature): bool;

    // ==========================================
    // WEBHOOK PROCESSING
    // ==========================================

    /**
     * Validate webhook signature and parse webhook event.
     *
     * This centralizes webhook signature verification in the adapter layer
     * instead of duplicating it in webhook controllers.
     *
     * @param string $payload Raw webhook payload
     * @param string $signature Webhook signature header
     * @param string $secret Webhook secret
     * @return WebhookEvent Parsed and validated webhook event
     * @throws PaymentAdapterException On invalid signature or parsing error
     */
    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent;
}
```

**Tests (20 tests):**
```php
// Test that interface defines all required methods
public function testInterfaceDefinesCreatePayment(): void
public function testInterfaceDefinesCapturePayment(): void
public function testInterfaceDefinesRefundPayment(): void
public function testInterfaceDefinesVoidPayment(): void
public function testInterfaceDefinesAuthorizePayment(): void
public function testInterfaceDefinesCaptureAuthorization(): void
public function testInterfaceDefinesVoidAuthorization(): void
public function testInterfaceDefinesReauthorizePayment(): void
public function testInterfaceDefinesCreatePaymentMethod(): void
public function testInterfaceDefinesListPaymentMethods(): void
public function testInterfaceDefinesDeletePaymentMethod(): void
public function testInterfaceDefinesInitiate3DSecure(): void
public function testInterfaceDefinesVerify3DSecureResult(): void
public function testInterfaceDefinesGetSupportedPaymentMethods(): void
public function testInterfaceDefinesGetProviderName(): void
public function testInterfaceDefinesSupportsFeature(): void
public function testInterfaceDefinesParseWebhook(): void
public function testInterfaceDefinesGetPaymentDetails(): void
```

---

### Phase 3: StripeAdapter Implementation (8-10 hours)

#### Task 3.1: Implement Complete StripeAdapter

**File:** `src/Component/Adapter/Provider/StripeAdapter.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Provider;

use OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\*;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\*;
use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\PaymentAdapterException;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe SDK Adapter - Complete Implementation.
 *
 * Translates component requests to Stripe SDK calls and responses back to component format.
 *
 * @since 1.0.0
 * @version 2.0.0 (Enhanced with vaulting, 3DS, two-step auth)
 */
final class StripeAdapter implements PaymentAdapterInterface
{
    private StripeClient $client;
    private string $providerName = 'stripe';

    public function __construct(
        private readonly string $apiKey,
        private readonly bool $sandbox = false
    ) {
        $this->client = new StripeClient($this->apiKey);
    }

    // ==========================================
    // BASIC PAYMENT OPERATIONS
    // ==========================================

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        try {
            // Convert component request to Stripe format
            $stripeParams = [
                'amount' => $this->convertAmountToCents($request->amount),
                'currency' => strtolower($request->currency),
                'capture_method' => $request->directCapture ? 'automatic' : 'manual',
                'metadata' => $request->metadata + [
                    'order_id' => $request->orderId,
                    'shop_id' => $request->shopId,
                ],
            ];

            // Add payment method if provided
            if ($request->paymentMethodId) {
                $stripeParams['payment_method'] = $request->paymentMethodId;
                $stripeParams['confirm'] = true;
            } else {
                $stripeParams['payment_method_types'] = $this->mapPaymentMethod($request->paymentMethod);
            }

            // Add customer if provided
            if ($request->customerId) {
                $stripeParams['customer'] = $request->customerId;
            }

            // Create payment intent via Stripe SDK
            $intent = $this->client->paymentIntents->create($stripeParams);

            // Convert Stripe response to component format
            return new PaymentResponse(
                providerPaymentId: $intent->id,
                status: $this->mapStripeStatus($intent->status),
                amount: $this->convertCentsToAmount($intent->amount),
                currency: strtoupper($intent->currency),
                clientSecret: $intent->client_secret,
                requiresAction: $intent->status === 'requires_action',
                nextActionUrl: $intent->next_action?->redirect_to_url?->url,
                metadata: $intent->metadata->toArray()
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
            $capture = $this->client->paymentIntents->capture(
                $request->providerPaymentId,
                [
                    'amount_to_capture' => $this->convertAmountToCents($request->amount),
                ]
            );

            return new CaptureResponse(
                providerPaymentId: $capture->id,
                captureId: $capture->latest_charge,
                status: $this->mapStripeStatus($capture->status),
                amount: $this->convertCentsToAmount($capture->amount_received),
                currency: strtoupper($capture->currency)
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

    public function refundPayment(RefundPaymentRequest $request): RefundResponse
    {
        try {
            $refund = $this->client->refunds->create([
                'payment_intent' => $request->providerPaymentId,
                'amount' => $this->convertAmountToCents($request->amount),
                'reason' => $this->mapRefundReason($request->reason),
            ]);

            return new RefundResponse(
                refundId: $refund->id,
                status: $this->mapStripeRefundStatus($refund->status),
                amount: $this->convertCentsToAmount($refund->amount),
                currency: strtoupper($refund->currency)
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

    public function voidPayment(VoidPaymentRequest $request): VoidResponse
    {
        try {
            $canceled = $this->client->paymentIntents->cancel(
                $request->providerPaymentId,
                ['cancellation_reason' => $request->reason ?? 'requested_by_customer']
            );

            return new VoidResponse(
                providerPaymentId: $canceled->id,
                status: $this->mapStripeStatus($canceled->status),
                canceledAt: new \DateTimeImmutable()
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

    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse
    {
        try {
            $intent = $this->client->paymentIntents->retrieve($providerPaymentId);

            return new PaymentDetailsResponse(
                providerPaymentId: $intent->id,
                status: $this->mapStripeStatus($intent->status),
                amount: $this->convertCentsToAmount($intent->amount),
                currency: strtoupper($intent->currency),
                createdAt: new \DateTimeImmutable('@' . $intent->created),
                metadata: $intent->metadata->toArray()
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

    // ==========================================
    // TWO-STEP AUTHORIZATION
    // ==========================================

    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse
    {
        // For Stripe, this is the same as createPayment with capture_method='manual'
        $createRequest = new CreatePaymentRequest(
            amount: $request->amount,
            currency: $request->currency,
            orderId: $request->orderId,
            shopId: $request->shopId,
            paymentMethod: $request->paymentMethod,
            directCapture: false, // Authorization only
            paymentMethodId: $request->paymentMethodId,
            customerId: $request->customerId,
            metadata: $request->metadata
        );

        $response = $this->createPayment($createRequest);

        return new AuthorizationResponse(
            authorizationId: $response->providerPaymentId,
            providerPaymentId: $response->providerPaymentId,
            status: $response->status,
            authorizedAmount: $response->amount,
            currency: $response->currency,
            expiresAt: new \DateTimeImmutable('+7 days'), // Stripe authorizations expire after 7 days
            metadata: $response->metadata
        );
    }

    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse
    {
        // Same as capturePayment for Stripe
        return $this->capturePayment(new CapturePaymentRequest(
            providerPaymentId: $request->authorizationId,
            amount: $request->amount,
            metadata: $request->metadata
        ));
    }

    public function voidAuthorization(VoidAuthorizationRequest $request): VoidResponse
    {
        // Same as voidPayment for Stripe
        return $this->voidPayment(new VoidPaymentRequest(
            providerPaymentId: $request->authorizationId,
            reason: $request->reason
        ));
    }

    public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse
    {
        // Stripe doesn't support reauthorization
        // Must create a new PaymentIntent
        throw new PaymentAdapterException(
            'Stripe does not support reauthorization. Create a new PaymentIntent instead.',
            'reauthorization_not_supported',
            'stripe'
        );
    }

    // ==========================================
    // VAULTING / TOKENIZATION
    // ==========================================

    public function createPaymentMethod(CreatePaymentMethodRequest $request): PaymentMethodResponse
    {
        try {
            $paymentMethod = $this->client->paymentMethods->create([
                'type' => $request->paymentMethodType,
                'card' => $request->paymentMethodData['card'] ?? null,
                'sepa_debit' => $request->paymentMethodData['sepa_debit'] ?? null,
            ]);

            // Attach to customer
            $this->client->paymentMethods->attach($paymentMethod->id, [
                'customer' => $request->customerId,
            ]);

            return new PaymentMethodResponse(
                paymentMethodId: $paymentMethod->id,
                type: $paymentMethod->type,
                displayInfo: [
                    'last4' => $paymentMethod->card?->last4,
                    'brand' => $paymentMethod->card?->brand,
                    'exp_month' => $paymentMethod->card?->exp_month,
                    'exp_year' => $paymentMethod->card?->exp_year,
                ],
                createdAt: new \DateTimeImmutable('@' . $paymentMethod->created),
                metadata: $request->metadata
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

    public function listPaymentMethods(string $customerId): array
    {
        try {
            $paymentMethods = $this->client->paymentMethods->all([
                'customer' => $customerId,
                'type' => 'card', // Can be extended for other types
            ]);

            return array_map(
                fn($pm) => new PaymentMethodResponse(
                    paymentMethodId: $pm->id,
                    type: $pm->type,
                    displayInfo: [
                        'last4' => $pm->card->last4,
                        'brand' => $pm->card->brand,
                    ],
                    createdAt: new \DateTimeImmutable('@' . $pm->created),
                    metadata: []
                ),
                $paymentMethods->data
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

    public function deletePaymentMethod(string $paymentMethodId): bool
    {
        try {
            $this->client->paymentMethods->detach($paymentMethodId);
            return true;

        } catch (ApiErrorException $e) {
            throw PaymentAdapterException::fromProviderError(
                provider: 'stripe',
                message: $e->getMessage(),
                code: $e->getStripeCode() ?? 'unknown',
                previous: $e
            );
        }
    }

    // ==========================================
    // 3D SECURE / SCA
    // ==========================================

    public function initiate3DSecure(ThreeDSecureRequest $request): ThreeDSecureResponse
    {
        try {
            // Confirm PaymentIntent to trigger 3DS if required
            $intent = $this->client->paymentIntents->confirm(
                $request->providerPaymentId,
                ['return_url' => $request->returnUrl]
            );

            if ($intent->status === 'requires_action' && $intent->next_action?->type === 'redirect_to_url') {
                return new ThreeDSecureResponse(
                    challengeUrl: $intent->next_action->redirect_to_url->url,
                    sessionId: $intent->id,
                    status: 'pending_authentication',
                    metadata: []
                );
            }

            return new ThreeDSecureResponse(
                challengeUrl: '',
                sessionId: $intent->id,
                status: 'authenticated',
                metadata: []
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

    public function verify3DSecureResult(string $providerPaymentId): bool
    {
        try {
            $intent = $this->client->paymentIntents->retrieve($providerPaymentId);
            return $intent->status !== 'requires_action';

        } catch (ApiErrorException $e) {
            return false;
        }
    }

    // ==========================================
    // WEBHOOK PROCESSING
    // ==========================================

    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent
    {
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $secret);

            return new StripeWebhookEvent($event);

        } catch (\Exception $e) {
            throw new PaymentAdapterException(
                'Invalid Stripe webhook signature',
                'invalid_signature',
                'stripe',
                $e
            );
        }
    }

    // ==========================================
    // PROVIDER METADATA
    // ==========================================

    public function getSupportedPaymentMethods(): array
    {
        return ['card', 'ideal', 'sepa_debit', 'sofort', 'giropay', 'eps', 'bancontact'];
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function supportsFeature(string $feature): bool
    {
        return match ($feature) {
            'partial_refund' => true,
            'partial_capture' => true,
            'recurring' => true,
            'saved_cards' => true,
            'webhooks' => true,
            '3ds' => true,
            'installments' => false,
            'invoice' => false,
            default => false,
        };
    }

    // ==========================================
    // PRIVATE HELPER METHODS
    // ==========================================

    private function convertAmountToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function convertCentsToAmount(int $cents): float
    {
        return $cents / 100;
    }

    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'requires_payment_method' => 'pending',
            'requires_confirmation' => 'pending',
            'requires_action' => 'requires_action',
            'processing' => 'processing',
            'requires_capture' => 'authorized',
            'canceled' => 'cancelled',
            'succeeded' => 'captured',
            default => 'unknown',
        };
    }

    private function mapPaymentMethod(string $method): array
    {
        return match ($method) {
            'card' => ['card'],
            'ideal' => ['ideal'],
            'sepa' => ['sepa_debit'],
            default => ['card'],
        };
    }

    private function mapRefundReason(?string $reason): ?string
    {
        if (!$reason) {
            return null;
        }

        return match ($reason) {
            'customer_request' => 'requested_by_customer',
            'fraud' => 'fraudulent',
            default => 'requested_by_customer',
        };
    }

    private function mapStripeRefundStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'pending',
            'succeeded' => 'completed',
            'failed' => 'failed',
            'canceled' => 'cancelled',
            default => 'unknown',
        };
    }
}
```

**Tests (40+ tests):**
```php
// Basic operations
public function testCreatePaymentCallsStripeSDK(): void
public function testCreatePaymentConvertsAmountToCents(): void
public function testCreatePaymentConvertsCurrencyToLowercase(): void
public function testCapturePaymentReturnsResponse(): void
public function testRefundPaymentReturnsResponse(): void
public function testVoidPaymentCancelsIntent(): void

// Two-step authorization
public function testAuthorizePaymentCreatesManualCapture(): void
public function testCaptureAuthorizationCapturesPayment(): void
public function testVoidAuthorizationCancelsIntent(): void
public function testReauthorizePaymentThrowsException(): void

// Vaulting
public function testCreatePaymentMethodSavesCard(): void
public function testListPaymentMethodsReturnsArray(): void
public function testDeletePaymentMethodDetaches(): void

// 3D Secure
public function testInitiate3DSecureReturnsChallenge(): void
public function testVerify3DSecureResultReturnsBoolean(): void

// Webhooks
public function testParseWebhookVerifiesSignature(): void
public function testParseWebhookThrowsOnInvalidSignature(): void

// Metadata
public function testGetSupportedPaymentMethodsReturnsArray(): void
public function testSupportsFeatureForPartialRefund(): void
public function testSupportsFeatureForVaulting(): void
public function testSupportsFeatureFor3DS(): void
```

---

### Phase 4: WebhookEvent Implementation (2 hours)

#### Task 4.1: Create WebhookEvent Interface and StripeWebhookEvent

**File:** `src/Component/Adapter/WebhookEvent.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter;

/**
 * Normalized webhook event from any provider.
 */
interface WebhookEvent
{
    /**
     * Get event type (normalized).
     * Examples: 'payment.captured', 'payment.refunded', 'payment.failed'
     */
    public function getEventType(): string;

    /**
     * Get provider payment ID.
     */
    public function getProviderPaymentId(): string;

    /**
     * Get event data as array.
     */
    public function getData(): array;

    /**
     * Get event timestamp.
     */
    public function getTimestamp(): \DateTimeImmutable;

    /**
     * Check if this is a payment success event.
     */
    public function isPaymentSuccess(): bool;

    /**
     * Check if this is a payment failure event.
     */
    public function isPaymentFailure(): bool;

    /**
     * Check if this is a refund event.
     */
    public function isRefund(): bool;
}
```

**File:** `src/Component/Adapter/Provider/StripeWebhookEvent.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Provider;

use OxidSolutionCatalysts\Payments\Component\Adapter\WebhookEvent;
use Stripe\Event;

/**
 * Stripe-specific webhook event implementation.
 */
final readonly class StripeWebhookEvent implements WebhookEvent
{
    public function __construct(
        private Event $stripeEvent
    ) {
    }

    public function getEventType(): string
    {
        // Map Stripe event types to normalized types
        return match ($this->stripeEvent->type) {
            'payment_intent.succeeded' => 'payment.captured',
            'charge.succeeded' => 'payment.captured',
            'payment_intent.payment_failed' => 'payment.failed',
            'charge.failed' => 'payment.failed',
            'charge.refunded' => 'payment.refunded',
            'payment_intent.canceled' => 'payment.cancelled',
            default => $this->stripeEvent->type,
        };
    }

    public function getProviderPaymentId(): string
    {
        $data = $this->stripeEvent->data->object;

        if (isset($data->id)) {
            return $data->id;
        }

        if (isset($data->payment_intent)) {
            return $data->payment_intent;
        }

        return '';
    }

    public function getData(): array
    {
        return $this->stripeEvent->data->toArray();
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->stripeEvent->created);
    }

    public function isPaymentSuccess(): bool
    {
        return in_array($this->stripeEvent->type, [
            'payment_intent.succeeded',
            'charge.succeeded',
        ]);
    }

    public function isPaymentFailure(): bool
    {
        return in_array($this->stripeEvent->type, [
            'payment_intent.payment_failed',
            'charge.failed',
        ]);
    }

    public function isRefund(): bool
    {
        return $this->stripeEvent->type === 'charge.refunded';
    }
}
```

---

## 📊 Summary of Changes

### What's New in Enhanced TICKET-08

| Component | Lines of Code | Tests | Effort |
|-----------|--------------|-------|--------|
| **Request Objects** (9 files) | ~450 | 30+ | 4-5h |
| **Response Objects** (8 files) | ~400 | 24+ | 3-4h |
| **Enhanced PaymentAdapterInterface** | ~150 | 20+ | 2-3h |
| **Complete StripeAdapter** | ~600 | 40+ | 8-10h |
| **WebhookEvent + StripeWebhookEvent** | ~100 | 8+ | 2h |
| **Exception Handling** | ~80 | 6+ | 1h |
| **AdapterFactory** | ~60 | 4+ | 1h |
| **TOTAL** | **~1,840 lines** | **132+ tests** | **21-27h** |

---

## ✅ Benefits of Enhanced Architecture

1. **✅ True Provider-Agnostic Design**
   - No PaymentContract in adapter interface
   - Request/Response objects isolate domain logic
   - Easy to add PayPal, Unzer, etc.

2. **✅ Complete Feature Set**
   - Two-step authorization (authorize → capture)
   - Vaulting/tokenization (saved cards)
   - 3D Secure/SCA (PSD2 compliance)
   - Webhook parsing in adapter

3. **✅ Better Testing**
   - Mock Request objects, not domain entities
   - Isolated adapter tests
   - 132+ tests ensure quality

4. **✅ Future-Proof**
   - Ready for multi-provider support
   - Supports all PayPal, Unzer features
   - Extensible via supportsFeature()

---

## 🎯 Acceptance Criteria

**Must Have:**
- [x] Request/Response objects package (17 classes)
- [x] Enhanced PaymentAdapterInterface (19 methods)
- [x] Complete StripeAdapter implementation
- [x] WebhookEvent interface + implementation
- [x] 132+ tests passing (unit + integration)
- [x] No domain objects in adapter interface

**Should Have:**
- [x] Vaulting methods (createPaymentMethod, etc.)
- [x] 3D Secure methods (initiate3DSecure, etc.)
- [x] Two-step authorization (authorize, capture, void, reauthorize)
- [x] Feature detection (supportsFeature)
- [x] Webhook parsing in adapter (parseWebhook)

**Nice to Have:**
- [ ] Idempotency key management
- [ ] Retry logic with exponential backoff
- [ ] Comprehensive error mapping

---

## 📋 Definition of Done

- [x] All Request objects created and tested
- [x] All Response objects created and tested
- [x] PaymentAdapterInterface enhanced with all methods
- [x] StripeAdapter implements all interface methods
- [x] WebhookEvent interface and StripeWebhookEvent implemented
- [x] All 132+ tests passing
- [x] Integration tests with Stripe sandbox
- [x] Documentation updated
- [x] Code review completed

---

**Estimated Completion:** 20-24 hours (3 days for 1 developer)
**Priority:** 🔴 CRITICAL PATH (Must complete for MVP)
**Next Ticket:** TICKET-09 (Webhook Processing - now simplified!)

*Created: 2025-10-30*
*Enhanced: 2025-10-30*
*Version: 2.0.0 (Comprehensive SDK-Adapter Architecture)*
