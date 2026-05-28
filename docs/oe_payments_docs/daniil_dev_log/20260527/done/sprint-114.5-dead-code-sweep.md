# Sprint 114.5 — Dead-code sweep

**Module:** `extensions/stripe`
**Priority:** P1 (remove ~ several hundred LOC of unreachable code → smaller risk surface)
**Findings:** O2, O4, O5, O6, O7, O8, O9, O10
**Mode:** one commit per finding group (so each deletion is independently revertable), TDD-aware (delete the dead code AND its now-pointless tests; keep tests for anything still reachable).
**Depends on:** 114.4 (so the webhook handler set is finalized before deleting handlers).
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-9.2** (grep-prove non-reachability before deleting), **R-7.3** (no orphan event/handler), **R-6.2** (shrink the PHPMD baseline, no new suppressions).

## 1. Why

Several classes/methods/events are unreachable in production (verified by
`grep` for non-test, non-definition callers). Maintaining them costs review
time and hides the real call graph.

## 2. Items (each its own commit)

| # | Finding | Delete | Evidence (no prod caller) |
|---|---------|--------|---------------------------|
| 1 | O2 | `Service/StripeCaptureService.php`, `Service/StripeRefundService.php` + their `services.yaml:786,794` registrations | live paths use `CaptureService`/`RefundService`; zero non-test refs in `src/` |
| 2 | O4 | `ConfigurationValidator::validateConfiguration()`, `validateKeyPair()`, `testConnection()`, `validateApiKeyFormat()` + their `ConfigurationValidatorInterface` entries | only `getKeyValidationError()` is called (`ModuleConfiguration:142`, `StripeOrderController:181`) |
| 3 | O5 | `EventSystem/Event/Stripe3DSRequiredEvent.php` + its dispatch at `StripePaymentStatusHandler.php:155` | no `Stripe3DSHandler` class, no listener — dispatched into the void |
| 4 | O6 | `StripeStatusMapper::fromPaymentIntent()`, `isProcessing()`, `isAuthorized()` | no `src/` callers; only the mapper's own test |
| 5 | O7 | `Payment` speculative methods: `isOtherSourced()`, `getPaymentProvider()`, `requiresStripeConfiguration()`, `getStripePaymentMethodType()`, `supportsStripeFeature()` | no callers in reviewed surface; `supportsStripeFeature` returns hardcoded `true` (dead logic) |
| 6 | O8 | `CheckoutReturnService::getSessionDetails()` (+ interface entry); `ModuleConfigurationService::getShopBaseUrl()` (+ drop the now-unused `ShopAdapterInterface` ctor arg if it has no other use) | only test/comment callers; real paths use `validateReturn()` / `getSslShopBaseUrl()` |
| 7 | O9 | `ReturnSessionSecurityService::QUICK_RETURN_MAX` constant + its `@phpstan-ignore` | dead constant kept alive by a suppression (violates project rule) |
| 8 | O10 | (optional) collapse `StripeEventTranslator`'s `instanceof` ladder to a `[abstract=>concrete]` map — refactor, not delete; defer if low value |

> **Verify-before-delete:** O5's dispatch removal means `StripePaymentStatusHandler::handlePending()` must still set 3DS context inline (the event carried `clientSecret`/`returnUrl`). Either keep that context-setting in `handlePending()` or confirm the frontend reads it elsewhere — do **not** silently drop 3DS data.

## 3. Goals

- **G1.** Each item removed in its own commit with message referencing the finding id.
- **G2.** For every deletion, run `grep -rn "<Symbol>" src/ tests/` first; remove orphaned tests in the same commit.
- **G3.** Interfaces shrink alongside implementations (don't leave declared-but-unimplemented methods).
- **G4.** O5: 3DS context still reaches the client (regression-tested).
- **G5.** PHPStan level max + PHPMD clean (deletions should *reduce* the PHPMD baseline — update `tests/PhpMd/phpmd.baseline.xml` if entries become obsolete).
- **G6.** `./bin/pre-commit-check.sh --full` green after each commit.

## 4. TDD plan

Deletion sprints invert TDD: the safety net is proving **nothing
reachable** breaks.

1. Before each delete: `grep -rn "ClassOrMethod" src/` → must show only the definition (+ tests). Paste proof into the report.
2. Run the full suite; confirm the only failures are tests of the deleted code; delete those tests.
3. For O5 (behavioral): add/keep a test asserting `handlePending()` still populates 3DS `clientSecret`/`returnUrl` in the context after the event is removed.
4. For O4/O8: confirm `getKeyValidationError()` / `validateReturn()` / `getSslShopBaseUrl()` retain their tests.

## 5. Risks & rollback

- **Risk:** a "dead" method is actually called from a Twig template, JS, or the OPC/other module via string/`method_exists`. Grep `views/`, `out/`, and sibling modules (`paypal`, `one-page-checkout`) for the symbol names before deleting `Payment`/`Order` model methods (O7).
- **Risk:** removing `ShopAdapterInterface` from `ModuleConfigurationService` ctor (O6) — confirm no other method uses it.
- **Rollback:** per-item commits → revert the offending one only.

## 6. Definition of Done

- All items removed (or explicitly deferred with rationale, e.g. O10).
- Grep proofs in the completion report; PHPMD baseline shrunk where applicable.
- Report 114 O2/O4/O5/O6/O7/O8/O9 marked Fixed.
