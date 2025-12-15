# Service Catalog

**Version:** 1.0.0
**Date:** 2025-12-15
**Purpose:** Comprehensive catalog of services in the Payment Component

---

## Overview

This document catalogs all services in the Payment Component, organized by layer and responsibility. Services follow SOLID principles with clear interfaces for dependency injection.

---

## Component Layer Services (Provider-Agnostic)

These services are 100% reusable across all payment providers.

### Contract Services

| Service | Interface | Responsibility |
|---------|-----------|----------------|
| `ContractService` | `ContractServiceInterface` | Contract CRUD operations, basket snapshot creation |
| `ContractFulfillmentService` | `ContractFulfillmentServiceInterface` | Contract fulfillment logic with guards |
| `TokenService` | `TokenServiceInterface` | Contract token generation/validation |

### Order Services

| Service | Interface | Responsibility |
|---------|-----------|----------------|
| `OrderPaymentStateService` | `OrderPaymentStateServiceInterface` | OXPAID updates, transaction status (single source of truth) |
| `ShopOrderService` | `ShopOrderServiceInterface` | Order creation via `Order::finalizeOrder()` |

### Security Services

| Service | Interface | Responsibility |
|---------|-----------|----------------|
| `ReturnSecurityValidator` | `ReturnSecurityValidatorInterface` | Validates checkout return (IP, user agent, etc.) |

---

## Stripe Layer Services (Provider-Specific)

These services implement Stripe-specific business logic.

### Checkout Services (Sprint 21)

| Service | Interface | Responsibility | Tests |
|---------|-----------|----------------|-------|
| `CheckoutReturnService` | `CheckoutReturnServiceInterface` | Validates return from Stripe checkout | 14 |
| `CheckoutSessionService` | `CheckoutSessionServiceInterface` | Creates Stripe checkout sessions | 15 |

### Payment Services (Sprint 21)

| Service | Interface | Responsibility | Tests |
|---------|-----------|----------------|-------|
| `RefundService` | `RefundServiceInterface` | Processes full/partial refunds via adapter | 18 |

### Metadata Services (Sprint 21)

| Service | Interface | Responsibility | Tests |
|---------|-----------|----------------|-------|
| `ContractMetadataService` | `ContractMetadataServiceInterface` | Stores/retrieves contract metadata (address hash, security) | 14 |
| `DeliveryAddressHashService` | `DeliveryAddressHashServiceInterface` | Manages delivery address hash for OXID validation | N/A |

### Configuration Services

| Service | Interface | Responsibility |
|---------|-----------|----------------|
| `ModuleConfigurationService` | - | Module settings (API keys, mode, webhook secret) |
| `StripeAdapterFactory` | `StripeAdapterFactoryInterface` | Creates configured StripeAdapter instances |

### Webhook Services

| Service | Interface | Responsibility |
|---------|-----------|----------------|
| `WebhookProcessingService` | - | Routes webhook events, updates contracts |
| `OxpaidReconciliationService` | - | Reconciles OXPAID from Stripe for orders with empty payment dates |

---

## Adapter Layer

### StripeAdapter

| Method | Purpose |
|--------|---------|
| `retrieveCheckoutSession()` | Retrieve Stripe checkout session |
| `createCheckoutSession()` | Create Stripe checkout session |
| `retrievePaymentIntent()` | Retrieve Stripe payment intent |
| `createRefundByCharge()` | Create refund by charge ID |
| Plus all `PaymentAdapterInterface` methods | Standard payment operations |

---

## DTOs (Data Transfer Objects)

### Result DTOs (Sprint 21)

| DTO | Purpose | Pattern |
|-----|---------|---------|
| `RefundResult` | Refund operation result | Result Object |
| `CheckoutReturnResult` | Return validation result | Result Object |
| `CheckoutSessionResult` | Session creation result | Result Object |

All DTOs follow:
- Immutable `final readonly class`
- Named constructors (`success()`, `failure()`)
- Type-safe accessors

---

## Service Registration (services.yaml)

