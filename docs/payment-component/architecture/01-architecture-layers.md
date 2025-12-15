# Event-Driven Architecture with Smart Contracts

**Component Documentation - Part 1**
**Version:** 4.0.0
**Date:** 2025-10-22
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)
**Visual Diagram:** [puml/01-architecture-overview.puml](puml/01-architecture-overview.puml)
**Database & Models:** [02-database-and-models.md](02-database-and-models.md) - Contract-aware schema
**Test Organization:** [10-test-organization.md](10-test-organization.md)

---

## Overview

The payment component follows an **event-driven layered architecture with smart-contract pattern** where business logic is decoupled from presentation concerns. Controllers act as thin validation and security layers that emit domain events. Event handlers orchestrate business operations around payment contracts.

**Key Innovation:** Payment contracts manage the lifecycle from intent to fulfillment, with orders created only when contracts are ready.

**📊 See Visual Diagram:** [puml/01-architecture-overview.puml](puml/01-architecture-overview.puml) for complete architecture visualization.

---

## Contract-Aware Layer Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                        │
│  Controllers (Frontend & CLI) - Security & Validation        │
│  ⚡ Emit Events, Don't Execute Business Logic                │
└────────────────────┬────────────────────────────────────────┘
                     │ emits events
┌────────────────────▼────────────────────────────────────────┐
│                      EVENT LAYER                             │
│  Domain Events, Event Dispatcher, Event Context             │
│  ContractCreated, ConditionFulfilled, ContractCommitted...  │
└───────┬────────────────────────────────────────────┬────────┘
        │ triggers                          triggers │
┌───────▼────────────────────────────────────────────▼────────┐
│            EVENT HANDLERS & SUBSCRIBERS                      │
│  Business Logic, Workflow Orchestration                      │
│  Contract lifecycle, condition resolution, order creation    │
└────────────────────┬────────────────────────────────────────┘
                     │ uses
┌────────────────────▼────────────────────────────────────────┐
│                 CONTRACT DOMAIN LAYER (NEW)                  │
│  PaymentContract (Aggregate Root), ContractCondition        │
│  Contract state machine, condition tracking                  │
└────────────────────┬────────────────────────────────────────┘
                     │ uses
┌────────────────────▼────────────────────────────────────────┐
│                     SERVICE LAYER                            │
│  ContractService, PaymentService, OrderManager              │
│  Called by Event Handlers - Contract-aware                  │
└────────────────────┬────────────────────────────────────────┘
                     │ uses
┌────────────────────▼────────────────────────────────────────┐
│                  SDK-ADAPTER LAYER                           │
│  PaymentAdapterInterface - Unified Provider Interface        │
│  Maps provider contracts to our contracts                    │
└────────────────────┬────────────────────────────────────────┘
                     │ persists
┌────────────────────▼────────────────────────────────────────┐
│                 DATA ACCESS LAYER                            │
│  ContractRepository, OrderRepository, Repositories           │
└────────────────────┬────────────────────────────────────────┘
                     │ uses
┌────────────────────▼────────────────────────────────────────┐
│                  INFRASTRUCTURE LAYER                        │
│  Database, HTTP Client, Logger, Session, Cache              │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                   EXTERNAL INTEGRATION                       │
│  Provider SDKs (Stripe, PayPal, Unzer, Amazon Pay, etc.)    │
│  ⬆️ Called via SDK-Adapter Layer (Provider-Agnostic)          │
│  Webhook Notifications → ContractLookup → Update Contract   │
└─────────────────────────────────────────────────────────────┘
```

**Key Architectural Principle**:
- **Controllers**: Thin, validate & emit events
- **Event Handlers**: Fat, contain business logic with contract awareness
- **Contracts**: Aggregate roots managing payment lifecycle
- **Services**: Reusable, contract-aware
- **Data flows through events and contracts, not direct method calls**

---

## 0. Contract Domain Layer (NEW - Primary Business Logic)

### Responsibilities
- Define payment contract aggregate root
- Manage contract state machine (DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED/CANCELLED/EXPIRED/FAILED)
- Track fulfillment conditions (payment_authorized, fraud_check, stock_reserved)
- Encapsulate business rules for contract lifecycle
- Emit domain events on state transitions

### Components

#### PaymentContract (Aggregate Root)
**Location:** `src/Component/Model/PaymentContract.php`

```php
class PaymentContract
{
    // States
    const STATE_DRAFT = 'draft';
    const STATE_PENDING = 'pending';
    const STATE_READY_TO_COMMIT = 'ready_to_commit';
    const STATE_COMMITTED = 'committed';
    const STATE_FULFILLED = 'fulfilled';
    const STATE_CANCELLED = 'cancelled';
    const STATE_EXPIRED = 'expired';
    const STATE_FAILED = 'failed';

