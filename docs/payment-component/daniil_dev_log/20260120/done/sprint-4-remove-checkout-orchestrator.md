# Sprint 4: Remove Unused CheckoutOrchestrator

**Date:** 2026-01-20
**Priority:** Low
**Estimated Effort:** 1-2 hours
**Risk Level:** Very Low (code is confirmed unused)

---

## Core Development Principles

All code in this sprint MUST follow:

| Principle | Requirement |
|-----------|-------------|
| **TDD-First** | Write failing tests BEFORE implementation. Red → Green → Refactor |
| **SOLID** | Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion |
| **Liskov Substitution** | Subtypes must be substitutable for their base types |
| **Dependency Injection** | Depend on abstractions, not concretions. Inject dependencies via constructor |
| **DRY** | Don't Repeat Yourself. Extract common logic to shared methods/classes |
| **Clean Code** | Meaningful names, small functions (15-25 lines), early returns (no else), single responsibility per method |
| **No Over-Engineering** | Only add what's needed now. No speculative features or premature abstractions |

### Testing Commands

Run from `payment-component/` or `stripe/` directory:

```bash
# Quick check (unit tests + style checks)
./bin/pre-commit-check.sh

# Full check (unit tests + integration tests + style checks)
./bin/pre-commit-check.sh --full
```

---

## Executive Summary

Remove the `CheckoutOrchestrator` service and its related result classes. These are registered in `services.yaml` but **never called** by any code in the Stripe module or payment-component.

Unlike the capture/refund services (Sprint 3), there's no architectural question here - `CheckoutOrchestrator` simply isn't used and serves no purpose.

---

## Evidence of Non-Usage

### 1. Registered but Never Called

**services.yaml (lines 76-88):**
```yaml
OxidEsales\PaymentComponent\Service\CheckoutOrchestratorInterface:
  class: OxidEsales\PaymentComponent\Service\CheckoutOrchestrator
  arguments:
    $eventDispatcher: '@OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface'
    $logger: '@Psr\Log\LoggerInterface'
```

### 2. Grep Results

```bash
$ grep -r "CheckoutOrchestrator" stripe/src/
# No matches

$ grep -r "CheckoutOrchestratorInterface" stripe/src/
# No matches

$ grep -r "processCheckout\|confirmOrder" stripe/src/
# No matches for orchestrator methods
```

### 3. Result Classes Only Used by Orchestrator

`CheckoutResult` and `OrderConfirmationResult` are only referenced in:
- `CheckoutOrchestrator.php` - returns them
- `CheckoutOrchestratorInterface.php` - type hints
- Test files for the orchestrator

No other code uses these classes.

---

## Files to Remove

### Source Files
```
payment-component/src/Service/CheckoutOrchestrator.php
payment-component/src/Service/CheckoutOrchestratorInterface.php
payment-component/src/Service/Result/CheckoutResult.php
payment-component/src/Service/Result/OrderConfirmationResult.php
```

### Test Files
```
payment-component/tests/Unit/Service/CheckoutOrchestratorTest.php
payment-component/tests/Unit/Service/Result/CheckoutResultTest.php
payment-component/tests/Unit/Service/Result/OrderConfirmationResultTest.php
```

### services.yaml Entry
Remove lines 76-88:
```yaml
# REMOVE THIS BLOCK
OxidEsales\PaymentComponent\Service\CheckoutOrchestratorInterface:
  class: OxidEsales\PaymentComponent\Service\CheckoutOrchestrator
  arguments:
    $eventDispatcher: '@OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface'
    $logger: '@Psr\Log\LoggerInterface'
```

---

## Why This Code Exists

Based on the code structure, `CheckoutOrchestrator` was likely intended to:

1. **Orchestrate checkout flow** - coordinate between basket validation, payment initiation, and order creation
2. **Abstract checkout process** - provide a high-level API for checkout operations

However, **Stripe implemented its own checkout flow** via:
- `StripeCheckoutController` - handles checkout initiation
- `StripeCheckoutService` - manages Stripe Checkout Sessions
- Event handlers - coordinate contract creation, order creation

The orchestrator became redundant before it was ever used.

---

## Implementation Steps

### Step 1: Remove Test Files First
```bash
rm payment-component/tests/Unit/Service/CheckoutOrchestratorTest.php
rm payment-component/tests/Unit/Service/Result/CheckoutResultTest.php
rm payment-component/tests/Unit/Service/Result/OrderConfirmationResultTest.php
rm -rf payment-component/tests/Unit/Service/Result/  # if empty
```

### Step 2: Remove Source Files
```bash
rm payment-component/src/Service/CheckoutOrchestrator.php
rm payment-component/src/Service/CheckoutOrchestratorInterface.php
rm payment-component/src/Service/Result/CheckoutResult.php
rm payment-component/src/Service/Result/OrderConfirmationResult.php
rm -rf payment-component/src/Service/Result/  # if empty
```

### Step 3: Update services.yaml
Remove the CheckoutOrchestratorInterface registration block.

### Step 4: Verify
```bash
# Run PHPStan
composer phpstan

# Run tests
composer test-unit

# Check for broken references
grep -r "CheckoutOrchestrator\|CheckoutResult\|OrderConfirmationResult" payment-component/
```

---

## Verification Checklist

- [ ] No references to `CheckoutOrchestrator` in payment-component/src/
- [ ] No references to `CheckoutOrchestrator` in stripe/src/
- [ ] No references in services.yaml
- [ ] PHPStan passes
- [ ] Unit tests pass
- [ ] Integration tests pass

---

## Impact Assessment

| Metric | Change |
|--------|--------|
| Files removed | 4-7 (depending on tests) |
| Lines of code | ~300-400 |
| Risk | None - code never executed |

---

## Post-Removal: Create Report

After removal, create a report at:
`reports/checkout-orchestrator-removal.md`

Content:
- Files removed
- Verification results
- Reason for removal

---

## References

- Sprint 1: Overall code analysis
- Architecture: Component never specified orchestrator as entry point
- Stripe flow: Uses controllers + event handlers instead
