# Event-Driven Architecture Layers

**Component Documentation - Part 1 (Refactored)**
**Version:** 3.0.0
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)
**Visual Diagram:** [puml/01-architecture-overview.puml](puml/01-architecture-overview.puml)
**Database & Models:** [02-database-and-models.md](02-database-and-models.md) - Normalized Master-Detail Pattern
**Test Organization:** [10-test-organization.md](10-test-organization.md)

---

## Overview

The payment component follows an **event-driven layered architecture** where business logic is decoupled from presentation concerns. Controllers act as thin validation and security layers that emit domain events. Event handlers orchestrate business operations.

**📊 See Visual Diagram:** [puml/01-architecture-overview.puml](puml/01-architecture-overview.puml) for complete architecture visualization.

**🧪 Test Organization:** This architecture supports clear test separation between component tests (provider-agnostic) and provider tests (provider-specific SDK integration). Component tests mock the `PaymentAdapterInterface` to test business logic without external dependencies, while provider tests verify SDK integration with real provider APIs. See [09-test-organization.md](09-test-organization.md) for complete test organization strategy.

---

## Event-Driven Layer Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                        │
│  Controllers (Frontend & CLI) - Security & Validation        │
│  ⚡ Emit Events, Don't Execute Business Logic                │
└────────────────────┬────────────────────────────────────────┘
                     │ emits events
┌────────────────────▼────────────────────────────────────────┐
│                      EVENT LAYER (NEW)                       │
│  Domain Events, Event Dispatcher, Event Context             │
│  PaymentInitiated, OrderCreated, PaymentCaptured...         │
└───────┬────────────────────────────────────────────┬────────┘
        │ triggers                          triggers │
┌───────▼────────────────────────────────────────────▼────────┐
│            EVENT HANDLERS & SUBSCRIBERS                      │
│  Business Logic, Workflow Orchestration                      │
│  Access cached request data, call services                   │
└────────────────────┬────────────────────────────────────────┘
                     │ uses
┌────────────────────▼────────────────────────────────────────┐
│                     SERVICE LAYER                            │
│  PaymentService, OrderRepository, ModuleSettings            │
│  OrderManager, Factories (Called by Event Handlers)         │
└────────────────────┬────────────────────────────────────────┘
                     │ uses
┌────────────────────▼────────────────────────────────────────┐
│                  SDK-ADAPTER LAYER (NEW)                     │
│  PaymentAdapterInterface - Unified Provider Interface        │
│  StripeAdapter, UnzerAdapter, PayPalAdapter, AdyenAdapter   │
│  Provider-Agnostic Request/Response Objects                  │
└────────────────────┬────────────────────────────────────────┘
                     │ translates to/from
┌────────────────────▼────────────────────────────────────────┐
│                      DOMAIN LAYER                            │
│  Component Models (PaymentOrderState, PaymentTransaction)   │
│  Domain Events, State Machine                                │
└────────────────────┬────────────────────────────────────────┘
                     │ persists
┌────────────────────▼────────────────────────────────────────┐
│                 DATA ACCESS LAYER                            │
│  Repositories, QueryBuilder, Cache Layer                     │
└────────────────────┬────────────────────────────────────────┘
                     │ uses
┌────────────────────▼────────────────────────────────────────┐
│                  INFRASTRUCTURE LAYER                        │
│  Database, HTTP Client, Logger, Session, Cache              │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                   EXTERNAL INTEGRATION                       │
│  Provider SDKs (Stripe SDK, Unzer SDK, PayPal SDK, etc.)    │
│  ⬆️ Called via SDK-Adapter Layer (Provider-Agnostic)          │
│  Webhook Notifications (Also emit events!)                  │
└─────────────────────────────────────────────────────────────┘
```

**Key Architectural Principle**:
- **Controllers**: Thin, only validate & emit events
- **Event Handlers**: Fat, contain all business logic
- **Services**: Reusable, called by event handlers
- **SDK-Adapters**: Isolate provider-specific code
- **Data flows through events, not direct method calls**

---

## 0. Event Layer (NEW - Primary Layer)

### Responsibilities
- Define domain events that represent business operations
- Dispatch events to registered handlers
- Carry event context (cached request data)
- Enable loose coupling between components

### Components

#### Domain Events
**Location:** `src/Event/Domain/`

| Event | Emitted By | Purpose |
|-------|-----------|---------|
| `PaymentInitiatedEvent` | Controller | User starts payment process |
| `OrderCreatedEvent` | Event Handler | Shop order created |
| `OrderCreatedAtProviderEvent` | Event Handler | Provider order created |
| `PaymentCapturedEvent` | Webhook Controller | Payment confirmed |
| `PaymentFailedEvent` | Event Handler | Payment failed |
| `OrderCompletedEvent` | Event Handler | Order finalized |
| `RefundInitiatedEvent` | Admin Controller | Refund started |

#### Event Context (NEW)
**Location:** `src/Event/EventContext.php`

```php
class EventContext {
    private Basket $basket;
    private User $user;
    private Session $session;
    private array $requestData;

