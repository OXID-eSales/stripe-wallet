# Status -- 2026-04-08

## Sprint 82: STRP-118 Fix Manual Capture -- Committed Contract Cannot Be Captured

### Objective

Fix the bug where manual capture orders cannot be captured from admin because the contract is in COMMITTED state instead of AUTHORIZED. Additionally, hide the refund section for uncaptured manual-capture orders.

### Core Principles Applied

| Principle | How It Applies to This Sprint |
|-----------|-------------------------------|
| **TDD** | Write failing tests first (unit, integration, E2E), then implement fixes to make them green |
| **SOLID -- SRP** | Each fix is isolated: handler validation, service transition, controller visibility |
| **SOLID -- OCP** | CaptureService extended to handle new state without changing existing AUTHORIZED flow |
| **SOLID -- LSP** | Both AUTHORIZED and COMMITTED contracts can be captured -- substitutable in the handler |
| **Clean Code** | Early returns, no else expressions, small focused methods |

### Bug Summary

| Symptom | Root Cause | Fix Location |
|---------|-----------|--------------|
| "Cannot capture: contract not in AUTHORIZED state (current: committed)" | Contract skips AUTHORIZED state during checkout return flow | `StripeCaptureRequestHandler`, `CaptureService` |
| Refund section shown for uncaptured orders | `isOrderRefundable()` doesn't check capture state | `OrderRefund` controller |

### Files Modified

| File | Change |
|------|--------|
| `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php` | Accept COMMITTED state for capture |
| `src/Stripe/Service/CaptureService.php` | Handle COMMITTED->FULFILLED transition |
| `src/Stripe/Controller/Admin/OrderRefund.php` | Hide refund when order is capturable |