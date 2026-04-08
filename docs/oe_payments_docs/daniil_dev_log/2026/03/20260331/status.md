# Status — 2026-03-31

## Sprint 80: One-Page-Checkout Payment Handler Integration

| Step | Description | Status |
|------|-------------|--------|
| 1 | Write 13 failing unit tests for StripePaymentHandler | done |
| 2 | Implement StripePaymentHandler (thin adapter, no new business logic) | done |
| 3 | Register handler in services.yaml with `oe.payment.handler` tag | done |
| 4 | Move PaymentHandlerInterface/Context/Result to payment-component (DIP) | done |
| 5 | Update OPC to import from payment-component + backwards-compat re-exports | done |
| 6 | Update Stripe to import from payment-component, remove OPC dependency | done |
| 7 | Run full pre-commit check (all modules) | done |

**Overall:** completed

### Results

- **Stripe Unit tests:** 803 pass, 0 failures (13 new)
- **OPC Unit tests:** 42 pass, 0 failures
- **PHPCS:** 0 errors
- **PHPStan:** 0 errors (level max)
- **PHPMD:** 0 errors

### Design Decision: Shared Abstraction over Direct Dependency

Original plan had Stripe depending on one-page-checkout. Changed to move
`PaymentHandlerInterface`, `PaymentContext`, `PaymentHandlerResult` to
`payment-component/src/Adapter/` (shared dependency of both modules).

**Why:** Stripe should be *aware* of the handler contract but *not dependent*
on one-page-checkout. Both modules already depend on payment-component, so
placing the interface there follows DIP — both depend on the abstraction,
neither depends on each other.

### Files Created

| File | Module |
|------|--------|
| `src/Adapter/PaymentHandlerInterface.php` | payment-component |
| `src/Adapter/PaymentContext.php` | payment-component |
| `src/Adapter/PaymentHandlerResult.php` | payment-component |
| `src/Stripe/PaymentHandler/StripePaymentHandler.php` | stripe |
| `tests/Unit/Stripe/PaymentHandler/StripePaymentHandlerTest.php` | stripe |

### Files Modified

| File | Module | Change |
|------|--------|--------|
| `services.yaml` | stripe | Added `oe.payment.handler` tagged service |
| `composer.json` | stripe | Removed OPC dependency (not needed) |
| `src/Stripe/Controller/PaymentController.php` | stripe | Fixed `oxNew()` constructor bug |
| `src/Contract/PaymentHandlerInterface.php` | one-page-checkout | Deprecated, re-exports from payment-component |
| `src/Contract/PaymentContext.php` | one-page-checkout | Deprecated, extends payment-component class |
| `src/Contract/PaymentHandlerResult.php` | one-page-checkout | Deprecated, extends payment-component class |
| `src/PaymentHandler/StandardPaymentHandler.php` | one-page-checkout | Updated imports |
| `src/Service/CheckoutService.php` | one-page-checkout | Updated imports |
| `src/Service/PaymentHandlerRegistry.php` | one-page-checkout | Updated imports |