    // Core properties
    private ?string $id;
    private string $userId;  // FK to oxuser
    private ?string $orderId;  // FK to oxorder (NULL until committed!)
    private string $state;
    private BasketSnapshot $basketSnapshot;  // Value Object
    private array $conditions = [];  // ContractCondition[]

    // Contract lifecycle methods
    public function addCondition(ContractCondition $condition): void;
    public function transitionToPending(): void;
    public function fulfillCondition(string $type, array $data = []): void;
    public function areAllConditionsFulfilled(): bool;
    public function commitToOrder(string $orderId): void;
    public function fulfill(): void;
    public function cancel(string $reason): void;
}
```

**Key Features:**
- **Aggregate Root**: Owns conditions, basket snapshot, state machine
- **Immutable Basket**: Captured at contract creation, never changes
- **Explicit Conditions**: Tracked in database, not hidden in code
- **Domain Events**: Emits events on state transitions
- **Order Creation Deferred**: OXORDERID is NULL until all conditions met

#### ContractCondition (Entity)
**Location:** `src/Component/Entity/ContractCondition.php`

```php
class ContractCondition
{
    // Types
    const TYPE_PAYMENT_AUTHORIZED = 'payment_authorized';
    const TYPE_FRAUD_CHECK = 'fraud_check';
    const TYPE_STOCK_RESERVED = 'stock_reserved';
    const TYPE_COMPLIANCE_CHECK = 'compliance_check';
    const TYPE_ADDRESS_VALIDATED = 'address_validated';

    // Statuses
    const STATUS_PENDING = 'pending';
    const STATUS_FULFILLED = 'fulfilled';
    const STATUS_FAILED = 'failed';

    private string $type;
    private string $status;
    private array $data;
    private ?\DateTime $fulfilledAt;

    public function fulfill(array $data = []): void;
    public function fail(string $reason): void;
    public function isFulfilled(): bool;
}
```

#### BasketSnapshot (Value Object)
**Location:** `src/Component/ValueObject/BasketSnapshot.php`

```php
final class BasketSnapshot
{
    // Immutable - no setters!
    private array $items;
    private array $discounts;
    private float $totalGross;
    private float $totalNet;
    private float $totalVat;
    private string $currency;
    private \DateTime $capturedAt;

    // Factory method
    public static function fromOxidBasket(Basket $basket): self;

