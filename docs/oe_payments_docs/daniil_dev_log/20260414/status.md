# Status — 2026-04-14

## Sprint 87: STRP-120 Variable (Partial) Capture and Refund

### Objective

Enable partial capture and partial refund from the admin Stripe tab. Currently only full operations are supported; partial requires Stripe Dashboard.

### Core Principles

| Principle | Application |
|-----------|-------------|
| **TDD-first** | Sprint 87a: failing tests before any production code |
| **DevOps-first** | Sprint 87e: full pre-commit + E2E screenshot validation |
| **SOLID / SRP** | Each layer has one job: template collects input, controller validates, dispatcher routes, handler delegates, service executes |
| **Liskov** | `?float $amount` — null=full, float=partial — same interface, substitutable behavior |
| **Open/Closed** | Unblock existing code (remove restrictions) rather than rewriting; adapter/request layers untouched |
| **DI** | All services injected, no new dependencies needed (existing wiring sufficient) |
| **DRY** | Reuse `getCaptureableAmount()` and `getRemainingRefundableAmount()` for validation limits |
| **Clean Code** | Amount validation in one place (controller), no duplicate checks |
| **No overengineering** | No JS validation, no currency formatting, no multi-capture — just HTML5 number input + server-side check |

### Key Insight

The lower layers (Stripe API, adapter, request objects, CaptureService) **already support** partial amounts. The blocks are only in:
1. `OrderActionDispatcher` — hardcodes `amount: null`
2. `StripeRefundRequestHandler` — explicitly rejects partial (line 150)
3. `StripeRefundService::validateRefundAmount()` — requires exact match
4. Template — no amount input field

### Sub-Sprint Progress

| Sprint | Description | Status | Notes |
|--------|-------------|--------|-------|
| 87a | RED — Failing tests | done | 9 tests: 3 validation + 6 controller |
| 87b | GREEN — Unblock refund handler + fix validation | done | Removed rejection + fixed validateRefundAmount |
| 87c | GREEN — Dispatcher + controller amount params | done | dispatchRefund/dispatchCapture accept ?float $amount |
| 87d | GREEN — Stripe tab: amount inputs + translations | done | number inputs with min/max/step |
| 87e | GREEN — Order overview: captured/refunded amounts | done | Block override + Order model methods |
| 87f | REFACTOR — Pre-commit + E2E screenshot | done | All checks pass, module reactivated |
