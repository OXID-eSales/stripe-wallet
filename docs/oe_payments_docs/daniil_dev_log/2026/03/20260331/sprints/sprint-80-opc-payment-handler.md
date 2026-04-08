# Sprint 80: One-Page-Checkout Payment Handler Integration

## Problem

The `one-page-checkout` module's `CheckoutService` looks up payment handlers via `PaymentHandlerRegistry::getHandlerForPaymentMethod()`. Handlers must implement `PaymentHandlerInterface` and be tagged `oe.payment.handler` in DI. The Stripe module has no such handler, causing:

```
Payment handler not found {"paymentMethodId":"oe_payments_stripe_wallet"}
```

## Root Cause

Stripe's checkout flow is event-driven (`StripeCheckoutSessionRequestEvent` dispatched by `StripeOrderController`). The one-page-checkout expects a `PaymentHandlerInterface` implementation registered in the DI container. No bridge exists.

## Approach: Option 1 — Handler lives in Stripe module

The Stripe module implements `OxidEsales\OnePageCheckout\Contract\PaymentHandlerInterface` and registers itself. This follows the **provider-registers-itself** pattern already established by `StandardPaymentHandler` in one-page-checkout.

### Why this approach

- **LSP:** Stripe handler IS-A PaymentHandler — same contract, different provider
- **OCP:** Adding a new provider = adding a new module, not modifying one-page-checkout
- **DIP:** one-page-checkout depends on `PaymentHandlerInterface` abstraction; Stripe provides the concrete

### New dependency

- `composer require oxid-esales/onepage-checkout:dev-b-7.4.x` (as `require`, not `require-dev`)

## Implementation Plan

### Step 1: Failing test — `supports()`

**File:** `tests/Unit/Stripe/PaymentHandler/StripePaymentHandlerTest.php`

Test that:
- `supports('oe_payments_stripe_wallet')` returns `true`
- `supports('oxidcashondel')` returns `false`
- `supports('oe_payments_stripe_other_future')` returns `true` (prefix match)
- `getId()` returns `'stripe'`
- `getName()` returns a non-empty string

Uses `StripeDefinitions::STRIPE_WALLET_PAYMENT_ID` constant (memory: never hardcode payment IDs).

### Step 2: Failing test — `processPayment()`

Test that:
- Given a valid `PaymentContext` with basket + user + `oe_payments_stripe_wallet` payment ID
- Handler creates a contract via `ContractServiceInterface`
- Handler calls `CheckoutSessionServiceInterface::createSession()`
- Returns `PaymentHandlerResult::success()` with contractId and checkoutUrl in metadata
- On failure: returns `PaymentHandlerResult::error()`, no exception thrown

Mock dependencies: `ContractServiceInterface`, `CheckoutSessionServiceInterface`, `ContractRepositoryInterface`, `TokenServiceInterface`, `ShopAdapterInterface`, `ModuleConfigurationServiceInterface`.

Mock **interfaces**, not concrete classes (memory: Sprint 42 lesson).

### Step 3: Implement `StripePaymentHandler`

**File:** `src/Stripe/PaymentHandler/StripePaymentHandler.php`

```
class StripePaymentHandler implements PaymentHandlerInterface
```

Responsibilities:
1. `supports()` — prefix match on `oe_payments_stripe_`
2. `processPayment()` — delegates to existing services:
   - Create contract via `ContractServiceInterface` (reuse existing logic)
   - Create Stripe Checkout Session via `CheckoutSessionServiceInterface`
   - Return result with contractId + checkoutUrl
3. `confirmPayment()` — delegates to webhook flow (Stripe confirms asynchronously)
4. `getFrontendConfig()` — returns publishable key from `ModuleConfigurationServiceInterface`

**Key principle: NO new business logic.** This class is a thin adapter bridging the `PaymentHandlerInterface` contract to existing Stripe services. All real work already happens in `CheckoutSessionService`, `ContractService`, etc.

Constructor dependencies (all existing interfaces):
- `ContractServiceInterface` — contract creation
- `CheckoutSessionServiceInterface` — Stripe session creation
- `ContractRepositoryInterface` — contract persistence
- `TokenServiceInterface` — contract token generation
- `ShopAdapterInterface` — shop URL
- `ModuleConfigurationServiceInterface` — Stripe config (publishable key, capture mode)
- `StripeCustomerServiceInterface` — Stripe customer resolution
- `?LoggerInterface` — optional logging

### Step 4: DI registration

**File:** `services.yaml`

```yaml
# One-Page-Checkout Payment Handler — bridges OPC interface to Stripe services
OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler:
  tags:
    - { name: oe.payment.handler }
  public: true
```

Autowire handles constructor injection (already enabled in `_defaults`).

### Step 5: Composer dependency

```bash
composer require oxid-esales/onepage-checkout:dev-b-7.4.x
```

### Step 6: Pre-commit check

```bash
./bin/pre-commit-check.sh --full
```

Must pass: PHPCS, PHPStan (level max), PHPMD, Unit tests, Integration tests.

## What this does NOT do

- No refactoring of existing event-driven flow (standard checkout still works via `StripeOrderController`)
- No new business logic — pure adapter/bridge
- No changes to one-page-checkout module
- No changes to payment-component

## Risk

- **Constructor arg count:** `StripePaymentHandler` has 8 deps. Acceptable for an adapter that bridges two systems. PHPMD `TooManyPublicMethods` won't trigger (only 5 public methods from interface + getId/getName).
- **Basket snapshot creation:** `processPayment()` receives an OXID basket object from `PaymentContext`. Need to convert to `BasketSnapshot` for `CheckoutSessionService`. Reuse `BasketSnapshotFactory` if it exists, or extract from `StripeContractCreationHandler`.

## Files to create/modify

| File | Action |
|------|--------|
| `tests/Unit/Stripe/PaymentHandler/StripePaymentHandlerTest.php` | create |
| `src/Stripe/PaymentHandler/StripePaymentHandler.php` | create |
| `services.yaml` | add handler registration |
| `composer.json` | add one-page-checkout dependency |