    // Conversion
    public function toArray(): array;  // For JSON storage
    public static function fromArray(array $data): self;
}
```

**Why Value Object?**
- Immutable: Basket data cannot change after contract creation
- Type-safe: Enforces structure
- Reusable: Easy to serialize/deserialize

---

## 1. Event Layer (Enhanced with Contract Events)

### Responsibilities
- Define domain events representing contract lifecycle operations
- Dispatch events to registered handlers
- Carry event context (cached request data)
- Enable loose coupling between components

### Contract-Specific Events

**Location:** `src/Event/Contract/`

| Event | Emitted By | Purpose | Listeners |
|-------|-----------|---------|-----------|
| `ContractCreatedEvent` | PaymentInitiationHandler | Contract created (DRAFT) | ContractConditionResolverHandler |
| `ContractTransitionedToPendingEvent` | PaymentContract | Conditions being resolved | PaymentAuthorizationHandler, FraudCheckHandler |
| `ContractConditionFulfilledEvent` | PaymentContract | Single condition fulfilled | ContractStateMonitor |
| `ContractReadyToCommitEvent` | PaymentContract | All conditions met | OrderCreationHandler |
| `ContractCommittedEvent` | PaymentContract | Order created | PaymentOrderStateHandler |
| `ContractFulfilledEvent` | PaymentContract | Payment captured | OrderCompletionHandler, EmailHandler |
| `ContractCancelledEvent` | PaymentContract | User/system cancelled | CleanupHandler |
| `ContractExpiredEvent` | CronJob | Timeout reached | CleanupHandler |
| `ContractFailedEvent` | ConditionHandler | Condition failed | RollbackHandler, NotificationHandler |

### Event Context (Enhanced)
**Location:** `src/Event/EventContext.php`

```php
class EventContext
{
    private Basket $basket;
    private User $user;
    private Session $session;
    private array $requestData;
    private ?PaymentContract $contract = null;  // NEW!

