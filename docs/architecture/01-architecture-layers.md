# Architecture Layers

**Date:** 2026-02-04
**Based on:** Actual code analysis

---

## Layer Diagram

```
┌───────────────────────────────────────────────────────────────────┐
│  PRESENTATION LAYER                                               │
│  Controllers (thin, emit events only)                             │
│  - WebhookController, PaymentController, OrderController          │
│  - Admin: OrderRefund, StripeConnect, ModuleConfiguration         │
└────────────────────────────┬──────────────────────────────────────┘
                             │ emits events
┌────────────────────────────▼──────────────────────────────────────┐
│  EVENT LAYER                                                      │
│  Domain Events + EventDispatcher (PSR-14 compatible)              │
│  - Contract Events: Created, Pending, ReadyToCommit, Fulfilled    │
│  - Payment Events: Authorized, Captured, Refunded, Failed         │
│  - Stripe Events: CheckoutSessionRequest, CheckoutReturn          │
└────────────────────────────┬──────────────────────────────────────┘
                             │ triggers
┌────────────────────────────▼──────────────────────────────────────┐
│  EVENT HANDLERS                                                   │
│  Business Logic (SOLID, single responsibility)                    │
│  - ContractCreationHandler, StripeCheckoutSessionHandler          │
│  - StripeOrderCreationHandler, PaymentAuthorizationHandler        │
│  - StripeCaptureRequestHandler, StripeRefundRequestHandler        │
└────────────────────────────┬──────────────────────────────────────┘
                             │ uses
┌────────────────────────────▼──────────────────────────────────────┐
│  CONTRACT DOMAIN                                                  │
│  PaymentContract (Aggregate Root) + State Machine                 │
│  - State validation, condition management                         │
│  - BasketSnapshot (immutable value object)                        │
└────────────────────────────┬──────────────────────────────────────┘
                             │ uses
┌────────────────────────────▼──────────────────────────────────────┐
│  SERVICE LAYER                                                    │
│  ContractService, CaptureService, RefundService                   │
│  - Business operations, validation, logging                       │
│  - CheckoutSessionService, CheckoutReturnService                  │
└────────────────────────────┬──────────────────────────────────────┘
                             │ uses
┌────────────────────────────▼──────────────────────────────────────┐
│  SDK-ADAPTER LAYER                                                │
│  PaymentAdapterInterface (provider-agnostic)                      │
│  - StripeAdapter implements unified interface                     │
│  - Normalized Request/Response DTOs                               │
└────────────────────────────┬──────────────────────────────────────┘
                             │ persists via
┌────────────────────────────▼──────────────────────────────────────┐
│  DATA ACCESS LAYER                                                │
│  Repositories (Doctrine DBAL)                                     │
│  - ContractRepositoryInterface → DoctrineContractRepository       │
│  - TransactionRepositoryInterface → DoctrineTransactionRepository │
│  - WebhookLogRepositoryInterface → DoctrineWebhookLogRepository   │
└───────────────────────────────────────────────────────────────────┘
```

---

## Layer Responsibilities

### 1. Presentation Layer (Controllers)

**Responsibility:** HTTP request handling, minimal logic, event emission

**payment-component Controllers:** None (library)

**stripe Controllers:**
| Controller | Purpose |
|------------|---------|
| `WebhookController` | Webhook entry point, delegates to processor |
| `PaymentController` | Payment method validation |
| `StripeOrderController` | OXID order extension |
| `OrderRefund` | Admin refund interface |
| `StripeConnect` | Stripe Connect OAuth |
| `ModuleConfiguration` | Module settings |

**Design Rule:** Controllers must NOT contain business logic. They emit events and delegate to services.

---

### 2. Event Layer

**Responsibility:** Decoupling, event-driven state changes

**Event Dispatcher:** PSR-14 compatible implementation with priority support

**Contract Events (payment-component):**
```
ContractCreatedEvent
ContractTransitionedToPendingEvent
ContractDraftCompletedEvent
ContractConditionFulfilledEvent
ContractReadyToCommitEvent
ContractCommittedEvent
ContractFulfilledEvent
ContractCancelledEvent
ContractExpiredEvent
ContractFailedEvent
```

**Payment Events (payment-component):**
```
PaymentInitiatedEvent
PaymentAuthorizedEvent
PaymentCapturedEvent
PaymentFailedEvent
PaymentRefundedEvent
OrderCreatedEvent
OrderCompletedEvent
WebhookReceivedEvent
```