    // Cached data accessible by all event handlers
    public function getBasket(): Basket;
    public function getUser(): User;
    public function getRequestParam(string $key): mixed;
}
```

**Purpose**: Cache HTTP request data once, share across all event handlers

#### Event Dispatcher
**Standard PSR-14 EventDispatcher**

---

## 1. Presentation Layer (Refactored)

### NEW Responsibilities (Event-Driven)
- **Validate & sanitize** user input
- **Enforce security**: authentication, authorization, CSRF
- **Cache request data** (basket, user, session)
- **Emit domain events** with validated data
- **Return responses** based on event outcomes
- **NO business logic** - just thin coordination

### Components

#### Controllers (Event Emitters)
**Location:** `src/Controller/`

| Controller | Purpose | Reusability |
|------------|---------|-------------|
| `PaymentController` | Validate payment selection, emit event | 90% |
| `OrderController` | Validate order, emit PaymentInitiatedEvent | 95% |
| `WebhookController` | Validate signature, emit WebhookReceivedEvent | 100% |
| `AjaxPaymentController` | Validate AJAX requests, emit events | 90% |
| `Admin/*` | Validate admin actions, emit events | 90% |

#### NEW Event-Driven Patterns

**Payment Initiation (Event-Driven):**
```php
OrderController::execute()
  → Validate basket, user session
  → Cache request data (basket, user, session)
  → Emit PaymentInitiatedEvent($eventContext)
  → Event Handler: Creates order, calls provider
  → Event Handler: Emits OrderCreatedAtProviderEvent
  → Controller receives provider redirect URL from event
  → Return redirect response
```

**Webhook Processing (Event-Driven):**
```php
WebhookController::handleRequest()
  → Validate webhook signature
  → Emit WebhookReceivedEvent($payload)
  → Event Handler: Processes payment, updates order
  → Event Handler: Emits PaymentCapturedEvent
  → Multiple Subscribers: Email, inventory, analytics
  → Return HTTP 200
```

**Controller Pseudo-Code Pattern:**
```php
class OrderController {
    public function execute(Request $request): Response {
        // 1. Security & Validation
        $this->validateCsrfToken($request);
        $user = $this->requireAuthenticatedUser();
        $basket = $this->validateBasket();

        // 2. Cache request data
        $context = new EventContext([
            'basket' => $basket,
            'user' => $user,
            'session' => $this->session,
            'returnUrl' => $request->get('returnUrl'),
        ]);

        // 3. Emit event
        $event = new PaymentInitiatedEvent($context);
        $this->dispatcher->dispatch($event);

        // 4. Return response based on event outcome
        if ($event->hasProviderRedirectUrl()) {
            return $this->redirect($event->getProviderRedirectUrl());
        }

        return $this->error('Payment initiation failed');
    }
}
```

### Templates
**Location:** `views/`

- `views/blocks/page/checkout/` - Checkout integration points
- `views/blocks/page/details/` - Product page buttons
- `views/admin/tpl/` - Admin interface
- `views/blocks/email/` - Email templates

**Pattern:** Template blocks extend shop templates at specific extension points

---

## 1.5 Event Handlers & Subscribers (NEW - Core Business Logic)

### Responsibilities
- **Primary business logic layer** in event-driven architecture
- Listen to domain events
- Execute workflows (order creation, payment capture, etc.)
- Call services to perform operations
- Access cached request data via EventContext
- Emit new events to trigger downstream processes

### Components

#### Event Handlers
**Location:** `src/EventHandler/`

| Handler | Listens To | Purpose |
|---------|-----------|---------|
| `PaymentInitiationHandler` | PaymentInitiatedEvent | Create order, call provider API |
| `PaymentCaptureHandler` | PaymentCapturedEvent | Update order status, finalize |
| `OrderCompletionHandler` | OrderCompletedEvent | Send email, clear cart |
| `PaymentFailureHandler` | PaymentFailedEvent | Rollback, notify user |
| `WebhookProcessingHandler` | WebhookReceivedEvent | Process provider webhooks |

**Handler Pattern:**
```php
class PaymentInitiationHandler {
    public function handle(PaymentInitiatedEvent $event): void {
        // 1. Get cached data from context
        $basket = $event->getContext()->getBasket();
        $user = $event->getContext()->getUser();

        // 2. Execute business logic
        $order = $this->orderManager->createTemporaryOrder($basket, $user);

        // 3. Call provider API via service
        $providerOrder = $this->paymentService->createProviderOrder($order);

        // 4. Emit new event
        $this->dispatcher->dispatch(
            new OrderCreatedAtProviderEvent($order, $providerOrder)
        );

        // 5. Store result in original event for controller
        $event->setProviderRedirectUrl($providerOrder->getApprovalUrl());
    }
}
```

#### Event Subscribers (Side Effects)
**Location:** `src/EventSubscriber/`

| Subscriber | Listens To | Purpose |
|------------|-----------|---------|
| `EmailNotificationSubscriber` | OrderCompletedEvent | Send order confirmation |
| `InventorySubscriber` | OrderCompletedEvent | Reduce stock |
| `AnalyticsSubscriber` | Multiple | Track conversion events |
| `AuditLogSubscriber` | All payment events | Audit logging |

**Benefits**:
- Multiple subscribers can react to same event
- Easy to add new features without modifying core
- Decoupled from business logic

---

## 2. Service Layer (Refactored)

### NEW Responsibilities (Called by Event Handlers)
- Implement reusable business operations
- NO direct controller access - called by event handlers
- Integrate with external APIs (payment providers)
- Enforce business rules
- Stateless operations

### Components

#### Core Services
**Location:** `src/Service/`

| Service | Purpose | Called By | Reusability |
|---------|---------|-----------|-------------|
| `PaymentService` | Provider API operations | Event Handlers | 90% |
| `AuthorizationService` | Two-step auth/capture flow | PaymentService | 100% |
| `IdempotencyService` | Duplicate request prevention | PaymentService | 100% |
| `VaultingService` | Save payment methods | PaymentService | 100% |
| `SCAValidatorService` | 3D Secure validation | PaymentService | 100% |
| `RefundService` | Refund calculations | PaymentService | 100% |
| `OrderManager` | Order lifecycle | Event Handlers | 100% |
| `OrderRepository` | Order data access | Event Handlers | 100% |
| `ModuleSettings` | Configuration | Event Handlers | 100% |
| `OrderProcessTracking` | Process tracking | Event Handlers | 100% |
| `BasketSummary` | Amount calculations | Event Handlers | 100% |
| `UserRepository` | User data access | Event Handlers | 100% |

#### Payment Service - Core Methods

**Payment Creation:**
```php
initiatePayment(
    order, paymentMethod, directCapture = false
): PaymentResponse

// Creates payment via adapter
// Handles idempotency
// Tracks transaction
```

**Authorization Flow (NEW):**
```php
authorizePayment(request): AuthorizationResponse
captureAuthorization(authorizationId, amount): CaptureResponse
voidAuthorization(authorizationId): VoidResponse
reauthorizePayment(authorizationId): AuthorizationResponse

// Two-step authorization flow
// Partial/full capture support
// Reauthorization for expiring auths
```

**3D Secure Flow (NEW):**
```php
initiate3DSecure(request): ThreeDSecureResponse
verify3DSecureResult(providerPaymentId): bool

// Start 3DS authentication
// Verify authentication result
```

**Vaulting (NEW):**
```php
createPaymentWithSavedMethod(
    order, savedPaymentMethodId
): PaymentResponse

vaultPaymentMethod(
    user, providerPaymentMethodId
): SavedPaymentMethod

// Use saved payment methods
// Vault new payment methods
```

**Refund Operations:**
```php
refundPayment(
    orderId, amount = null, isPartial = false
): RefundResponse

// Full or partial refund
// Validates max refundable amount
// Tracks refunded amounts
```

**Transaction Tracking:**
```php
trackTransaction(
    orderId, providerOrderId, status, transactionType
): PaymentTransaction

// Persists transaction to database
```

### Service Dependencies

```
PaymentService
  ├─ uses: PaymentAdapterInterface (provider abstraction)
  ├─ uses: AuthorizationService (two-step auth flow)
  ├─ uses: IdempotencyService (duplicate prevention)
  ├─ uses: VaultingService (saved payment methods)
  ├─ uses: SCAValidatorService (3D Secure validation)
  ├─ uses: RefundService (refund calculations)
  ├─ uses: OrderRepository (data access)
  ├─ uses: PaymentTransactionRepository (transaction tracking)
  ├─ uses: ModuleSettings (configuration)
  └─ uses: Logger (debugging/errors)

AuthorizationService
  ├─ uses: PaymentAdapterInterface (provider operations)
  ├─ uses: PaymentTransactionRepository (authorization tracking)
  └─ uses: Logger

IdempotencyService
  ├─ uses: IdempotencyRepository (key/result storage)
  └─ uses: Cache

VaultingService
  ├─ uses: PaymentAdapterInterface (provider vaulting)
  ├─ uses: SavedPaymentMethodRepository (stored methods)
  └─ uses: Logger

SCAValidatorService
  ├─ uses: PaymentAdapterInterface (3DS operations)
  ├─ uses: ModuleSettings (3DS configuration)
  └─ uses: Logger

RefundService
  ├─ uses: PaymentAdapterInterface (provider refunds)
  ├─ uses: PaymentTransactionRepository (refund tracking)
  ├─ uses: ModuleSettings (provider-specific rules)
  └─ uses: Logger
```

### Service Layer Patterns

#### 1. Repository Pattern
```php
interface OrderRepository {
    paymenterOrderByOrderIdAndPaymenterId(
        shopOrderId, paymenterOrderId, transactionId
    ): PaymenterOrder

    getShopOrderByPaymenterOrderId(paymenterOrderId): Order

    cleanUpNotFinishedOrders(): void
}
```

**Benefits:**
- Abstracts data access
- Testable with mocks
- Centralized query logic

#### 2. Configuration Service Pattern
```php
class ModuleSettings {
    isSandbox(): bool
    getClientId(): string
    getClientSecret(): string
    getPaymenterStandardCaptureStrategy(): string  // 'directly', 'delivery', 'manually'
    isAcdcEligibility(): bool
    isPuiEligibility(): bool
    // ... 50+ configuration methods
}
```

**Benefits:**
- Centralized configuration
- Environment separation (sandbox/production)
- Type-safe access

#### 3. Factory Service Pattern
```php
class OrderRequestFactory {
    setBasket(basket): self

    getRequest(
        basket, intent, userAction, customId,
        processingInstruction, paymentSource,
        payPalClientMetadataId, returnUrl, cancelUrl,
        setProvidedAddress
    ): OrderRequest
}
```

**Benefits:**
- Separates construction from business logic
- Reusable request building
- Testable independently

---

## 2.5 SDK-Adapter Layer (NEW - Provider Abstraction)

### Responsibilities
- **Unified interface** for all payment provider SDKs (Stripe, Unzer, PayPal, Adyen, etc.)
- **Translate** component requests to provider-specific formats
- **Map** provider responses to component format
- **Isolate** business logic from provider SDK changes
- **Enable** easy provider switching via configuration

### Architecture Principle: Provider Agnostic Design

**Problem Without SDK-Adapter:**
```php
// ❌ BAD: Business logic tightly coupled to Stripe SDK
class PaymentService {
    public function createPayment(Order $order): void {
        $stripe = new \Stripe\StripeClient($apiKey);
        $intent = $stripe->paymentIntents->create([
            'amount' => $order->getTotal() * 100, // Stripe-specific cents conversion
            'currency' => strtolower($order->getCurrency()),
            // ... Stripe-specific code everywhere
        ]);
    }
}
```

**Solution With SDK-Adapter:**
```php
// ✅ GOOD: Business logic uses provider-agnostic interface
class PaymentService {
    public function __construct(
        private PaymentAdapterInterface $adapter  // Provider-agnostic!
    ) {}

    public function createPayment(Order $order): void {
        $request = new CreatePaymentRequest(
            amount: $order->getTotal(),
            currency: $order->getCurrency(),
            orderId: $order->getId()
        );

        // Works with Stripe, Unzer, PayPal, etc.
        $response = $this->adapter->createPayment($request);
    }
}
```

### Components

#### Core Interface
**Location:** `src/Adapter/PaymentAdapterInterface.php`

```php
interface PaymentAdapterInterface
{
    // Payment operations
    public function createPayment(CreatePaymentRequest $request): PaymentResponse;
    public function capturePayment(CapturePaymentRequest $request): CaptureResponse;
    public function refundPayment(RefundPaymentRequest $request): RefundResponse;
    public function voidPayment(VoidPaymentRequest $request): VoidResponse;
    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse;

    // Provider metadata
    public function getSupportedPaymentMethods(): array;
    public function getProviderName(): string;
    public function supportsFeature(string $feature): bool;

    // Webhook handling
    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent;
}
```

#### Provider Adapters
**Location:** `src/Adapter/Provider/`

| Adapter | Provider | SDK | Reusability |
|---------|----------|-----|-------------|
| `StripeAdapter` | Stripe | stripe/stripe-php | Provider-specific (30%) |
| `UnzerAdapter` | Unzer | unzerdev/php-sdk | Provider-specific (30%) |
| `PayPalAdapter` | PayPal | paypal/paypal-checkout-sdk | Provider-specific (30%) |
| `AdyenAdapter` | Adyen | adyen/php-api-library | Provider-specific (30%) |

**Note:** While adapters are provider-specific (30% reusable), the **adapter pattern itself is 100% reusable**.

#### Request Objects (100% Reusable)
**Location:** `src/Adapter/Request/`

```php
// Provider-agnostic request
class CreatePaymentRequest
{
    public function __construct(
        private readonly float $amount,
        private readonly string $currency,
        private readonly string $orderId,
        private readonly string $shopId,
        private readonly string $paymentMethod,
        private readonly bool $directCapture = false,
        private readonly ?string $returnUrl = null,
        private readonly ?string $cancelUrl = null
    ) {}

    // Getters...
}
```

**Other Requests:**
- `CapturePaymentRequest` - Capture authorized payment
- `RefundPaymentRequest` - Refund captured payment
- `VoidPaymentRequest` - Cancel authorization
- All are provider-agnostic and 100% reusable

#### Response Objects (100% Reusable)
**Location:** `src/Adapter/Response/`

```php
// Provider-agnostic response
class PaymentResponse
{
    public function __construct(
        private readonly string $providerPaymentId,
        private readonly string $status,
        private readonly float $amount,
        private readonly string $currency,
        private readonly ?string $clientSecret = null,
        private readonly bool $requiresAction = false,
        private readonly ?string $nextActionUrl = null
    ) {}

    // Status helpers
    public function isPending(): bool { return $this->status === 'pending'; }
    public function isAuthorized(): bool { return $this->status === 'authorized'; }
    public function isCaptured(): bool { return $this->status === 'captured'; }
}
```

**Other Responses:**
- `CaptureResponse` - Capture result
- `RefundResponse` - Refund result
- `PaymentDetailsResponse` - Payment details
- All are provider-agnostic and 100% reusable

#### Adapter Factory (100% Reusable)
**Location:** `src/Adapter/AdapterFactory.php`

```php
class AdapterFactory
{
    public function __construct(
        private readonly ModuleSettings $settings
    ) {}

    // Create adapter based on configuration
    public function createAdapter(string $providerName): PaymentAdapterInterface
    {
        return match ($providerName) {
            'stripe' => $this->createStripeAdapter(),
            'unzer' => $this->createUnzerAdapter(),
            'paypal' => $this->createPayPalAdapter(),
            'adyen' => $this->createAdyenAdapter(),
            default => throw new \InvalidArgumentException("Unknown provider: {$providerName}"),
        };
    }

    // Create default configured adapter
    public function createDefaultAdapter(): PaymentAdapterInterface
    {
        $provider = $this->settings->getDefaultProvider();
        return $this->createAdapter($provider);
    }
}
```

### Example: StripeAdapter Implementation

```php
final class StripeAdapter implements PaymentAdapterInterface
{
    private StripeClient $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly bool $sandbox = false
    ) {
        $this->client = new StripeClient($this->apiKey);
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        try {
            // Translate component request to Stripe format
            $intent = $this->client->paymentIntents->create([
                'amount' => $this->convertAmountToCents($request->getAmount()),
                'currency' => strtolower($request->getCurrency()),
                'capture_method' => $request->isDirectCapture() ? 'automatic' : 'manual',
                'metadata' => ['order_id' => $request->getOrderId()],
            ]);

            // Translate Stripe response to component format
            return new PaymentResponse(
                providerPaymentId: $intent->id,
                status: $this->mapStripeStatus($intent->status),
                amount: $this->convertCentsToAmount($intent->amount),
                currency: strtoupper($intent->currency),
                clientSecret: $intent->client_secret,
                requiresAction: $intent->status === 'requires_action'
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

    private function convertAmountToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'requires_capture' => 'authorized',
            'succeeded' => 'captured',
            'requires_action' => 'requires_action',
            'canceled' => 'cancelled',
            default => 'unknown',
        };
    }

    public function getProviderName(): string
    {
        return 'stripe';
    }

    public function getSupportedPaymentMethods(): array
    {
        return ['card', 'ideal', 'sepa_debit', 'sofort', 'giropay'];
    }
}
```

### Error Handling

**Unified Exception:**
```php
class PaymentAdapterException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly ?string $provider = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromProviderError(
        string $provider,
        string $message,
        string $code,
        ?\Throwable $previous = null
    ): self {
        return new self(
            message: "[{$provider}] {$message}",
            errorCode: $code,
            provider: $provider,
            previous: $previous
        );
    }

    public function isCardDeclined(): bool
    {
        return in_array($this->errorCode, ['card_declined', 'insufficient_funds']);
    }
}
```

### Integration with PaymentService

```php
class PaymentService
{
    public function __construct(
        private readonly PaymentAdapterInterface $adapter,  // Injected adapter
        private readonly PaymentTransactionRepository $transactionRepo,
        private readonly PaymentOrderStateRepository $orderStateRepo
    ) {}

    public function initiatePayment(Order $order, string $paymentMethod): PaymentResponse
    {
        // Create adapter request (provider-agnostic)
        $request = new CreatePaymentRequest(
            amount: $order->getTotalAmount(),
            currency: $order->getCurrency(),
            orderId: $order->getId(),
            shopId: $order->getShopId(),
            paymentMethod: $paymentMethod,
            directCapture: $this->shouldDirectCapture($paymentMethod),
            returnUrl: $this->buildReturnUrl($order),
            cancelUrl: $this->buildCancelUrl($order)
        );

        try {
            // Call adapter (works with any provider)
            $response = $this->adapter->createPayment($request);

            // Track transaction
            $transaction = new PaymentTransaction(
                shopId: $order->getShopId(),
                orderId: $order->getId(),
                providerOrderId: $response->getProviderPaymentId(),
                status: $response->getStatus(),
                paymentMethodId: $paymentMethod,
                transactionType: $response->isCaptured() ? 'capture' : 'authorization'
            );
            $this->transactionRepo->save($transaction);

            return $response;

        } catch (PaymentAdapterException $e) {
            // Unified error handling for all providers
            $this->logger->error('Payment initiation failed', [
                'provider' => $e->getProvider(),
                'error' => $e->getErrorCode(),
                'order_id' => $order->getId(),
            ]);
            throw new PaymentException("Payment failed: {$e->getMessage()}", previous: $e);
        }
    }
}
```

### Benefits

✅ **Provider Agnostic** - Business logic doesn't know about Stripe/Unzer/PayPal
✅ **Easy Testing** - Mock `PaymentAdapterInterface`, not provider SDKs
✅ **Easy Provider Switching** - Change provider via configuration
✅ **Consistent Errors** - All providers throw `PaymentAdapterException`
✅ **SDK Independence** - Update provider SDKs without changing component
✅ **100% Pattern Reusability** - The adapter pattern works for ANY provider

### Configuration

**Module Settings:**
```yaml
payment:
  default_provider: stripe
  providers:
    stripe:
      api_key: sk_test_...
      sandbox: true
    unzer:
      private_key: s-priv-...
      sandbox: true
    paypal:
      client_id: ...
      client_secret: ...
      sandbox: true
```

**DI Container Registration:**
```php
// Register factory
$container->singleton(AdapterFactory::class, function ($c) {
    return new AdapterFactory($c->get(ModuleSettings::class));
});

// Register default adapter
$container->singleton(PaymentAdapterInterface::class, function ($c) {
    return $c->get(AdapterFactory::class)->createDefaultAdapter();
});
```

**Documentation:** See [04-sdk-adapter-layer.md](04-sdk-adapter-layer.md) for complete SDK-Adapter architecture details.

---

## 3. Domain Layer (Refactored - OXID 7.4+)

### Responsibilities
- Define business entities and value objects
- Encapsulate business rules
- **Reference core shop models via FK** (NO class extensions in metadata.php)
- Define domain events
- Maintain payment state machine

### Architecture Principle: Foreign Key References (NEW)

**OLD Approach (Deprecated)**:
```
❌ Class extensions: Order extends oxOrder
❌ ALTER TABLE oxorder ADD COLUMN...
❌ Tight coupling to OXID core
```

**NEW Approach (OXID 7.4+)**:
```
✅ Component models with FK references
✅ CREATE TABLE osc_payment_* with OXORDERID FK
✅ Clean isolation from OXID core
✅ No metadata.php extensions
```

### Components

#### Component Models (NEW - FK Reference Pattern)
**Location:** `src/Component/Model/`

**Philosophy**: Component uses separate tables that reference OXID core via FK

| Component Model | References | Relationship | Reusability |
|-----------------|------------|--------------|-------------|
| `PaymentTransaction` | oxorder.OXID | 1:N (FK) | 100% |
| `PaymentOrderState` | oxorder.OXID | 1:1 (FK + UNIQUE) | 100% |
| `PaymentCustomer` | oxuser.OXID | 1:1 (FK + UNIQUE) | 100% |
| `PaymentBasketSnapshot` | oxorder.OXID | 1:N (FK) | 100% |

**Example - PaymentOrderState Model (NEW):**
```php
namespace Osc\Payment\Component\Model;

/**
 * Payment Order State
 *
 * Stores payment lifecycle state in separate component table.
 * References oxorder via FK (1:1), does NOT extend oxOrder.
 */