    // Cached data accessible by all event handlers
    public function getBasket(): Basket;
    public function getUser(): User;
    public function getContract(): ?PaymentContract;  // NEW!
    public function setContract(PaymentContract $contract): void;  // NEW!
}
```

**Enhancement:** Contract is cached in context for handlers to access without DB queries.

---

## 2. Presentation Layer (Contract-Aware Controllers)

### NEW Responsibilities (Event-Driven + Contract-Aware)
- **Validate & sanitize** user input
- **Enforce security**: authentication, authorization, CSRF
- **Cache request data** (basket, user, session)
- **Emit domain events** with validated data (ContractCreated, PaymentInitiated, etc.)
- **Return responses** based on contract/event outcomes
- **NO business logic** - just thin coordination

### Components

#### Controllers (Event Emitters)
**Location:** `src/Controller/`

| Controller | Purpose | Contract Interaction |
|------------|---------|---------------------|
| `PaymentController` | Validate payment selection, emit event | Retrieves contract by ID |
| `OrderController` | Validate order, emit PaymentInitiatedEvent | Creates contract |
| `WebhookController` | Validate signature, emit WebhookReceivedEvent | Finds contract by provider order ID |
| `ContractStatusController` (NEW) | Check contract status (API) | Queries contract |
| `Admin/ContractController` (NEW) | Manage pending contracts | CRUD operations |

#### Contract-Aware Payment Initiation Pattern

```php
class OrderController
{
    public function execute(Request $request): Response
    {
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

        // 3. Emit event (contract will be created by handler)
        $event = new PaymentInitiatedEvent($context);
        $this->dispatcher->dispatch($event);

        // 4. Check if contract was created
        if ($contract = $context->getContract()) {
            // Return contract ID to frontend (for status polling)
            return $this->json([
                'contractId' => $contract->getId(),
                'providerRedirectUrl' => $contract->getProviderRedirectUrl(),
                'status' => $contract->getState()
            ]);
        }

        return $this->error('Payment initiation failed');
    }
}
```

**Key Changes from Old Pattern:**
- ❌ OLD: `$order = $this->orderManager->createOrder()`
- ✅ NEW: Emit event, handler creates contract, order created later

#### Webhook Processing (Contract Lookup)

```php
class WebhookController
{
    public function handleRequest(Request $request): Response
    {
        // 1. Validate webhook signature
        $signature = $request->headers->get('X-Provider-Signature');
        if (!$this->webhookVerifier->verify($request->getContent(), $signature)) {
            return $this->error('Invalid signature', 401);
        }

        // 2. Parse webhook payload
        $payload = json_decode($request->getContent(), true);
        $providerOrderId = $payload['order_id'] ?? null;

        // 3. Find contract by provider order ID
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if (!$contract) {
            return $this->error('Contract not found', 404);
        }

        // 4. Emit webhook event with contract
        $event = new WebhookReceivedEvent($payload, $contract);
        $this->dispatcher->dispatch($event);

        return $this->json(['status' => 'received'], 200);
    }
}
```

**Pattern:** Webhooks always look up contract first, then emit event.

---

## 3. Event Handlers & Subscribers (Contract Lifecycle Management)

### Responsibilities
- **Primary business logic layer** for contract lifecycle
- Listen to domain events
- Execute workflows (contract creation, condition resolution, order creation)
- Call services to perform operations
- Access cached request data via EventContext
- Update contract state and emit new events

### Contract Lifecycle Handlers

**Location:** `src/EventHandler/Contract/`

| Handler | Listens To | Purpose | Contract Action |
|---------|-----------|---------|-----------------|
| `ContractCreationHandler` | PaymentInitiatedEvent | Create contract from basket | NEW Contract (DRAFT) |
| `ContractConditionResolverHandler` | ContractCreatedEvent | Start condition resolution | Transition to PENDING |
| `PaymentAuthorizationHandler` | ContractTransitionedToPendingEvent | Authorize payment | Fulfill payment_authorized |
| `FraudCheckHandler` | ContractTransitionedToPendingEvent | Run fraud checks | Fulfill fraud_check |
| `StockReservationHandler` | ContractTransitionedToPendingEvent | Reserve inventory | Fulfill stock_reserved |
| `OrderCreationHandler` | ContractReadyToCommitEvent | Create OXID order | Commit contract (link order) |
| `PaymentCaptureHandler` | WebhookReceivedEvent | Process payment capture | Fulfill contract |
| `OrderCompletionHandler` | ContractFulfilledEvent | Finalize order | Mark order OK |
| `ContractCleanupHandler` | ContractCancelledEvent, ContractExpiredEvent | Clean up resources | Archive contract |

### Handler Implementation Pattern

```php
class ContractCreationHandler
{
    public function handle(PaymentInitiatedEvent $event): void
    {
        $context = $event->getContext();

        // 1. Create basket snapshot (immutable)
        $basketSnapshot = BasketSnapshot::fromOxidBasket($context->getBasket());

        // 2. Create contract (DRAFT state)
        $contract = new PaymentContract(
            shopId: $this->config->getShopId(),
            userId: $context->getUser()->getId(),
            basketSnapshot: $basketSnapshot,
            state: PaymentContract::STATE_DRAFT
        );

        // 3. Define conditions (what must be fulfilled)
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED
        ));
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_FRAUD_CHECK
        ));
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_STOCK_RESERVED
        ));

        // 4. Save contract
        $this->contractRepository->save($contract);

        // 5. Store contract in context (for other handlers)
        $context->setContract($contract);

        // 6. Emit event (triggers condition resolution)
        $this->dispatcher->dispatch(new ContractCreatedEvent($contract));
    }
}
```

### Parallel Condition Resolution Pattern

```php
class PaymentAuthorizationHandler
{
    public function handle(ContractTransitionedToPendingEvent $event): void
    {
        $contract = $event->getContract();

        try {
            // Call payment provider via adapter
            $authResponse = $this->paymentService->authorizePayment(
                amount: $contract->getBasketSnapshot()->getTotalGross(),
                currency: $contract->getBasketSnapshot()->getCurrency(),
                returnUrl: $this->buildReturnUrl($contract)
            );

            if ($authResponse->isSuccessful()) {
                // Fulfill condition
                $contract->fulfillCondition(
                    type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                    data: [
                        'authorizationId' => $authResponse->getAuthorizationId(),
                        'providerOrderId' => $authResponse->getProviderOrderId()
                    ]
                );

                // Set provider info on contract
                $contract->setProvider(
                    provider: $authResponse->getProvider(),
                    providerOrderId: $authResponse->getProviderOrderId()
                );

                $this->contractRepository->save($contract);

                // Check if all conditions fulfilled
                if ($contract->areAllConditionsFulfilled()) {
                    $this->dispatcher->dispatch(
                        new ContractReadyToCommitEvent($contract)
                    );
                }
            } else {
                // Fail condition
                $contract->failCondition(
                    type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                    reason: $authResponse->getErrorMessage()
                );

                $this->dispatcher->dispatch(new ContractFailedEvent($contract));
            }

        } catch (\Exception $e) {
            $this->logger->error('Payment authorization failed', [
                'contractId' => $contract->getId(),
                'error' => $e->getMessage()
            ]);

            $contract->fail('Payment authorization error: ' . $e->getMessage());
            $this->dispatcher->dispatch(new ContractFailedEvent($contract));
        }
    }
}
```

**Pattern Highlights:**
- Handlers run in parallel (payment auth, fraud check, stock reservation)
- Each fulfills one condition
- Contract checks if ALL conditions fulfilled
- If yes, emit ContractReadyToCommitEvent

### Order Creation from Contract Pattern

```php
class OrderCreationHandler
{
    public function handle(ContractReadyToCommitEvent $event): void
    {
        $contract = $event->getContract();

        // All conditions verified → Safe to create order
        $order = $this->orderFactory->createFromContract($contract);
        $order->setState(Order::ORDER_STATE_NOT_FINISHED);
        $order->setOrderNumber($this->getNextOrderNumber());  // No gaps!
        $order->save();

        // Link contract to order
        $contract->commitToOrder($order->getId());
        $this->contractRepository->save($contract);

        // Create PaymentOrderState (links both contract + order)
        $orderState = new PaymentOrderState(
            orderId: $order->getId(),
            contractId: $contract->getId(),  // NEW!
            paymentState: PaymentOrderState::STATE_PAYMENT_IN_PROGRESS
        );
        $this->orderStateRepository->save($orderState);

        // Emit event
        $this->dispatcher->dispatch(
            new ContractCommittedEvent($contract, $order)
        );
    }
}
```

**Critical:** Order number assigned HERE, not earlier. No gaps for failed payments!

### Payment Capture from Webhook Pattern

```php
class PaymentCaptureHandler
{
    public function handle(WebhookReceivedEvent $event): void
    {
        $contract = $event->getContract();
        $payload = $event->getPayload();

        // Validate payment status from provider
        if ($payload['status'] !== 'captured') {
            $this->logger->warning('Webhook received but payment not captured', [
                'contractId' => $contract->getId(),
                'status' => $payload['status']
            ]);
            return;
        }

        // Fulfill contract
        $contract->fulfill();
        $this->contractRepository->save($contract);

        // Update order
        $order = $this->orderRepository->find($contract->getOrderId());
        $order->markOrderPaid();
        $order->setState(Order::ORDER_STATE_OK);
        $order->save();

        // Update order state
        $orderState = $this->orderStateRepository->findByOrderId($order->getId());
        $orderState->markAsCompleted();
        $this->orderStateRepository->save($orderState);

        // Create transaction record
        $transaction = new PaymentTransaction(
            shopId: $order->getShopId(),
            orderId: $order->getId(),
            contractId: $contract->getId(),  // NEW!
            provider: $contract->getProvider(),
            providerOrderId: $contract->getProviderOrderId(),
            type: 'capture',
            status: 'completed',
            amount: $contract->getBasketSnapshot()->getTotalGross(),
            currency: $contract->getBasketSnapshot()->getCurrency()
        );
        $this->transactionRepository->save($transaction);

        // Emit completion event
        $this->dispatcher->dispatch(
            new ContractFulfilledEvent($contract, $order)
        );
    }
}
```

---

## 4. Service Layer (Contract-Aware Services)

### NEW Responsibilities
- Implement reusable business operations **with contract awareness**
- Called by event handlers (not controllers!)
- Integrate with external APIs via SDK-Adapter
- Enforce business rules
- Stateless operations

### Contract-Aware Services

**Location:** `src/Service/`

| Service | Purpose | Contract Interaction |
|---------|---------|---------------------|
| `ContractService` | Contract CRUD, state management | Primary contract operations |
| `PaymentService` | Provider API operations | Uses contract data for payment |
| `AuthorizationService` | Two-step auth/capture | Links authorization to contract |
| `IdempotencyService` | Duplicate prevention | Uses contract ID for key |
| `VaultingService` | Save payment methods | Associates with contract user |
| `OrderFactory` | Create orders | Builds order from contract snapshot |
| `OrderManager` | Order lifecycle | Reads contract for context |

### ContractService Implementation

```php
class ContractService
{
    public function createContract(
        string $userId,
        Basket $basket,
        array $conditions = []
    ): PaymentContract {
        $basketSnapshot = BasketSnapshot::fromOxidBasket($basket);

        $contract = new PaymentContract(
            shopId: $this->config->getShopId(),
            userId: $userId,
            basketSnapshot: $basketSnapshot
        );

        // Add default conditions if not provided
        if (empty($conditions)) {
            $conditions = [
                ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                ContractCondition::TYPE_FRAUD_CHECK,
            ];
        }

        foreach ($conditions as $conditionType) {
            $contract->addCondition(new ContractCondition($conditionType));
        }

        $this->contractRepository->save($contract);

        return $contract;
    }