**Stripe Events:**
```
StripeCheckoutSessionRequestEvent
StripeCheckoutReturnEvent
StripePaymentExecuteEvent
StripePaymentReturnEvent
StripeCaptureRequestEvent
StripeCancelAuthorizationRequestEvent
StripeRefundRequestEvent
Stripe3DSRequiredEvent
```

---

### 3. Event Handlers

**Responsibility:** Business logic execution, state transitions

**Pattern:** All handlers implement `HandlerInterface`:
```php
interface HandlerInterface
{
    public function handle(EventInterface $event): void;
    public function getHandledEventClass(): string;
}
```

**Base Class:** `AbstractHandler` provides repository and dispatcher injection

**Handler Registration:** Tagged services in `services.yaml`:
```yaml
tags:
  - { name: 'payment.event_handler', priority: 100 }
```

**Stripe Handlers:**
| Handler | Event | Priority |
|---------|-------|----------|
| StripeContractCreationHandler | StripeCheckoutSessionRequestEvent | 100 |
| StripeCheckoutSessionHandler | StripeCheckoutSessionRequestEvent | 0 |
| StripeCheckoutReturnHandler | StripeCheckoutReturnEvent | 100 |
| StripeOrderCreationHandler | ContractReadyToCommitEvent | 80 |
| StripePaymentStatusHandler | StripePaymentExecuteEvent | default |
| StripePaymentReturnHandler | StripePaymentReturnEvent | default |
| StripeCaptureRequestHandler | StripeCaptureRequestEvent | default |
| StripeCancelAuthorizationRequestHandler | StripeCancelAuthorizationRequestEvent | default |
| StripeRefundRequestHandler | StripeRefundRequestEvent | default |

---

### 4. Contract Domain

**Responsibility:** Domain model, state machine, business rules

**PaymentContract (Aggregate Root):**
- Manages valid state transitions
- Enforces business invariants
- Contains conditions array
- Tracks capture/refund amounts
- Links to OXID order (after commitment)

**ContractState Enum:**
```php
case DRAFT = 'draft';
case NOT_FINISHED = 'not_finished';
case PENDING = 'pending';
case AUTHORIZED = 'authorized';
case READY_TO_COMMIT = 'ready_to_commit';
case COMMITTED = 'committed';
case FULFILLED = 'fulfilled';
case CANCELLED = 'cancelled';
case EXPIRED = 'expired';
case FAILED = 'failed';
```

**BasketSnapshot (Value Object):**
- Immutable representation of basket
- Serialized to JSON in database
- Contains: items, discounts, totals, currency

---

### 5. Service Layer

**Responsibility:** Business operations, orchestration, validation

**payment-component Services:**
| Service | Purpose |
|---------|---------|
| ContractService | Contract creation, lookup, cleanup |
| ContractFulfillmentService | Fulfills contracts, dispatches events |
| ContractMetadataService | Provider metadata storage |
| OrderPaymentStateService | Order state management |
| WebhookLogService | Webhook logging |
| DeliveryAddressHashService | Address fingerprinting |

**stripe Services:**
| Service | Purpose |
|---------|---------|
| CheckoutSessionService | Creates Stripe Checkout sessions |
| CheckoutReturnService | Validates return from Stripe |
| CaptureService | Executes payment capture (full + partial) |
| CancelAuthorizationService | Cancels authorized payments |
| RefundService / StripeRefundService | Executes refunds (full + partial) |
| StripeOrderApiService | Stripe API calls for admin (PaymentIntent, Charge) |
| OxpaidReconciliationService | Self-healing for OXPAID timestamps |
| ContractTokenService | Secure token generation |
| ReturnSessionSecurityService | Return request validation |
| RequestLogService | API request logging |
| ModuleConfigurationService | Configuration access |
| OrderContractResolver | Maps OXID orders to payment contracts |

**Admin Services (Stripe tab):**
| Service | Purpose |
|---------|---------|
| OrderRefundViewDataProvider | Stripe API data for admin template (captured/refundable amounts, transaction history) |
| OrderActionDispatcher | Dispatches capture/refund/cancel events from admin (supports partial amounts) |

---

### 6. SDK-Adapter Layer

**Responsibility:** Provider abstraction, normalized interfaces