final class PaymentOrderState implements PaymentOrderStates
{
    private ?string $id = null;
    private string $orderId;  // FK to oxorder.OXID (NOT inheritance!)
    private string $paymentState;
    private ?string $providerOrderId = null;
    private ?\DateTime $webhookWaitSince = null;
    private ?int $webhookTimeout = null;

    public function __construct(string $orderId, string $paymentState = self::STATE_NOT_FINISHED)
    {
        $this->orderId = $orderId;  // Store FK reference
        $this->paymentState = $paymentState;
    }

    // State machine methods
    public function markAsPaymentInProgress(): void
    {
        $this->validateStateTransition(self::STATE_PAYMENT_IN_PROGRESS);
        $this->paymentState = self::STATE_PAYMENT_IN_PROGRESS;
    }

    public function markAsWaitingForWebhook(): void
    {
        $this->validateStateTransition(self::STATE_WAITING_FOR_WEBHOOK);
        $this->paymentState = self::STATE_WAITING_FOR_WEBHOOK;
        $this->webhookWaitSince = new \DateTime();
    }

    public function markAsCompleted(): void
    {
        $this->validateStateTransition(self::STATE_OK);
        $this->paymentState = self::STATE_OK;
    }

    // Getters
    public function getOrderId(): string { return $this->orderId; }
    public function getPaymentState(): string { return $this->paymentState; }
}
```

**Example - PaymentTransaction Model:**
```php
namespace Osc\Payment\Component\Model;