    public function findActiveContractByUser(string $userId): ?PaymentContract
    {
        return $this->contractRepository->findOneBy([
            'userId' => $userId,
            'state' => [
                PaymentContract::STATE_PENDING,
                PaymentContract::STATE_READY_TO_COMMIT,
                PaymentContract::STATE_COMMITTED
            ]
        ]);
    }

    public function cleanupExpiredContracts(): int
    {
        $expired = $this->contractRepository->findExpired();
        $count = 0;

        foreach ($expired as $contract) {
            $contract->expire();
            $this->contractRepository->save($contract);
            $count++;
        }

        return $count;
    }
}
```

### OrderFactory (Create from Contract)

```php
class OrderFactory
{
    public function createFromContract(PaymentContract $contract): Order
    {
        $snapshot = $contract->getBasketSnapshot();

        $order = new Order();

        // Map basket snapshot to order
        $order->setUserId($contract->getUserId());
        $order->setTotalAmount($snapshot->getTotalGross());
        $order->setNetAmount($snapshot->getTotalNet());
        $order->setVatAmount($snapshot->getTotalVat());
        $order->setCurrency($snapshot->getCurrency());

        // Map items
        foreach ($snapshot->getItems() as $item) {
            $orderArticle = new OrderArticle();
            $orderArticle->setArticleId($item['articleId']);
            $orderArticle->setTitle($item['title']);
            $orderArticle->setAmount($item['amount']);
            $orderArticle->setPrice($item['price']);
            $orderArticle->setVat($item['vat']);
            $order->addArticle($orderArticle);
        }

        // Map discounts
        foreach ($snapshot->getDiscounts() as $discount) {
            $order->addDiscount($discount);
        }

        return $order;
    }
}
```

---

## 5. SDK-Adapter Layer (Provider Contract Mapping)

### NEW Responsibilities
- Map **provider contract patterns** to **our contract pattern**
- Translate provider states to contract states
- Handle provider-specific contract IDs (PaymentIntent ID, Order ID, etc.)

### Provider Contract State Mapping

```php
class StripeAdapter implements PaymentAdapterInterface
{
    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        // Create Stripe PaymentIntent (their "contract")
        $paymentIntent = $this->client->paymentIntents->create([
            'amount' => $this->convertAmountToCents($request->getAmount()),
            'currency' => strtolower($request->getCurrency()),
            'capture_method' => 'manual',  // Two-step = contract pattern
            'metadata' => [
                'contract_id' => $request->getContractId()  // NEW!
            ],
        ]);