```yaml
# Component Services
OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface:
  class: OxidSolutionCatalysts\Payments\Component\Service\ContractService

OxidSolutionCatalysts\Payments\Component\Service\ContractFulfillmentServiceInterface:
  class: OxidSolutionCatalysts\Payments\Component\Service\ContractFulfillmentService

OxidSolutionCatalysts\Payments\Component\Service\OrderPaymentStateServiceInterface:
  class: OxidSolutionCatalysts\Payments\Component\Service\OrderPaymentStateService

# Stripe Services (Sprint 21)
OxidSolutionCatalysts\Payments\Stripe\Service\RefundServiceInterface:
  class: OxidSolutionCatalysts\Payments\Stripe\Service\RefundService

OxidSolutionCatalysts\Payments\Stripe\Service\CheckoutReturnServiceInterface:
  class: OxidSolutionCatalysts\Payments\Stripe\Service\CheckoutReturnService

OxidSolutionCatalysts\Payments\Stripe\Service\CheckoutSessionServiceInterface:
  class: OxidSolutionCatalysts\Payments\Stripe\Service\CheckoutSessionService

OxidSolutionCatalysts\Payments\Stripe\Service\ContractMetadataServiceInterface:
  class: OxidSolutionCatalysts\Payments\Stripe\Service\ContractMetadataService

OxidSolutionCatalysts\Payments\Stripe\Service\DeliveryAddressHashServiceInterface:
  class: OxidSolutionCatalysts\Payments\Stripe\Service\DeliveryAddressHashService
```

---

## OXPAID Update Strategy

**Single Source of Truth:** `OrderPaymentStateServiceInterface`

All OXPAID updates go through this service to ensure:
- Consistent date formatting (PHP DateTimeImmutable)
- Single update location (DRY)
- Proper timezone handling

### Service Interface

```php
interface OrderPaymentStateServiceInterface
{
    public function updatePaidTimestamp(string $orderId, ?DateTimeImmutable $paidAt = null): bool;
    public function updateTransactionStatus(string $orderId, string $status): bool;
    public function updateTransactionId(string $orderId, string $transactionId): bool;
    public function markOrderAsPaid(string $orderId, string $transactionId, ?DateTimeImmutable $paidAt = null): bool;
}
```

### Update Flow

```
[Primary Path: Checkout Return]
StripeOrderCreationHandler
    └─► OrderPaymentStateService.markOrderAsPaid()
            └─► OXPAID SET

[Backup Path: Webhook]
PaymentIntentSucceededHandler
    └─► Check: OXPAID empty?
            └─► OrderPaymentStateService.updatePaidTimestamp()
                    └─► OXPAID SET
```

---

## Contract Fulfillment Flow

**Single Source of Truth:** `ContractFulfillmentServiceInterface`

### Service Interface

```php
interface ContractFulfillmentServiceInterface
{
    public function fulfill(PaymentContractInterface $contract): bool;
    public function canFulfill(PaymentContractInterface $contract): bool;
}
```

### Flow

```
ContractFulfillmentService.fulfill($contract)
    │
    ├── canFulfill() guard check
    │   ├── Is COMMITTED? Yes → continue
    │   └── Is FULFILLED? No → continue
    │
    ├── $contract->fulfill()
    │
    ├── $contractRepository->save($contract)
    │
    └── dispatch(ContractFulfilledEvent)
```

---

## Handler Service Delegation Pattern

Event handlers follow the delegation pattern - business logic extracted to services:

```
Handler (thin)
├── Extract parameters from event
├── Delegate to Service
├── Handle result
└── Update context

Service (business logic)
├── Business logic
├── API calls via Adapter
├── Error handling
└── Return Result DTO
```

### Handler → Service Mapping

| Handler | Service | Responsibility |
|---------|---------|----------------|
| `StripeRefundRequestHandler` | `RefundService` | Refund processing |
| `StripeCheckoutReturnHandler` | `CheckoutReturnService` | Return validation |
| `StripeCheckoutSessionHandler` | `CheckoutSessionService` | Session creation |
| `StripeContractCreationHandler` | `ContractMetadataService` | Metadata operations |
| `StripeOrderCreationHandler` | `ShopOrderService`, `OrderPaymentStateService` | Order creation, OXPAID |

---

## Related Documentation

- [01-architecture-layers.md](01-architecture-layers.md) - Layer architecture
- [04-sdk-adapter-layer.md](04-sdk-adapter-layer.md) - Adapter pattern
- [Sprint 21 Report](../daniil_dev_log/20251209/done/sprint-21-refactor-fat-handlers-report.md) - Service extraction details

---

**Last Updated:** 2025-12-15