/**
 * Payment Transaction
 *
 * Tracks provider transactions in separate component table.
 * References oxorder via FK (1:N), does NOT extend oxOrder.
 */
final class PaymentTransaction
{
    private ?string $id = null;
    private string $shopId;
    private string $orderId;  // FK to oxorder.OXID
    private string $providerOrderId;
    private ?string $transactionId = null;
    private string $status;
    private string $paymentMethodId;
    private string $transactionType; // capture, authorization, refund
    private array $providerData = [];

    public function __construct(
        string $shopId,
        string $orderId,
        string $providerOrderId,
        string $status,
        string $paymentMethodId,
        string $transactionType = 'capture'
    ) {
        $this->shopId = $shopId;
        $this->orderId = $orderId;  // Store FK reference
        $this->providerOrderId = $providerOrderId;
        $this->status = $status;
        $this->paymentMethodId = $paymentMethodId;
        $this->transactionType = $transactionType;
    }

    // State management
    public function markAsCompleted(): void { $this->status = 'COMPLETED'; }
    public function markAsRefunded(): void { $this->status = 'REFUNDED'; }
    public function setTransactionId(string $id): void { $this->transactionId = $id; }

    // Getters
    public function getOrderId(): string { return $this->orderId; }
    public function getProviderOrderId(): string { return $this->providerOrderId; }
    public function getTransactionId(): ?string { return $this->transactionId; }
    public function getStatus(): string { return $this->status; }
}
```

**Example - PaymentCustomer Model:**
```php
namespace Osc\Payment\Component\Model;