        // Map Stripe state to our contract semantics
        return new PaymentResponse(
            providerPaymentId: $paymentIntent->id,  // Stripe's contract ID
            status: $this->mapStripeStatusToContractStatus($paymentIntent->status),
            amount: $this->convertCentsToAmount($paymentIntent->amount),
            currency: strtoupper($paymentIntent->currency),
            clientSecret: $paymentIntent->client_secret,
            requiresAction: $paymentIntent->status === 'requires_action'
        );
    }

    private function mapStripeStatusToContractStatus(string $stripeStatus): string
    {
        return match($stripeStatus) {
            'requires_confirmation' => 'pending',  // Contract PENDING
            'requires_action' => 'pending',  // Contract PENDING (3DS)
            'requires_capture' => 'ready_to_commit',  // Contract READY_TO_COMMIT
            'succeeded' => 'fulfilled',  // Contract FULFILLED
            'canceled' => 'cancelled',  // Contract CANCELLED
            default => 'unknown',
        };
    }
}
```

**Pattern:** Provider states map to contract states, making the abstraction explicit.

---

## 6. Data Layer (Contract-Enhanced Schema)

### Responsibilities
- Persist contracts, conditions, basket snapshots
- Maintain FK references to OXID core (NO ALTER TABLE)
- Efficient queries for contract lookup
- Cache contract state

### Key Tables (Contract-Aware)

**Primary:**
- `osc_payment_contract` - Master contract table (NEW)
  - Stores: state, basket snapshot (JSON), conditions (JSON), provider order ID
  - FK to oxuser (OXUSERID)
  - FK to oxorder (OXORDERID) - **NULL until committed!**

**Enhanced:**
- `osc_payment_order_state` - Enhanced with OXCONTRACTID FK
- `osc_payment_transaction` - Enhanced with OXCONTRACTID FK

**See:** [02-database-and-models.md](02-database-and-models.md) for complete schema.

### Repository Patterns

```php
class ContractRepository
{
    public function findByProviderOrderId(string $providerOrderId): ?PaymentContract
    {
        // Fast lookup for webhook processing
        $data = $this->connection->fetchAssociative(
            "SELECT * FROM osc_payment_contract WHERE OXPROVIDERORDERID = ?",
            [$providerOrderId]
        );

        return $data ? $this->hydrate($data) : null;
    }

