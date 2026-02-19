# DTO Inventory Report

**Generated:** 2026-01-28

## Summary

**Total DTOs Found: 34**

| Module | Count | Location |
|--------|-------|----------|
| stripe | 7 | `src/Stripe/DTO/`, `src/Stripe/Service/Result/`, `src/Stripe/Service/` |
| payment-component | 25 | `src/Adapter/Request/`, `src/Adapter/Response/`, `src/Service/Result/`, `src/Webhook/` |
| shop-watch | 2 | `src/ValueObject/` |

---

## STRIPE MODULE (7 DTOs)

### `src/Stripe/DTO/` (5 files)

| File | Class | Purpose |
|------|-------|---------|
| `CheckoutSessionResult.php` | CheckoutSessionResult | Result of checkout session creation |
| `CheckoutReturnResult.php` | CheckoutReturnResult | Result of checkout return validation |
| `RefundResult.php` | RefundResult | Result of refund operations |
| `CaptureResult.php` | CaptureResult | Result of capture operations |
| `CancellationResult.php` | CancellationResult | Result of cancel authorization |

### `src/Stripe/Service/Result/` (1 file)

| File | Class | Purpose |
|------|-------|---------|
| `SecurityValidationResult.php` | SecurityValidationResult | Security validation check result |

### `src/Stripe/Service/` (1 file)

| File | Class | Purpose |
|------|-------|---------|
| `ReconciliationResult.php` | ReconciliationResult | Order reconciliation result |

---

## PAYMENT-COMPONENT MODULE (25 DTOs)

### `src/Adapter/Request/` (11 files)

| File | Class | Purpose |
|------|-------|---------|
| `AuthorizePaymentRequest.php` | AuthorizePaymentRequest | Request for authorizing payment (two-step) |
| `CapturePaymentRequest.php` | CapturePaymentRequest | Request for capturing authorized payment |
| `CreatePaymentRequest.php` | CreatePaymentRequest | Request for creating a payment |
| `RefundPaymentRequest.php` | RefundPaymentRequest | Request for refunding captured payment |
| `VoidPaymentRequest.php` | VoidPaymentRequest | Request for voiding authorization |
| `CreateOrderRequest.php` | CreateOrderRequest | Request for creating an order |
| `CaptureAuthorizationRequest.php` | CaptureAuthorizationRequest | Request for capturing authorization |
| `VoidAuthorizationRequest.php` | VoidAuthorizationRequest | Request for voiding authorization |
| `ReauthorizePaymentRequest.php` | ReauthorizePaymentRequest | Request for reauthorizing expired auth |
| `CreatePaymentMethodRequest.php` | CreatePaymentMethodRequest | Request for creating payment method (vaulting) |
| `ThreeDSecureRequest.php` | ThreeDSecureRequest | Request for 3D Secure / SCA |

### `src/Adapter/Response/` (9 files)

| File | Class | Purpose |
|------|-------|---------|
| `PaymentResponse.php` | PaymentResponse | Response from creating payment |
| `CaptureResponse.php` | CaptureResponse | Response from capturing payment |
| `RefundResponse.php` | RefundResponse | Response from refunding payment |
| `AuthorizationResponse.php` | AuthorizationResponse | Response from authorizing payment |
| `VoidResponse.php` | VoidResponse | Response from voiding authorization |
| `OrderResponse.php` | OrderResponse | Response with created order details |
| `PaymentMethodResponse.php` | PaymentMethodResponse | Response for saved payment method |
| `ThreeDSecureResponse.php` | ThreeDSecureResponse | Response for 3DS authentication |
| `PaymentDetailsResponse.php` | PaymentDetailsResponse | Response for payment status lookup |

### `src/Service/Result/` (3 files)

| File | Class | Purpose |
|------|-------|---------|
| `FraudCheckResult.php` | FraudCheckResult | Result of fraud check |
| `RefundResult.php` | RefundResult | Result of refund operation |
| `CaptureResult.php` | CaptureResult | Result of capture operation |

### `src/Webhook/` (2 files)

| File | Class | Purpose |
|------|-------|---------|
| `WebhookRequest.php` | WebhookRequest | Incoming webhook HTTP request |
| `WebhookResult.php` | WebhookResult | Result of webhook processing |

---

## SHOP-WATCH MODULE (2 DTOs)

### `src/ValueObject/` (2 files)

| File | Class | Purpose |
|------|-------|---------|
| `AssumptionRequest.php` | AssumptionRequest | DB field value check request |
| `AssumptionResponse.php` | AssumptionResponse | DB field value check response |

---

## DTO Categories

| Category | Count | Examples |
|----------|-------|----------|
| Result Objects | 10 | RefundResult, CaptureResult, FraudCheckResult |
| Adapter Requests | 11 | CreatePaymentRequest, RefundPaymentRequest |
| Adapter Responses | 9 | PaymentResponse, RefundResponse |
| Webhook DTOs | 2 | WebhookRequest, WebhookResult |
| Value Objects | 2 | AssumptionRequest, AssumptionResponse |

---

## Design Patterns Used

- **Immutable**: All DTOs use `readonly` classes or properties
- **Factory Methods**: Result objects use `success()`, `failure()`, `skipped()`
- **Provider-Agnostic**: Adapter Request/Response DTOs are payment provider independent
- **Early Returns**: No else statements (guard clauses)
- **Type Safety**: PHPStan level 6 compliant

---

## Observations

1. **Duplicate Names**: Both stripe and payment-component have `RefundResult` and `CaptureResult` classes
   - stripe: `OxidEsales\Payments\Stripe\DTO\RefundResult`
   - payment-component: `OxidEsales\PaymentComponent\Service\Result\RefundResult`

2. **Inconsistent Locations**:
   - stripe has DTOs in `DTO/`, `Service/Result/`, and `Service/`
   - payment-component uses `Adapter/Request/`, `Adapter/Response/`, `Service/Result/`, `Webhook/`

3. **shop-watch**: Uses `ValueObject/` folder instead of `DTO/`