**PaymentAdapterInterface Methods:**
```php
// Basic operations
createPayment(CreatePaymentRequest): PaymentResponse
capturePayment(CapturePaymentRequest): CaptureResponse
refundPayment(RefundPaymentRequest): RefundResponse
voidPayment(VoidPaymentRequest): VoidResponse

// Two-step authorization
authorizePayment(AuthorizePaymentRequest): AuthorizationResponse
captureAuthorization(CaptureAuthorizationRequest): CaptureResponse
voidAuthorization(VoidAuthorizationRequest): VoidResponse

// Vaulting
createPaymentMethod(CreatePaymentMethodRequest): PaymentMethodResponse

// Webhooks
parseWebhook(string, array): WebhookEvent
verifyWebhookSignature(string, string, string): bool
```

**Request DTOs (payment-component):**
```
CreatePaymentRequest
AuthorizePaymentRequest
CapturePaymentRequest
CaptureAuthorizationRequest
RefundPaymentRequest
VoidPaymentRequest
VoidAuthorizationRequest
CreatePaymentMethodRequest
ThreeDSecureRequest
```

**Response DTOs (payment-component):**
```
PaymentResponse
AuthorizationResponse
CaptureResponse
RefundResponse
VoidResponse
PaymentMethodResponse
ThreeDSecureResponse
FraudCheckResponse
```

---

### 7. Data Access Layer

**Responsibility:** Data persistence, query operations

**Repository Interfaces:**
```php
interface ContractRepositoryInterface
{
    public function save(PaymentContractInterface $contract): void;
    public function findById(string $id): ?PaymentContractInterface;
    public function findByProviderOrderId(string $id): ?PaymentContractInterface;
    public function findActiveByUserId(string $userId): ?PaymentContractInterface;
    public function findExpired(\DateTimeInterface $before): array;
}

interface TransactionRepositoryInterface
{
    public function save(Transaction $transaction): void;
    public function findByContractId(string $contractId): array;
}

interface WebhookLogRepositoryInterface
{
    public function save(WebhookLog $log): void;
    public function existsByEventId(string $eventId): bool;
}
```

**Implementations:** Doctrine DBAL (DoctrineContractRepository, etc.)

---

## Data Flow Example: Checkout Session (Early Order Creation)

```
1. User clicks "Checkout with Stripe"
   ↓
2. StripeOrderController emits StripeCheckoutSessionRequestEvent
   ↓
3. StripeContractCreationHandler (priority 100)
   - Creates PaymentContract in DRAFT state
   - Adds conditions: payment_authorized
   - Saves to ContractRepository
   - Dispatches ContractDraftCompletedEvent
   ↓
4. EarlyOrderCreationHandler (triggered by ContractDraftCompletedEvent)
   - Creates OXID order via ShopOrderService
   - Stores order_number in contract.metadata
   - Transitions: DRAFT → NOT_FINISHED → PENDING
   - Saves contract with orderId
   ↓
5. StripeCheckoutSessionHandler (priority 0)
   - Retrieves contract (now in PENDING state with orderId)
   - Gets order_number from contract.metadata
   - Calls CheckoutSessionService with orderId and orderNumber
   - Order number included in Stripe PaymentIntent metadata
   - Sets redirect URL in event context
   ↓
6. User redirected to Stripe Checkout
   (Stripe dashboard shows order_number in metadata)
   ↓
7. User completes payment, returns to shop
   ↓
8. WebhookController receives payment_intent.succeeded
   ↓
9. StripeWebhookProcessor validates signature
   ↓
10. WebhookContractFulfillmentHandler.handlePaymentSucceeded()
    - Finds contract by provider_order_id
    - Fulfills payment_authorized condition
    - Dispatches ContractConditionFulfilledEvent
   ↓
11. ContractConditionResolverHandler
    - Checks if all conditions fulfilled
    - Transitions to READY_TO_COMMIT
    - Dispatches ContractReadyToCommitEvent
   ↓
12. StripeOrderCreationHandler (priority 80)
    - Detects existing orderId (skips order creation)
    - Updates OXTRANSID with PaymentIntent ID
    - Sets OXPAID timestamp
    - Transitions to COMMITTED → FULFILLED
   ↓
13. ContractFulfillmentService
    - Marks contract FULFILLED
    - Dispatches ContractFulfilledEvent
```

**Key Difference:** Order is created in step 4 (NOT_FINISHED), not step 12 (COMMITTED).
This allows the order number to be sent to Stripe in step 5.