    public function findExpired(\DateTime $before = null): array
    {
        $before = $before ?? new \DateTime();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM osc_payment_contract
             WHERE OXEXPIRESAT < ?
             AND OXSTATE NOT IN (?, ?, ?)",
            [
                $before->format('Y-m-d H:i:s'),
                PaymentContract::STATE_FULFILLED,
                PaymentContract::STATE_CANCELLED,
                PaymentContract::STATE_EXPIRED,
            ]
        );

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }
}
```

---

## Contract Lifecycle: Complete Flow

### Phase 1: Contract Creation

```
OrderController::execute()
  → Validate input, cache data
  → Emit PaymentInitiatedEvent

PaymentInitiationHandler::handle()
  → Create PaymentContract (DRAFT)
  → Add conditions (payment_authorized, fraud_check, stock_reserved)
  → Save contract
  → Emit ContractCreatedEvent

Contract state: DRAFT
```

### Phase 2: Transition to Pending

```
ContractConditionResolverHandler::handle()
  → Validate conditions defined
  → Transition contract to PENDING
  → Emit ContractTransitionedToPendingEvent

Contract state: DRAFT → PENDING
```

### Phase 3: Condition Resolution (Parallel)

```
PaymentAuthorizationHandler::handle()
  → Authorize payment via adapter
  → Contract.fulfillCondition('payment_authorized')

FraudCheckHandler::handle()
  → Run fraud check
  → Contract.fulfillCondition('fraud_check')

StockReservationHandler::handle()
  → Reserve inventory
  → Contract.fulfillCondition('stock_reserved')

Contract monitors: All conditions fulfilled?
  → YES: Emit ContractReadyToCommitEvent

Contract state: PENDING → READY_TO_COMMIT
```

### Phase 4: Order Creation

```
OrderCreationHandler::handle()
  → Create OXID order from contract snapshot
  → Assign order number (NO GAPS!)
  → Contract.commitToOrder(order.getId())
  → Create PaymentOrderState (link contract + order)
  → Emit ContractCommittedEvent