/**
 * Payment Customer
 *
 * Stores customer payment data in separate component table.
 * References oxuser via FK (1:1), does NOT extend oxUser.
 */
final class PaymentCustomer
{
    private ?string $id = null;
    private string $userId;  // FK to oxuser.OXID (1:1)
    private ?string $paymentCustomerId = null;
    private ?string $defaultPaymentMethod = null;
    private array $savedPaymentMethods = [];

    public function __construct(string $userId)
    {
        $this->userId = $userId;  // Store FK reference
    }

    public function getUserId(): string { return $this->userId; }
    public function getPaymentCustomerId(): ?string { return $this->paymentCustomerId; }

    public function setPaymentCustomerId(string $customerId): void
    {
        $this->paymentCustomerId = $customerId;
    }

    public function addSavedPaymentMethod(string $methodId): void
    {
        $this->savedPaymentMethods[] = $methodId;
    }
}
```

#### Key Model Patterns

**Order State Management:**
```php
class Order extends EshopModelOrder {
    // Payment states
    const ORDER_STATE_SESSIONPAYMENT_INPROGRESS = 500;
    const ORDER_STATE_WAIT_FOR_WEBHOOK_EVENTS = 600;
    const ORDER_STATE_ACDCINPROGRESS = 700;
    const ORDER_STATE_ACDCCOMPLETED = 750;
    const ORDER_STATE_NEED_CALL_ACDC_FINALIZE = 800;
    const ORDER_STATE_TIMEOUT_FOR_WEBHOOK_EVENTS = 900;

