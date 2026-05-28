# Sprint 114.13 — Close coverage gaps + test hygiene

**Module:** `extensions/stripe`
**Priority:** P3 (test quality)
**Findings:** §8 coverage gaps, T3 (tautological tests), T4 (test-of-test-helper), T5 (no-value assertions), T6 (silent integration skips)
**Mode:** test-only (+ tiny seams if needed), TDD-first for the new coverage. Multi-commit.
**Depends on:** 114.4, 114.5 (so the class set is final before writing tests for it). Some gaps auto-close: 114.9 D8 (OPC handlers), 114.11 S6 (`StripeWebhookEndpointApi`).
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-1** (real behavioral tests, mock interfaces not concretions), **R-7.3** (cover the translator/handlers), **R-6** (honest CI gating — no silent skips).

## 1. Why

Several production classes with real logic have **no test**, and several
existing tests assert nothing meaningful — both give false confidence.

## 2. Coverage gaps (write real tests)

| Class | Why it matters | Note |
|---|---|---|
| `Adapter/StripeWebhookEndpointApi.php` | 133 LOC; recent STRP-144 webhook-endpoint CRUD against Stripe; untested | likely covered by 114.11 S6 — coordinate |
| `EventSystem/Handler/OpcModalSuccessUrlHandler.php` | OPC success-URL building | covered by 114.9 D8 extraction + tests |
| `EventSystem/Handler/OpcModalCancelUrlHandler.php` | OPC cancel-URL building | covered by 114.9 D8 |
| `EventSystem/Translator/StripeEventTranslator.php` | maps agnostic→Stripe events; untested | test `supports()`/`translate()` for each mapped type + null path |
| `Service/OxidContractLinkedOrderUpdater.php` | `markCancelled`/`markFailed` mutate order state on cancel/fail; only the interface is mocked elsewhere | test the OXID impl via testable subclass |
| `Controller/OxidSessionWriter.php` | `writeSessChallenge()` — CSRF/session-challenge writer | security-relevant; test the write |
| `Component/Widget/StripeCheckoutFooter.php` | `render`/`getCheckoutData`/`getStripeConfig` | test config assembly |

For each: write the test FIRST against current behavior (characterization),
then it guards future change. Use the seam-only testable-subclass pattern; do
not re-implement the method under test (the T1 mistake from 114.3).

## 3. Test hygiene (fix existing)

- **T3 tautological** — `OrderRefundViewDataProviderTest:71-84` mocks `availableForRefund` to return the asserted value, so the charge fixture is dead. Either inject the real `StripeChargeAmountResolver` (so the fixture drives the result) or drop the misleading fixture/comments. (May be superseded by 114.11 S4 / 114.10 DTO migration — reconcile.)
- **T4 test-of-test-helper** — `StripeWebhookTestHelperTest` verifies the helper against itself. Move signature verification into an integration test that calls Stripe's real `Webhook::constructEvent`, or delete the self-verification tests.
- **T5 no-value assertions** — remove `assertTrue(true)` (`StripeCaptureRequestHandlerTest:72`, `RequestLogServiceTest:67,95`, `StripeRefundServiceValidationTest:29,53`) and the `assertInstanceOf`-on-`new` (`OxpaidReconciliationServiceTest:71`); rely on `expects()`/`expectException`.
- **T6 silent integration skips** — `tests/Integration/Stripe/StripeIntegrationTestCase.php:45` + `SKIPPED_TESTS_REASON.md`: ~53 tests skip silently (no creds / container boot fail). Split credential-dependent specs into an explicitly-gated suite (e.g. `@group requires-stripe-creds`) so the default CI run's coverage is honest, and make the container-boot precondition a hard failure (not a skip) in CI.
- **Mock-interface fixes** (from the test review): replace `createMock(PaymentContract::class)` with `PaymentContractInterface` (`StripeCaptureRequestHandlerTest`, `WebhookContractFulfillmentHandlerTest`, `StripePaymentHandlerLanguageTest`); build real `BasketSnapshot` value objects instead of mocking them.

## 4. Goals

- **G1.** Every §8 class has a dedicated test (or is provably covered by a sibling sprint's extraction).
- **G2.** No `assertTrue(true)` / `assertInstanceOf`-on-`new` / mock-returns-then-asserts-same in the touched files.
- **G3.** Concrete-class mocks of `PaymentContract`/`BasketSnapshot` replaced.
- **G4.** Integration suite no longer reports green while silently skipping the Stripe-facing layer (T6): credential specs gated, container-boot failures hard-fail.
- **G5.** `./bin/pre-commit-check.sh --full` green; net meaningful assertion count rises.

## 5. Risks & rollback

- **Risk:** T6 hard-failing on missing creds could break CI for contributors without Stripe keys. Mitigate: gate by group; the default suite runs the credential-free tests, the gated suite runs only where keys exist (CI secret).
- **Risk:** overlap churn with 114.10/114.11 (S4, DTOs) — schedule 114.13 last so it tests the final shapes.
- **Rollback:** test-only; low risk.

## 6. Definition of Done

- §8 gaps closed (directly or via sibling sprints — cross-reference in the report).
- T3/T4/T5 fixed; T6 integration gating in place and documented.
- Completion report: per-class new-test list + before/after assertion counts.