Contract state: READY_TO_COMMIT → COMMITTED
Order state: NOT_FINISHED
```

### Phase 5: Payment Capture (Webhook)

```
WebhookController::handleRequest()
  → Validate signature
  → Find contract by provider order ID
  → Emit WebhookReceivedEvent

PaymentCaptureHandler::handle()
  → Validate payment captured
  → Contract.fulfill()
  → Order.markOrderPaid()
  → Order.setState(OK)
  → Create transaction record
  → Emit ContractFulfilledEvent

Contract state: COMMITTED → FULFILLED
Order state: NOT_FINISHED → OK
```

---

## Testing Strategy (Contract-Focused)

### Unit Tests (Pure Domain Logic)

```php
public function testContractLifecycle()
{
    // No database required!
    $contract = new PaymentContract($shopId, $userId, $basketSnapshot);
    $contract->addCondition(new ContractCondition('payment_authorized'));

    $this->assertEquals(PaymentContract::STATE_DRAFT, $contract->getState());

    $contract->transitionToPending();
    $this->assertEquals(PaymentContract::STATE_PENDING, $contract->getState());

    $contract->fulfillCondition('payment_authorized', ['auth_id' => '123']);
    $this->assertTrue($contract->areAllConditionsFulfilled());
}
```

### Integration Tests

```php
public function testContractToOrderFlow()
{
    // Create contract
    $contract = $this->contractService->createContract($userId, $basket);

    // Fulfill conditions
    $contract->fulfillCondition('payment_authorized');
    $contract->fulfillCondition('fraud_check');
    $this->contractRepository->save($contract);

    // Create order from contract
    $order = $this->orderFactory->createFromContract($contract);
    $order->save();

    $contract->commitToOrder($order->getId());
    $this->contractRepository->save($contract);

    // Verify linkage
    $this->assertEquals($order->getId(), $contract->getOrderId());
    $this->assertEquals(PaymentContract::STATE_COMMITTED, $contract->getState());
}
```

---

## Performance Characteristics

### Contract Operations Overhead

| Operation | Time | Notes |
|-----------|------|-------|
| Contract creation | ~50ms | JSON storage, condition setup |
| Condition fulfillment | ~10ms | JSON update, state check |
| All conditions check | <1ms | In-memory array iteration |
| Order creation from contract | ~100ms | Standard OXID order creation |
| **Total overhead vs. old** | **+50ms** | Acceptable for better architecture |

### Optimization Strategies

- Redis caching for active contracts (PENDING, COMMITTED)
- Indexed queries on OXSTATE, OXPROVIDERORDERID
- Lazy-loading of basket snapshot (only when needed)
- Async condition processing where possible

---

## Summary: Layer Reusability (Contract-Enhanced)

| Layer | Reusability | Contract Enhancements |
|-------|-------------|----------------------|
| Presentation | 70-90% | Contract status endpoints, webhook lookup |
| Event Layer | 100% | Contract-specific events |
| Event Handlers | 90-100% | Contract lifecycle management |
| Contract Domain | 100% | NEW - fully reusable |
| Service Layer | 95-100% | Contract-aware operations |
| SDK-Adapter | 100% (pattern) | Provider contract state mapping |
| Data Access | 100% | Contract repository, FK references |
| Infrastructure | 100% | Standard PSR interfaces |

---

## Key Takeaways

✅ **Contract-First Architecture**: Payment contracts manage lifecycle before order creation
✅ **Event-Driven**: All operations triggered by domain events
✅ **Explicit Conditions**: Tracked in database, not hidden in code
✅ **Provider Alignment**: Maps to how Stripe, PayPal, etc. work internally
✅ **Clean Separation**: Payment domain vs. order domain
✅ **DDD-Compliant**: Aggregate roots, entities, value objects
✅ **Testable**: Pure domain logic, no framework dependencies
✅ **OXID-Compatible**: NO ALTER TABLE on core, FK references only

---

**Continue to:** [02-database-and-models.md](02-database-and-models.md)