    finalizeOrder(): int
    finalizeOrderAfterExternalPayment(basket): int
    markOrderPaid(): void
    setTransId(transactionId): void
    isOrderFinished(): bool
    isWaitForWebhookTimeoutReached(): bool
}
```

**Transaction Tracking Model:**
```php
class PaymenterOrder extends EshopCoreModel {
    getPaymenterOrderId(): string
    getTransactionId(): string
    getShopOrderId(): string
    getStatus(): string
    getPaymentMethodId(): string

    setStatus(status): void
    setTransactionId(id): void
    setPaymentMethodId(id): void
    setTransactionType(type): void  // 'capture' or 'authorization'
}
```

**Basket Amount Methods:**
```php
class Basket extends EshopModelBasket {
    getPaymenterCheckoutWrapping(): float
    getPaymenterCheckoutGiftCard(): float
    getPaymenterCheckoutPayment(): float
    getPaymenterCheckoutDeliveryCosts(): float
    getPaymenterCheckoutDiscount(): float
    getPaymenterCheckoutItems(): float
    isVirtualPaymenterBasket(): bool
    isFractionQuantityItemsPresent(): bool
}
```

#### Events
**Location:** `src/Event/`

```php
class PaymenterOrderCompletedEvent extends Event {
    private Order $order;
    private Basket $basket;
    private User $user;
    private string $shopOrderId;
    private string $payPalOrderId;
    private string $paymentsId;
    private string $transactionId;
    private string $payPalCustomerId;

