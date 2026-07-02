# Payment-Component Dependency

The stripe module depends on **payment-component** (`OxidEsales\PaymentComponent\`) — a provider-agnostic library that defines all interfaces, domain models, events, and database schema. This document maps what payment-component provides and how stripe implements it.

For architecture details, see [`docs/architecture/00-overview.md`](../architecture/00-overview.md).

---

## 1. What Payment-Component Provides

### Interfaces

| Category | Interface | Purpose |
|----------|-----------|---------|
| Adapter | `PaymentAdapterInterface` | Provider abstraction for payment operations |
| Adapter | `ShopAdapterInterface` | Shop platform abstraction (config, URLs) |
| Adapter | `SessionAdapterInterface` | Session management abstraction |
| Adapter | `ShopOrderServiceInterface` | Order creation/finalization abstraction |
| Repository | `ContractRepositoryInterface` | Contract CRUD operations |
| Repository | `TransactionRepositoryInterface` | Transaction CRUD operations |
| Repository | `PaymentCustomerRepositoryInterface` | Customer payment data storage |
| Repository | `IdempotencyRepositoryInterface` | Duplicate charge prevention |
| Repository | `WebhookLogRepositoryInterface` | Webhook event logging |
| Service | `ContractServiceInterface` | Contract lifecycle operations |
| Service | `ContractFulfillmentServiceInterface` | Contract fulfillment workflow |
| Service | `ContractMetadataServiceInterface` | Contract metadata read/write |
| Service | `OrderPaymentStateServiceInterface` | Order payment state tracking |
| Service | `TokenServiceInterface` | Contract token generation/validation |
| Service | `ReturnSecurityValidatorInterface` | Return URL security validation |
| Service | `FraudCheckServiceInterface` | Fraud check abstraction |
| Service | `StockRestorationServiceInterface` | Stock restoration on cancellation |
| Service | `FileLoggerInterface` | File-based logging abstraction |
| Service | `PaymentAdapterFactoryInterface` | Creates payment adapter instances |
| Event | `EventDispatcherInterface` | PSR-14 compatible event dispatching |
| Event | `EventListenerProviderInterface` | Collects tagged event handlers |
| Event | `EventContextInterface` | Request-scoped data passing between handlers |
| Event | `HandlerInterface` | Event handler contract |
| Webhook | `WebhookProcessorInterface` | Webhook parsing and dispatch |
| Webhook | `WebhookEventHandlerInterface` | Individual webhook event handling |

### Domain Models

| Model | Purpose |
|-------|---------|
| `PaymentContract` / `PaymentContractInterface` | Aggregate root — state machine for payment lifecycle |
| `ContractCondition` | Condition that must be fulfilled before commitment |
| `ContractState` | Enum of valid contract states |
| `BasketSnapshot` | Immutable copy of basket at contract creation |
| `Transaction` / `TransactionInterface` | Payment transaction record |
| `PaymentCustomer` | Customer payment data (vaulting) |
| `IdempotencyRecord` | Duplicate charge prevention record |

### Abstract Base Classes

| Class | Purpose | Stripe Extends? |
|-------|---------|-----------------|
| `ContractCreationHandler` | Template method for contract creation | Yes — `StripeContractCreationHandler` |
| `AbstractPaymentCaptureService` | Base capture logic | Yes — `StripeCaptureService` |
| `AbstractPaymentRefundService` | Base refund logic | Yes — `StripeRefundService` |
| `AbstractWebhookProcessor` | Base webhook processing | Yes — `StripeWebhookProcessor` |
| `AbstractHandler` | Base handler with repo + dispatcher injection | Some handlers |
| `AbstractFileLoggerFactory` | Base file logger factory | Yes — 4 logger factories |

### Events (30 total)

**Contract events** (in `EventSystem/Event/Contract/`):

| Event | When Dispatched |
|-------|-----------------|
| `ContractCreatedEvent` | Contract instantiated |
| `ContractDraftCompletedEvent` | Draft phase complete, ready for order creation |
| `ContractTransitionedToPendingEvent` | Contract moved to PENDING |
| `ContractConditionFulfilledEvent` | A single condition was fulfilled |
| `ContractReadyToCommitEvent` | All conditions met |
| `ContractCommittedEvent` | Order finalized |
| `ContractFulfilledEvent` | Payment captured, terminal success |
| `ContractCancelledEvent` | Contract cancelled |
| `ContractExpiredEvent` | Contract expired (timeout) |
| `ContractFailedEvent` | Contract failed (unrecoverable) |

**Payment events** (in `EventSystem/Event/Payment/`):

| Event | When Dispatched |
|-------|-----------------|
| `PaymentInitiatedEvent` | Payment process started |
| `PaymentAuthorizedEvent` | Payment authorized by provider |
| `PaymentCapturedEvent` | Payment captured (money moved) |
| `PaymentRefundedEvent` | Payment refunded |
| `PaymentFailedEvent` | Payment failed |
| `WebhookReceivedEvent` | Webhook received from provider |
| `OrderCreatedEvent` | OXID order created |
| `OrderCompletedEvent` | OXID order fully completed |

Each event has a corresponding interface (e.g., `ContractReadyToCommitEventInterface`).

---

## 2. Interface-to-Implementation Mapping

How stripe implements payment-component interfaces:

| payment-component Interface | stripe Implementation |
|-----------------------------|----------------------|
| `PaymentAdapterInterface` | `StripeAdapter` (via `LazyStripeAdapter` proxy) |
| `ShopAdapterInterface` | `OxidShopAdapter` |
| `SessionAdapterInterface` | `OxidSessionAdapter` |
| `ShopOrderServiceInterface` | `OxidShopOrderService` |
| `PaymentAdapterFactoryInterface` | `StripeAdapterFactory` |
| `FraudCheckServiceInterface` | `StripeRadarFraudCheckService` |
| `StockRestorationServiceInterface` | `OxidStockRestorationService` |
| `ReturnSecurityValidatorInterface` | `ReturnSessionSecurityService` |
| `TokenServiceInterface` | `ContractTokenService` |
| `ContractMetadataServiceInterface` | `ContractMetadataService` (from payment-component) |

**Repository implementations** (from payment-component, wired by stripe):

| Interface | Implementation |
|-----------|---------------|
| `ContractRepositoryInterface` | `DoctrineContractRepository` |
| `TransactionRepositoryInterface` | `DoctrineTransactionRepository` |
| `PaymentCustomerRepositoryInterface` | `DoctrinePaymentCustomerRepository` |
| `IdempotencyRepositoryInterface` | `DoctrineIdempotencyRepository` |
| `WebhookLogRepositoryInterface` | `DoctrineWebhookLogRepository` |

---

## 3. services.yaml Wiring

The stripe module's `services.yaml` is the central wiring point. It binds payment-component interfaces to concrete implementations and registers all event handlers.

### Interface Aliases

```yaml
# Payment-component interfaces → concrete implementations
OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface:
    class: OxidEsales\PaymentComponent\Repository\DoctrineContractRepository
    public: true

OxidEsales\PaymentComponent\Service\ContractServiceInterface:
    class: OxidEsales\PaymentComponent\Service\ContractService
    public: true

# Stripe-specific interfaces → implementations
OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface:
    class: OxidEsales\Payments\Stripe\Service\CheckoutSessionService
```

### Handler Registration

All handlers (both payment-component and stripe) are tagged with `payment.event_handler`:

```yaml
# Stripe handler — priority in tag
OxidEsales\Payments\Stripe\EventSystem\Handler\StripeContractCreationHandler:
    tags:
        - { name: payment.event_handler, priority: 100 }

# Payment-component handler — registered by stripe's services.yaml
OxidEsales\PaymentComponent\EventSystem\Handler\EarlyOrderCreationHandler:
    tags:
        - { name: payment.event_handler, priority: 100 }

OxidEsales\PaymentComponent\EventSystem\Handler\PaymentAuthorizedEventHandler:
    tags:
        - { name: payment.event_handler, priority: 90 }

OxidEsales\PaymentComponent\EventSystem\Handler\FraudCheckHandler:
    tags:
        - { name: payment.event_handler, priority: 85 }
```

### EventListenerProvider Collects Tagged Handlers

```yaml
OxidEsales\PaymentComponent\EventSystem\EventListenerProviderInterface:
    class: OxidEsales\PaymentComponent\EventSystem\EventListenerProvider
    arguments:
        - !tagged_iterator payment.event_handler
    public: true
```

### Factory-Created Services

```yaml
# Stripe SDK client — created via factory
stripe.payment.adapter.client:
    class: Stripe\StripeClient
    factory: ['@OxidEsales\Payments\Stripe\Adapter\StripeClientFactory', 'create']

# File loggers — each with its own factory
stripe.events.file_logger:
    class: OxidEsales\PaymentComponent\Service\FileLoggerInterface
    factory: ['@OxidEsales\Payments\Stripe\Service\Factory\EventFileLoggerFactory', 'create']
```

### Module Parameters

```yaml
parameters:
    payment.fraud_check.enabled: true
    payment.fraud_check.threshold: 0.7
```

---

## 4. Database Schema

All 6 tables are created and owned by payment-component. The stripe module has **zero** migrations.

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `oe_payments_contract` | Contract lifecycle | OXID, OXSHOPID, OXUSERID, OXORDERID, OXSTATE, OXBASKETDATA (JSON), OXCAPTUREDAMOUNT, OXREFUNDEDAMOUNT, OXMETADATA (JSON) |
| `oe_payments_transaction` | Transaction records | OXID, OXCONTRACTID (FK), OXTRANSACTIONID, OXTYPE, OXAMOUNT, OXSTATUS |
| `oe_payments_customer` | Customer payment data | OXID, OXUSERID, OXPROVIDERID, OXCUSTOMERDATA (JSON) |
| `oe_payments_idempotency` | Duplicate charge prevention | OXID, OXKEY (UNIQUE), OXCONTRACTID, OXSTATUS |
| `oe_payments_sessions` | Session state management | OXID, OXSESSIONID, OXCONTRACTID, OXDATA (JSON) |
| `oe_payments_webhooklogs` | Webhook event logs | OXID, OXEVENTTYPE, OXEVENTID, OXPAYLOAD, OXSTATUS |

### Contract Metadata (OXMETADATA column)

The `OXMETADATA` column on `oe_payments_contract` is a JSON field for arbitrary key-value data. It is accessed via `PaymentContractInterface::setMetadata()` / `getMetadata()`:

```php
$contract->setMetadata('delivery_address_hash', $hash);
$contract->setMetadata('subscription_id', 'sub_abc123');
$value = $contract->getMetadata('subscription_id'); // 'sub_abc123'
```

This is the primary extension point for storing custom data on contracts without schema changes. See [Pattern 3 in the extension guide](03-extending-the-stripe-module.md#pattern-3-contract-metadata).

### Running Migrations

Migrations are run from the payment-component package:

```bash
docker compose exec php php vendor/bin/doctrine-migrations migrate \
    --configuration=extensions/payment-component/migration/migrations.yml \
    --db-configuration=extensions/payment-component/migration/migrations-db.php \
    --no-interaction
```

---

## 5. ContractCondition Extensibility

### The Type Whitelist

`ContractCondition` enforces a strict type whitelist via `validateType()`:

```php
// payment-component/src/Contract/ContractCondition.php (lines 107-119)

private function validateType(string $type): void
{
    $validTypes = [
        self::TYPE_PAYMENT_AUTHORIZED,  // 'payment_authorized'
        self::TYPE_FRAUD_CHECK,         // 'fraud_check'
        self::TYPE_COMPLIANCE_CHECK,    // 'compliance_check'
        self::TYPE_ADDRESS_VALIDATED,   // 'address_validated'
    ];

    if (!in_array($type, $validTypes, true)) {
        throw new InvalidArgumentException("Invalid condition type: {$type}");
    }
}
```

### Currently Used Conditions

| Type | Constant | Used By |
|------|----------|---------|
| `payment_authorized` | `TYPE_PAYMENT_AUTHORIZED` | `PaymentAuthorizedEventHandler` — fulfilled when Stripe confirms payment |
| `fraud_check` | `TYPE_FRAUD_CHECK` | `FraudCheckHandler` — fulfilled when Stripe Radar passes |
| `compliance_check` | `TYPE_COMPLIANCE_CHECK` | Reserved — not currently used |
| `address_validated` | `TYPE_ADDRESS_VALIDATED` | Reserved — not currently used |

### Factory Methods

```php
ContractCondition::paymentAuthorized();  // Creates TYPE_PAYMENT_AUTHORIZED
ContractCondition::fraudCheck();         // Creates TYPE_FRAUD_CHECK
ContractCondition::complianceCheck();    // Creates TYPE_COMPLIANCE_CHECK
ContractCondition::addressValidated();   // Creates TYPE_ADDRESS_VALIDATED
```

### Impact for Custom Condition Types

You **cannot** add custom condition types (e.g., `subscription_validated`) without modifying payment-component's whitelist. The `validateType()` method will throw `InvalidArgumentException` for unknown types.

**Workaround:** Use the contract's **metadata** field instead. Store your custom condition state as metadata keys and check them in your handler:

```php
// Instead of: $contract->addCondition(new ContractCondition('subscription_validated'));
// Do this:
$contract->setMetadata('subscription_validated', true);
$contract->setMetadata('subscription_validated_at', time());
```

If you need a true condition type, you would need to contribute it upstream to payment-component or fork the `ContractCondition` class. The two reserved types (`compliance_check`, `address_validated`) may be available for your use case.

---

## 6. Namespace Conventions

### payment-component

```
OxidEsales\PaymentComponent\
├── Adapter\          # Provider abstraction interfaces + request/response DTOs
├── Contract\         # Domain models (PaymentContract, ContractCondition, etc.)
├── EventSystem\      # Events, handlers, dispatcher
├── Model\            # Base model abstractions
├── Repository\       # Repository interfaces + Doctrine implementations
├── Service\          # Service interfaces + implementations
└── Webhook\          # Webhook processing abstractions
```

### stripe

```
OxidEsales\Payments\Stripe\
├── Adapter\          # Stripe SDK wrappers, OXID adapters
├── Command\          # CLI commands
├── Controller\       # OXID controllers (frontend + admin)
├── Core\             # Module lifecycle, ViewConfig extension
├── EventSystem\      # Stripe events + handlers
├── Model\            # OXID model extensions
├── Service\          # Business logic services
├── Traits\           # Shared traits (ServiceContainer)
├── Twig\             # Twig extensions
├── Webhook\          # Stripe webhook parsing
└── WebhookHandler\   # Individual Stripe webhook handlers
```

### Boundary Rules

1. **stripe** classes may `use` payment-component classes freely
2. **payment-component** classes must **never** reference stripe namespace
3. Interfaces live in payment-component; implementations live in whichever module is appropriate
4. Stripe-specific interfaces (e.g., `StripeAdapterInterface`, `CheckoutSessionServiceInterface`) live in the stripe module
5. All DI wiring happens in stripe's `services.yaml` — payment-component has no service container configuration
