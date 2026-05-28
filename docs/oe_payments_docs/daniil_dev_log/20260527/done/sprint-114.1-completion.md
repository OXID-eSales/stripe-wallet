# Sprint 114.1 Completion Report — Fix hardcoded `shopId: 1` in transaction audit

**Date:** 2026-05-27
**Branch:** `b-7.4.x-code-review-STRP-145`
**Commit:** `6d4c0e1`
**Status:** DONE

---

## Goals Met

- **G1.** `CaptureService::recordCaptureTransaction()` now writes `(int) $this->shopAdapter->getShopId()`.
- **G2.** `WebhookContractFulfillmentHandler::recordAudit()` now writes `(int) $this->shopAdapter->getShopId()`.
- **G3.** `grep -rn "shopId: 1" src/` → empty (verified).
- **G4.** `ShopAdapterInterface` constructor-injected in all three classes; no static fetch.
- **G5.** `./bin/pre-commit-check.sh --full` green — PHPCS 0 errors, PHPStan 0 errors, PHPMD 0 new violations, all PHPUnit tests pass.

---

## TDD Evidence

### RED — CaptureService (`testCaptureTransactionUsesInjectedShopId`)

```
PHPUnit 11.5.55

E                                                                   1 / 1 (100%)
1) OxidEsales\Payments\Tests\Unit\Stripe\Service\CaptureServiceTest::testCaptureTransactionUsesInjectedShopId
TypeError: CaptureService::__construct(): Argument #5 ($logger) must be of type
?Psr\Log\LoggerInterface, MockObject_ShopAdapterInterface given
```

Fails for the right reason: constructor has no `$shopAdapter` parameter yet.

### RED — WebhookContractFulfillmentHandler (`testRefundRecordingUsesInjectedShopId`)

```
PHPUnit 11.5.55

F                                                                   1 / 1 (100%)
1) ...WebhookContractFulfillmentHandlerShopIdTest::testRefundRecordingUsesInjectedShopId
Failed asserting that 1 is identical to 7.
```

Fails for the right reason: `shopId: 1` is hardcoded; the injected shop id 7 is not used.

### GREEN — both new tests

Both new tests pass after the production fix. All existing tests pass after updating
their constructors to provide the new `$shopAdapter` argument.

---

## Test Counts

| Suite | Before | After |
|---|---|---|
| Unit | 897 tests, 2169 assertions | 898 tests, 2172 assertions |
| Full (Unit + Integration) | 1027 tests, 2495 assertions | 1036 tests, 2545 assertions |

Net new: +2 targeted RED→GREEN tests (1 in `CaptureServiceTest`, 1 in `WebhookContractFulfillmentHandlerShopIdTest`).

---

## Files Changed

**Production (3 files):**
- `src/Stripe/Service/CaptureService.php` — added `ShopAdapterInterface` import + constructor arg; replaced `shopId: 1` with `(int) $this->shopAdapter->getShopId()`.
- `src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php` — same.
- `src/Stripe/WebhookHandler/PaymentIntentSucceededHandler.php` — same (arg inserted before `LoggerInterface $logger`).

**DI wiring (1 file):**
- `services.yaml` — added `$shopAdapter: '@OxidEsales\PaymentBase\Adapter\ShopAdapterInterface'` to:
  - `CaptureServiceInterface` definition (~line 701)
  - `WebhookContractFulfillmentHandlerInterface` definition (~line 831)
  - `PaymentIntentSucceededHandler` definition (~line 1035)

**Tests updated (8 files):**
- `tests/Unit/Stripe/Service/CaptureServiceTest.php` — new `ShopAdapterInterface` field in setUp; updated `createService()`; added `testCaptureTransactionUsesInjectedShopId`.
- `tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerShopIdTest.php` — NEW FILE; `testRefundRecordingUsesInjectedShopId`.
- `tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerTest.php` — added `ShopAdapterInterface` field; added `makeHandler()` factory; replaced 15 inline constructors.
- `tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerAuditTest.php` — same pattern.
- `tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerCancelOrderTest.php` — same pattern.
- `tests/Unit/Stripe/Webhook/Handler/PaymentIntentSucceededHandlerTest.php` — added `$shopAdapter` field; updated handler construction.
- `tests/Integration/Stripe/Controller/Admin/ManualCaptureIntegrationTest.php` — updated 3 `new CaptureService(...)` calls.
- `tests/Integration/Stripe/Webhook/WebhookContractTransitionTest.php` — added `$shopAdapter` field; updated handler construction.

---

## Grep Proof

```
$ grep -rn "shopId: 1" src/
(empty)
```

---

## Cache / Opcache

- `bin/oe-console oe:cache:clear` → "Cleared cache files"
- `docker compose restart php` → container restarted

---

## R-1…R-10 Gate Checklist

- [x] **R-1 TDD:** RED shown before GREEN for both `CaptureService` and `WebhookContractFulfillmentHandler`. Failures were for the right reason (wrong arg type / hardcoded literal). No method re-implemented in a test double.
- [x] **R-2 SOLID:** No god-objects introduced. `ShopAdapterInterface` is a pre-existing, focused interface. PHPMD baseline unchanged (0 new entries).
- [x] **R-3 LI:** No security-weakening overrides. No `instanceof` downcasts added. PHPStan level max clean.
- [x] **R-4 DI:** `ShopAdapterInterface` constructor-injected in all three classes. `$logger` stays as trailing optional arg in `CaptureService`. No `ContainerFactory::getInstance` added. All three services wired in `services.yaml`.
- [x] **R-5 Clean Code:** `(int) $this->shopAdapter->getShopId()` matches the existing idiom in `StripeCaptureRequestHandler:323,360`. No `else` added. All imports explicit. No magic literals remain.
- [x] **R-6 DevOps-first:** `pre-commit-check.sh --full` green (PHPCS 0, PHPStan 0, PHPMD 0 new, PHPUnit 1036 pass). Cache cleared + PHP restarted after `services.yaml` changes.
- [x] **R-7 Event-driven:** No changes to event routing. Writes remain inside services reached by events (no new direct paths).
- [x] **R-8 Contract-aware:** No contract state transitions changed. Fix is purely in the `Transaction` value-object construction.
- [x] **R-9 No overengineering:** Exactly three `shopId: 1` literals replaced. No other changes. No new abstractions.
- [x] **R-10 Persistence:** Writes stay in services/handlers reached via the event system. `TransactionRepositoryInterface::save()` is called from `CaptureService::recordCaptureTransaction()` and `WebhookContractFulfillmentHandler::recordAudit()` — same paths as before, now with the correct shop id.
