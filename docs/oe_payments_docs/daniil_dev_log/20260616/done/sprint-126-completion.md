# Sprint 126 — Completion report

**Status:** ✅ DONE · **Date:** 2026-06-16 · **Ticket:** STRP-135
**Repo:** `extensions/opalreturns` · **Branch:** `b-7.4.x-agnosticism`
**Sprint plan:** `sprint-126-opalreturns-refund-intent-handler-conditional-service.md`
**Report:** `../reports/01-opalreturns-refund-intent-handler-hard-dependency.md`

## Outcome

`oe:module:activate opalreturns` no longer fatals when the installed payment-base lacks
`RefundIntentHandler`. The handler is now produced by a factory that resolves the real
payment-base handler when present and a no-op fallback otherwise. Verified end-to-end
against canonical payment-base (`b-7.4.x` @ `7bc2ef9`).

## Files

**Production**
- `src/Integration/PaymentBase/RefundIntentListenerFactory.php` — new; `create(): callable`
  with protected `paymentBaseAvailable()` seam (`class_exists(RefundIntentHandler::class)`).
- `src/Integration/PaymentBase/NullRefundIntentListener.php` — new; no-op `__invoke` fallback.
- `services.yaml` — `opalreturns.refund_intent_handler` now `factory:`-resolved; `class:` =
  the factory's own class (never the payment-base FQCN); listener tag carries `method: '__invoke'`.
- `src/Domain/Event/ReturnRefundRequestedEvent.php` — docblock documents the C-skip invariant.

**Tests**
- `tests/Unit/Integration/PaymentBase/RefundIntentListenerFactoryTest.php` — both branches.
- `tests/Unit/Integration/PaymentBase/NullRefundIntentListenerTest.php` — no-op proof.
- `tests/Unit/Integration/PaymentBase/RefundIntentHandlerWiringTest.php` — service wiring.
- `tests/Unit/Domain/Event/ReturnRefundRequestedEventPathGuardTest.php` — C-skip guard.
- `tests/Unit/Architecture/SymfonyServiceIdClashTest.php` — updated to factory form.

## Phase outcomes

- **A — characterization:** RED tests for both deps states. ✅
- **B — factory + null-listener:** GREEN; activate fatal gone. Surfaced two OXID-7.4
  compile-pass requirements (`class:` on tagged services, `method:` on listener tag),
  both handled without referencing the payment-base FQCN. ✅
- **C — event landmine:** **C-skip** chosen. `ReturnRefundRequestedEvent` is only built in
  `PaymentBaseResolutionHandler::resolve()`, reachable only when the `@?` contract repo is
  non-null → never autoloads without payment-base. Guard test pins it. No structural change. ✅
- **D — gates + dual-state proof:** all green; activation proven HTTP 200 (present) and
  container-compile clean (absent). ✅

## Metrics

| Gate | Before | After |
|---|---|---|
| Unit tests | 297 | 308 (+11, green) |
| Integration tests | 12 | 12 |
| PHPCS | 0 | 0 |
| PHPStan (max) | 0 | 0 |
| New suppressions | — | 0 |
| PHPMD | n/a (no ruleset) | n/a |

## Commits

```
7adeb87  STRP-135 test: characterize refund_intent_handler wiring (both deps states)
8478dc0  STRP-135 fix: resolve refund_intent_handler via factory (tolerate absent/old payment-base)
9ba9f50  STRP-135 docs+test: assert ReturnRefundRequestedEvent stays on payment-base path
91947b8  STRP-135 chore: quality reports + dual-state activation proof
```

## End-to-end verification (canonical payment-base present)

`oe:cache:clear` → `oe:module:deactivate` → `oe:module:activate opalreturns` (exit 0, no
fatal) → `curl http://localhost.local/` HTTP 200. Booted container: service resolves to the
**real** `RefundIntentHandler` (callable); compiled container registers
`ReturnRefundRequestedEvent → opalreturns.refund_intent_handler::__invoke` alongside all 5
existing opalreturns listeners. Evidence: report §7.

## Follow-ups (not blocking merge)

- `EventBrokerInterface` needs a concrete binding from the active PSP (Stripe/PayPal) for
  refund routing to fire end-to-end; until then the handler early-returns (safe).