    // Getters...
}

class PaymenterVaultingSucceededEvent extends Event {
    private User $user;
    private string $payPalCustomerId;

    // Getters...
}
```

**Pattern:** Domain events represent important business occurrences

---

## 4. Data Access Layer (Enhanced with Caching)

### Responsibilities
- Execute database queries
- Map database rows to domain objects
- **Cache frequently accessed data** (NEW)
- Manage transactions
- Optimize queries

### Components

#### Repositories (Enhanced)
**Location:** `src/Repository/`

**OrderRepository Methods:**
```php
getTransactionByOrderAndProvider(
    shopOrderId, providerOrderId, transactionId
): PaymentTransaction

getOrderByProviderOrderId(providerOrderId): Order

getOrderByTransactionId(transactionId): Order

getProviderOrderIdByShopOrderId(shopOrderId): string

getCurrentOrderId(): string
getCurrentOrder(): Order

cleanUpAbandonedOrders(): void
```

**UserRepository Methods:**
```php
getUserById(userId): User
getUserCountryIso(user): string
getUserStateIso(user): string
```

#### Request Data Cache (NEW)
**Location:** `src/Cache/RequestDataCache.php`

**Purpose**: Cache expensive data fetches within a single HTTP request

```php
class RequestDataCache {
    private array $cache = [];

    // Cache basket for request lifetime
    public function cacheBasket(Basket $basket): void;
    public function getBasket(): ?Basket;

    // Cache user for request lifetime
    public function cacheUser(User $user): void;
    public function getUser(): ?User;

    // Cache session data
    public function cacheSessionData(array $data): void;
    public function getSessionData(): array;

