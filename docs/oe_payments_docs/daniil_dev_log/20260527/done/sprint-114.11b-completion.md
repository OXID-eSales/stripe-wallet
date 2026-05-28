# Sprint 114.11b — SRP Extractions (S2, S3, S4) — Completion Report

**Date:** 2026-05-28
**Branch:** `b-7.4.x-code-review-STRP-145`
**Status:** DONE

---

## Deliverables

### S2 — Split `ModuleConfigurationService` god-object

**New collaborators:**
- `src/Stripe/Service/StripeUrlBuilder.php` — owns `getWebhookUrl()` + protected `getSslShopBaseUrl()` seam
- `src/Stripe/Service/ModuleDescriptionProvider.php` — owns `getModuleDescription()` from metadata.php

**Migration:**
- `ModuleConfigurationService` receives both via constructor injection
- `getWebhookUrl()` delegates to `StripeUrlBuilder::getWebhookUrl()`
- `getModuleDescription()` delegates to `ModuleDescriptionProvider::getModuleDescription()`
- Interface (`ModuleConfigurationServiceInterface`) unchanged — callers unaffected
- `getSslShopBaseUrl()` removed from service (was `protected`, now on StripeUrlBuilder)

**Tests:**
- `StripeUrlBuilderTest` — 4 tests (webhook URL appends controller, strips trailing slash, handles no-slash, HTTPS)
- `ModuleDescriptionProviderTest` — 4 tests (English, fallback lang, DAO throws, empty descriptions)
- `ModuleConfigurationServiceWebhookUrlTest` — replaced 3 old URL-seam tests with 2 delegation-assertion tests

**Commit:** `ad10875`

---

### S3 — Move capturable-state policy from `StripeCaptureRequestHandler` to `CaptureService`

**Change:**
- `CaptureService::processCapture()` now owns the capturable-state precondition (AUTHORIZED or COMMITTED)
- Returns `CaptureResponse::failure('Cannot capture: contract not in capturable state (current: X)')` for other states
- `CaptureService::processCapture()` also owns PI ID resolution (providerOrderId → metadata fallback), removing the handler's pre-resolution step
- `StripeCaptureRequestHandler::processCapture()` reduced to: find contract → set context → delegate to service → propagate result
- Removed `$paymentIntentResolver` constructor arg from handler (now unused)
- Removed `PaymentIntentResolver` import from handler

**PHPMD baseline delta:** No entry was in baseline for `StripeCaptureRequestHandler`; no new entries added.

**Tests moved/added in `CaptureServiceTest`:**
- `testProcessCaptureReturnsFailureWhenContractIsPending` — state policy: PENDING rejected
- `testProcessCaptureReturnsFailureWhenContractIsFulfilled` — state policy: FULFILLED rejected
- `testProcessCaptureSucceedsForAuthorizedContract` — parity: AUTHORIZED accepted
- `testProcessCaptureSucceedsForCommittedContract` — parity: COMMITTED accepted

**Handler tests updated:**
- State-rejection tests now assert service is called and propagates failure
- `testHandleSetsErrorWhenNoPaymentIntentFound` → tests delegation pattern
- `testHandleSetsErrorWhenMetadataPaymentIntentIsEmptyString` → renamed/updated for delegation
- `testHandleUsesPaymentIntentFromMetadataWhenProviderOrderIdEmpty` → renamed to `testHandleDelegatesToCaptureServiceForContractCapture`

**Note:** S3 required two commits (integration test `testCaptureRejectsNonCapturableStates` revealed that handler's PI pre-check was intercepting before the service's state check). Resolution: removed PI pre-check entirely from handler; service now fully owns both state and PI resolution.

**Commits:** `04f647a`, `ddc0df9`

---

### S4 — Split `OrderRefundViewDataProvider`

**New collaborator:**
- `src/Stripe/Admin/StripeTransactionHistoryBuilder.php` (final) — owns transaction-history assembly and `mapPiStatusToLabel()`

**Public API:** `build(StripePaymentIntentDto $paymentIntent): array<int, array<string, mixed>>`

**Migration:**
- `OrderRefundViewDataProvider::getStripeTransactionHistory()` reduced to 8 lines (gets PI, delegates to builder)
- `mapPiStatusToLabel()` moved from provider to builder
- `StripeTransactionHistoryBuilder` injected via constructor (required arg) and registered in services.yaml
- Provider: 303 → 256 LOC

**Tests:**
- `StripeTransactionHistoryBuilderTest` — 7 tests:
  - auth+capture+refund rows
  - auth-only when no charge
  - status label mapping (requires_capture→authorized, succeeded→completed)
  - createdAt from timestamp
  - multiple refunds
  - uncaptured charge (captured=false, no capture row)

**Existing tests updated:**
- `OrderRefundViewDataProviderDtoCharacterizationTest`: pass `new StripeTransactionHistoryBuilder()` to parent constructor
- `OrderRefundViewDataProviderTest`: same
- `StripePanelApiCallCountTest`: same

**Commit:** `ddecd32`

---

## Test Counts

| Metric | Before S2 | After All |
|--------|-----------|-----------|
| Unit tests | 1069 | 1087 |
| Unit assertions | 2603 | 2643 |
| Integration tests | 141 | 141 |

Net: +18 unit tests, +40 assertions. All green.

---

## Gate Results

```
./bin/pre-commit-check.sh --full
✓ ALL CHECKS PASSED — Status: COMMITABLE
```

- PHPCS: 0 errors (PSR-12)
- PHPStan: 0 errors (level max, 154 files)
- PHPMD: 0 new violations (baseline unchanged — 3 entries)
- PHPUnit Unit: 1087 tests, 2643 assertions — GREEN
- PHPUnit Integration: 141 tests, 356 assertions, 53 skipped — GREEN

---

## PHPMD Baseline State

Unchanged (3 entries, same as sprint start):
- `StripeAdapter`: TooManyMethods, TooManyPublicMethods
- `StripeOrderController`: WeightedMethodCount

No new entries added. No entries removed (StripeCaptureRequestHandler had no entry — confirmed).

---

## R-1…R-10 Checklist

- [x] **R-1 TDD**: RED shown before GREEN for all 3 new collaborators. Pre-existing tests updated to reflect delegation (not re-implemented).
- [x] **R-2 SOLID**: God-object split (S2 removes URL+description from config service, S4 removes history assembly from provider). PHPMD baseline not grown.
- [x] **R-3 LI**: No security-weakening overrides; no instanceof downcasts.
- [x] **R-4 DI**: All collaborators constructor-injected; registered in services.yaml; no new ContainerFactory calls.
- [x] **R-5 Clean Code**: Methods ≤25 lines; no else; explicit imports (RuntimeException use import restored); no magic literals.
- [x] **R-6 DevOps-first**: `pre-commit-check.sh --full` green; oe:cache:clear + php restart done after services.yaml changes; no new suppressions.
- [x] **R-7 Event-driven**: No new events/handlers; existing event flow unchanged.
- [x] **R-8 Contract-aware**: State check in CaptureService uses named `isAuthorized()`/`isCommitted()` — no direct state writes.
- [x] **R-9 No overengineering**: No speculative abstractions; no unused methods.
- [x] **R-10 Persistence**: No new direct DB writes in touched code; CaptureService persists via `transactionRepository.save()` (existing behavior unchanged).

---

## Commit Hashes

- S2: `ad10875`
- S3: `04f647a` + `ddc0df9` (integration-test fix required second commit)
- S4: `ddecd32`
