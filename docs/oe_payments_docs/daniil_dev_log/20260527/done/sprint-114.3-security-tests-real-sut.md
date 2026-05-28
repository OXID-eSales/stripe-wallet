# Sprint 114.3 — Security tests must exercise the real SUT

**Module:** `extensions/stripe`
**Priority:** P0 (false test confidence on security-critical paths)
**Findings:** T1 (TDD — testable-subclass re-implements the method under test)
**Mode:** test-only refactor, may span 2 commits (one per test file). No production change expected; if a real seam is missing, add a minimal protected getter to the controller.
**Depends on:** none. (Coordinate with 114.2 if both touch `Order`/session helpers — they don't.)
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-1.5** (never re-implement the method under test in a double), **R-6** (gate green).

## 1. Why

Two security test suites assert against **copy-pasted re-implementations**
of the methods under test, so production can regress while the tests stay
green. `@covers` is therefore false:

- `tests/Unit/Stripe/Controller/StripeOrderControllerSecurityTest.php:190,267`
  re-declares `createCheckoutSession()` and `checkoutSuccess()`. The real
  `StripeOrderController::checkoutSuccess()` has **five** "Payment
  verification failed" branches (src lines 292, 314, 319, 325, 338); the
  double has **one**.
- `tests/Unit/Stripe/Controller/Webhook/WebhookControllerGuardIntegrationTest.php:165`
  re-implements `render()` ("Reimplements render() without OXID Registry
  calls") and omits production's warn-and-continue path when the guard chain
  is unavailable (`WebhookController::render()` ~lines 60-62).

The correct pattern already exists in the repo:
`tests/Unit/Stripe/Controller/StripeOrderControllerTest.php:636` overrides
only DI/IO seams and calls the real method.

## 2. Goals

- **G1.** Both suites invoke the **real** production methods; no method
  under test is re-declared in a testable subclass.
- **G2.** All five `checkoutSuccess()` rejection branches are individually
  covered (contract-token mismatch, secret-leak prevention, error
  sanitization, etc. — enumerate from src 292/314/319/325/338).
- **G3.** `WebhookController::render()` guard tests cover: each guard
  rejection, the payload-size path, AND the guard-chain-unavailable
  warn-and-continue path.
- **G4.** Testable subclasses override **only** seams: `init()`, container
  resolution / typed getters, header + `exit`/`echo` IO, request/session
  accessors. No business logic in the double.
- **G5.** If a seam is missing (e.g. no overridable getter for the guard
  chain or the JSON responder), add a minimal `protected` getter to the
  controller — production behavior unchanged — and note it.
- **G6.** `./bin/pre-commit-check.sh --full` green; assertion count rises.

## 3. Scope inventory

| File | Change |
|---|---|
| `StripeOrderControllerSecurityTest.php` | Replace the re-implemented `createCheckoutSession()`/`checkoutSuccess()` doubles with a seam-only subclass (mirror `StripeOrderControllerTest.php:636`). Drive each rejection branch via inputs. |
| `WebhookControllerGuardIntegrationTest.php` | Replace the re-implemented `render()` with a subclass overriding `getGuard()`, `init()`, header/exit IO only; exercise real `render()`. |
| `src/Stripe/Controller/StripeOrderController.php` | Only if needed — extract a `protected` seam (e.g. JSON responder, validator getter) so the real method is testable without re-declaration. |
| `src/Stripe/Controller/Webhook/WebhookController.php` | Only if needed — `protected getGuard()` / typed processor getter for override. |

## 4. TDD plan

This sprint *is* the test fix; "RED" = the rewritten tests fail against any
seam still missing, proving they now touch real code.

`checkoutSuccess()` matrix (one test each):
1. Valid return → success view, no error.
2. Contract id ≠ session contract id → "Payment verification failed", no secret leaked in output.
3. Missing/old session contract → rejection.
4. Token/signature mismatch branch → rejection.
5. Exception path → sanitized error (no stack/secret in response body).

`WebhookController::render()`:
6. Guard rejects (e.g. IP guard) → correct HTTP status, no processing.
7. Payload too large → rejection before signature work.
8. Guard chain unavailable → warns and continues (matches production), not a hard 500.

Assert on the controller's actual output/return + `displayedErrors`/HTTP
code produced by the **real** method, never on a re-declared copy.

## 5. Implementation steps

1. Read `StripeOrderController::checkoutSuccess()` fully; list the 5 branch conditions and their observable outputs.
2. Build `TestableStripeOrderController` overriding only: `init()` (skip OXID admin bootstrap), the validator/responder getters, and `exitWithJson()`/`echo` capture. Keep the real `checkoutSuccess()`/`createCheckoutSession()`.
3. Repeat for `TestableWebhookController` (override `getGuard()`, header/exit IO).
4. Where the real method calls a `private` that blocks substitution, promote to `protected` (minimal, no behavior change) — G5.
5. Delete the copied method bodies; keep `@covers` now that it is true.

## 6. Risks & rollback

- **Risk:** OXID admin/controller bootstrap leaks into the test (Registry, ContainerFactory). Mitigate by overriding `init()` and resolving collaborators through the seams — same approach as the working `StripeOrderControllerTest`.
- **Risk:** promoting `private`→`protected` widens API. Keep it to genuine seams; document each in the report.
- **Rollback:** test-only (plus tiny visibility tweaks); low blast radius.

## 7. Definition of Done

- G1–G6 met; the re-implemented method bodies are gone from both test files.
- A deliberately-introduced regression in any one `checkoutSuccess()` branch turns a test RED (spot-check during review).
- Completion report in `done/` listing each covered branch and any promoted seam.
