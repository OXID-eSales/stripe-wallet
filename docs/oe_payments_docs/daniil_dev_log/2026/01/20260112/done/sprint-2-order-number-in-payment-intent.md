# Sprint 2: STRP-75 Order Number in Payment Intent Metadata

## Objective
Update payment intent initialization to send `order_number` from oxorder instead of (or in addition to) `contract_id` in Stripe metadata.

## Background
With STRP-74 completed, orders are now created early in the flow (DRAFT → NOT_FINISHED). The contract has an `orderId` after the early order creation step. We should leverage this to include the actual OXID order number (`OXORDERNR`) in Stripe metadata for better tracking and reconciliation.

## Current Flow
```
1. Contract created (DRAFT)
2. EarlyOrderCreationHandler creates order (NOT_FINISHED)
3. Contract transitions to NOT_FINISHED (has orderId)
4. Contract transitions to PENDING
5. StripeCheckoutSessionHandler creates Stripe session with contract_id only
6. Payment completed → Contract fulfilled
```

## Target State
Include `order_number` (OXORDERNR) in Stripe Checkout Session and PaymentIntent metadata:
```php
'metadata' => [
    'contract_id' => $contractId,
    'order_id' => $orderId,           // oxorder.OXID
    'order_number' => $orderNumber,   // oxorder.OXORDERNR
    'shop_id' => $shopId,
],
```

## Implementation Steps

### 1. Update CheckoutSessionServiceInterface
Add `orderNumber` parameter to `createSession()` method signature.

### 2. Update CheckoutSessionService
- Accept `orderNumber` as optional parameter
- Include in session and payment_intent_data metadata

### 3. Update StripeCheckoutSessionHandler
- Get order ID from contract (`$contract->getOrderId()`)
- Look up order number from order repository
- Pass order number to CheckoutSessionService

### 4. Add OrderRepositoryInterface method (if not exists)
- `getOrderNumber(string $orderId): ?string` - fetch OXORDERNR by OXID

## Core Requirements
- **TDD-first**: Write failing tests before implementation
- **SOLID**: Single Responsibility, Open/Closed, Liskov Substitution
- **Clean Code**: Small methods, meaningful names, early returns
- **No Over-engineering**: Minimal changes to achieve the goal

## Files to Modify
1. `src/Stripe/Service/CheckoutSessionServiceInterface.php`
2. `src/Stripe/Service/CheckoutSessionService.php`
3. `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php`
4. Possibly: `src/Component/Adapter/ShopOrderServiceInterface.php`

## Test Files
1. `tests/Unit/Stripe/Service/CheckoutSessionServiceTest.php`
2. `tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutSessionHandlerTest.php`

## Acceptance Criteria
- [ ] Stripe Checkout Session metadata includes `order_number`
- [ ] PaymentIntent metadata includes `order_number`
- [ ] Backward compatible (works if order not yet created)
- [ ] All unit tests pass
- [ ] PHPStan passes
- [ ] PHPMD passes

## Dependencies
- STRP-74 completed (early order creation)

## Notes
- `contract_id` should remain for backward compatibility and webhook processing
- `order_number` is the human-readable order number (OXORDERNR)
- `order_id` is the internal OXID (OXID)
