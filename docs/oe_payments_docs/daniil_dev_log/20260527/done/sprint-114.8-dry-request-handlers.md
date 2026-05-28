# Sprint 114.8 — DRY the Stripe request handlers

**Module:** `extensions/stripe`
**Priority:** P2 (DRY across capture/refund/cancel handlers + webhook refund recording)
**Findings:** D3 (refund-recording triplicated), D4 (handler skeleton + PI-resolution duplicated), D5 (idempotency wrapper duplicated)
**Mode:** introduce shared collaborators + a base/trait, migrate handlers, TDD-first. Multi-commit.
**Depends on:** 114.1 (shopId injection), 114.4 (handler set finalized). **Coordinate with:** 114.7.
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-2.1** (extract shared collaborators), **R-10** (refund recording stays a service-via-repository write reached from the event), **R-8.2** (named contract transitions), **R-1.4** (idempotency PROCESSING/COMPLETED/FAILED matrix).

## 1. Why

Three structural duplications across the event/webhook handlers:

- **D4 — handler skeleton + PI-resolution.** `StripeCaptureRequestHandler`, `StripeRefundRequestHandler`, `StripeCancelAuthorizationRequestHandler` share the same `handle()` shape (`logEvent START` → `instanceof` guard → `try { processXxx } catch (\Throwable) { handleException }`), the same PaymentIntent-id resolution chain (`getProviderOrderId` → metadata fallback → "No PaymentIntent ID found"), and the same `logEvent()` / `logExceptionToRequestLog()` helpers. `logEvent()` is copy-pasted into all 9 handlers. The cancel handler's docblock literally says "Mirrors StripeCaptureRequestHandler::getPaymentIntentId()".
- **D3 — refund recording.** `addRefundedAmount → setRefundedAt → save` is triplicated (`StripeRefundRequestHandler:212`, `ChargeRefundedHandler:68`, `WebhookContractFulfillmentHandler:73`), and the `!isFulfilled()` guard exists in two but is missing from `ChargeRefundedHandler` (divergence).
- **D5 — idempotency wrapper.** `PaymentIntentHelper::captureWithIdempotency()` (266-376) and `RefundHelper::refundWithIdempotency()` (144-259) are near-identical: same status constants, find/short-circuit/save/execute/save flow, same `catch → setStatus(FAILED) → setResult(json) → throw`.

## 2. Goals

- **G1. `ContractRefundRecorder`** (or method on an existing payment-base service) — one place that does `guard isFulfilled → addRefundedAmount → setRefundedAt → save`. All 3 refund sites call it; the guard is now uniform.
- **G2. `PaymentIntentResolver`** — one collaborator resolving the PI id from event → contract `getProviderOrderId` → metadata fallback, returning a typed result or a uniform "not found" error. The 3 request handlers use it.
- **G3. `AbstractStripeRequestHandler`** (base class) or a `RequestHandlerLoggingTrait` — holds the `handle()` template method, `logEvent()`, and `logExceptionToRequestLog()`. The 3 request handlers extend/use it; per-handler code shrinks to the action string + success/error context keys.
- **G4. `IdempotentExecutor`** — generic `execute(string $key, callable $op, callable $serialize, callable $deserialize)` (home: extend the existing `IdempotencyHelper`). Both helpers delegate; the capture/refund helpers keep only their op + serializer.
- **G5.** No behavior change — capturable-state policy still applies, idempotency semantics identical, refund FULFILLED guard now consistently applied (fixes the `ChargeRefundedHandler` gap).
- **G6.** PHPMD `ExcessiveClassComplexity` on `StripeCaptureRequestHandler` reduced (baseline entry removable if it drops under threshold).
- **G7.** `./bin/pre-commit-check.sh --full` green.

## 3. TDD plan (RED first)

Per collaborator, write the unit test before extraction:
1. `ContractRefundRecorderTest` — fulfilled contract → records + saves; non-fulfilled → no-op (or documented behavior); asserts `addRefundedAmount`/`setRefundedAt`/`save` called once. Then point all 3 handlers at it and assert each still records correctly (incl. the previously-unguarded `ChargeRefundedHandler` now guarded).
2. `PaymentIntentResolverTest` — event-id present; contract-only; metadata fallback; none → uniform error. Then assert the 3 handlers delegate.
3. `IdempotentExecutorTest` — first call executes + stores COMPLETED; concurrent PROCESSING → throws "already in progress"; COMPLETED → returns deserialized without re-executing; failure → FAILED + rethrow. Then assert `captureWithIdempotency`/`refundWithIdempotency` behavior unchanged via existing helper tests.
4. Keep the existing per-handler tests green throughout (they are the behavior-parity net).

## 4. Implementation steps

1. Extract `IdempotentExecutor` first (most self-contained); migrate both helpers; run helper tests.
2. Extract `ContractRefundRecorder`; migrate the 3 refund sites; unify the guard.
3. Extract `PaymentIntentResolver`; migrate the 3 request handlers.
4. Introduce `AbstractStripeRequestHandler` (or trait); pull up `logEvent`/`logExceptionToRequestLog` + the `handle()` template; reduce each handler to its deltas.
5. Re-tag in `services.yaml` if constructor signatures change; `oe:cache:clear` + `restart php`.

## 5. Risks & rollback

- **Risk:** base-class template method reduces flexibility for the capture handler's dual-mode (direct vs contract) dispatch — keep mode-specific logic in the concrete handler / push into `CaptureService` (see 114.11 S3), not the base.
- **Risk:** behavior drift in idempotency edge cases. The `IdempotentExecutorTest` PROCESSING/COMPLETED/FAILED matrix is mandatory.
- **Rollback:** each extraction is its own commit; revert independently.

## 6. Definition of Done

- G1–G7 met; `logEvent()` exists once (not 9×); refund recording exists once; idempotency wrapper exists once.
- `ChargeRefundedHandler` now applies the FULFILLED guard (D3 divergence closed).
- Completion report shows the PHPMD complexity delta on `StripeCaptureRequestHandler`.