    // Clear cache after request
    public function clear(): void;
}
```

**Usage Pattern (in Controllers):**
```php
class OrderController {
    public function execute() {
        // Fetch once, cache for all event handlers
        $basket = $this->basketRepository->getCurrentBasket();
        $user = $this->userRepository->getCurrentUser();

        $this->requestCache->cacheBasket($basket);
        $this->requestCache->cacheUser($user);

        // Event handlers access cached data
        $event = new PaymentInitiatedEvent($this->requestCache);
        $this->dispatcher->dispatch($event);
    }
}
```

**Benefits**:
- Reduces database queries by 50-70%
- Ensures data consistency across event handlers
- No need to pass objects through multiple layers

### Database Schema (OXID 7.4+)

**Component Tables with FK References (NO ALTER TABLE on core):**

**⚠️ IMPORTANT:** The database schema has been **normalized** to use a **Master-Detail Pattern** for optimal performance and maintainability.

**📖 Complete Database Documentation:** See [02-database-and-models.md](02-database-and-models.md) for:
- Normalized master-detail pattern architecture
- Performance comparison (3-6x faster queries)
- Complete SQL schemas for all 11 tables
- Data models with FK reference pattern
- Query examples and repository patterns
- Migration scripts
- Provider-specific handling

**Quick Summary:**
- **Master Table** (15 columns): `osc_payment_transaction` - Core fields only (~250 bytes/row)
- **Detail Tables** (5 tables): Authorization, 3DS, Refund, Tracking, Provider Data
- **Support Tables** (5 tables): Order State, Customer, Idempotency, Saved Methods, Sessions
- **Benefits**: 6x smaller rows, 3-6x faster queries, 60-70% storage reduction, NULL-free schema

**Database Tables (11 Total):**

1. **osc_payment_transaction** - Master table (15 columns, ~250 bytes/row)
2. **osc_payment_authorization_details** - Authorization-specific (computed columns for expiry)
3. **osc_payment_3ds_details** - 3D Secure/SCA fields
4. **osc_payment_refund_details** - Refund calculations
5. **osc_payment_delivery_tracking** - Shipment tracking (Amazon Pay, PayPal)
6. **osc_payment_provider_data** - Flexible key-value storage
7. **osc_payment_order_state** - Payment lifecycle state (1:1 with oxorder)
8. **osc_payment_customer** - Customer payment data (1:1 with oxuser)
9. **osc_payment_idempotency** - Duplicate charge prevention (CRITICAL P0)
10. **osc_payment_saved_methods** - Vaulting/tokenization
11. **osc_payment_sessions** - Session state (Amazon Pay, PayPal)

**Pattern**:
- Component tables are **completely independent** from OXID core
- Can be dropped with `DROP TABLE` without affecting oxorder/oxuser
- FK constraints ensure data integrity
- OXID core tables remain **unmodified** (no ALTER TABLE)

**📖 See:** [02-database-and-models.md](02-database-and-models.md) for complete schemas, models, migrations, and query examples.

---

## 5. Infrastructure Layer

### Responsibilities
- Provide low-level services
- Manage external resources
- Handle cross-cutting concerns

### Components

- **Database Connection:** Doctrine DBAL QueryBuilder
- **HTTP Client:** Guzzle / cURL
- **Logger:** PSR-3 Logger (Monolog)
- **Session:** Shop session management
- **Config:** Shop configuration access
- **Event Dispatcher:** PSR-14 or Symfony EventDispatcher

### Service Factory Pattern

```php
class ServiceFactory {
    getOrderService(): ApiOrderService
    getPaymentService(): ApiPaymentService
    getVaultingService(): VaultingService
    // Creates provider API clients
}
```

---

## 6. External Integration Layer

### Responsibilities
- Communicate with payment provider API
- Handle webhook notifications
- Verify signatures
- Map provider responses to domain objects

### Components

#### API Client Integration
**Location:** `src/Core/`, external SDK

- **Order API:** Create, update, capture, authorize orders
- **Payment API:** Capture, refund, reauthorize payments
- **Vaulting API:** Save/retrieve payment methods
- **Identity API:** User information
- **Webhook API:** Register/manage webhooks

#### Webhook Processing
**Location:** `src/Core/Webhook/`

**Components:**
- `Event` - Webhook event representation
- `EventVerifier` - Signature verification
- `EventDispatcher` - Route to handlers
- `EventHandlerMapping` - Event type → handler class
- `RequestHandler` - Process webhook request
- `Handler/WebhookHandlerBase` - Base handler class
- `Handler/*Handler` - Concrete event handlers

---

## Layer Communication Rules

### Allowed Dependencies
```
Presentation → Service → Domain → Data Access → Infrastructure
                      ↓
                   External
```

### Forbidden Dependencies
- Domain layer MUST NOT depend on Service layer
- Service layer MUST NOT depend on Presentation layer
- External systems accessed only through Service layer

### Dependency Injection

All layers use constructor injection:

```php
class PaymentService {
    public function __construct(
        Session $session,
        OrderRepository $orderRepository,
        SCAValidatorInterface $scaValidator,
        ModuleSettings $moduleSettings,
        LoggerInterface $logger,
        OrderProcessTrackingService $trackingService,
        ServiceFactory $serviceFactory,
        PatchRequestFactory $patchFactory,
        OrderRequestFactory $requestFactory
    ) {
        // Store dependencies
    }
}
```

**Benefits:**
- Testability (inject mocks)
- Flexibility (swap implementations)
- Explicit dependencies (no hidden coupling)

---

## Cross-Cutting Concerns

### Logging
```php
$this->logger->log('debug', 'Payment order created', [
    'orderId' => $orderId,
    'amount' => $amount
]);
```

**Pattern:** PSR-3 Logger injected into services

### Error Handling
```php
try {
    $response = $orderService->createOrder($request);
} catch (ApiException $e) {
    $this->handlePaymenterApiError($e);
    $this->setPaymentExecutionError(self::PAYMENT_ERROR_GENERIC);
}
```

**Pattern:** Catch provider-specific exceptions, convert to domain errors

### Configuration
```php
$captureStrategy = $this->moduleSettings->getPaymenterStandardCaptureStrategy();
$isSandbox = $this->moduleSettings->isSandbox();
```

**Pattern:** Centralized configuration service

### Session Management
```php
$sessionOrderId = $this->session->getVariable('sess_challenge');
PaymenterSession::storePaymenterOrderId($payPalOrderId);
```

**Pattern:** Wrapper around shop session

---

## Summary: Layer Reusability

| Layer | Reusability | Notes |
|-------|-------------|-------|
| Presentation | 70-90% | Controller patterns reusable, templates vary |
| Service | 90-100% | Core business logic is generic |
| Domain | 90-100% | State machine and patterns reusable |
| Data Access | 100% | Fully generic repositories |
| Infrastructure | 100% | Standard interfaces (PSR) |
| External | 30-50% | Provider-specific, but patterns apply |

---

**Continue to:** [02-database-and-models.md](02-database-and-models.md)
