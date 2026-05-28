# Sprint 114.1 — Fix hardcoded `shopId: 1` in transaction audit

**Module:** `extensions/stripe`
**Priority:** P0 (functional bug — multi-shop)
**Findings:** C1 (Clean Code / latent bug)
**Mode:** single atomic commit, TDD-first. ~3 production files, ~3 test additions.
**Depends on:** none.
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-4** (DI the `ShopAdapterInterface`), **R-1** (RED test first), **R-10** (the transaction write stays inside the event-reached service/repository path).

## 1. Why

Transaction audit records are written with `shopId: 1` hardcoded in three
places — including the **active** capture path:

- `src/Stripe/Service/CaptureService.php:117` — `shopId: 1,` (wired via `CaptureServiceInterface`, services.yaml:701)
- `src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php:146`
- `src/Stripe/WebhookHandler/PaymentIntentSucceededHandler.php:120`

In any non-default-shop context the `oe_payments_transaction` row points at
shop 1, corrupting per-shop reconciliation and reporting. The correct pattern
already exists: `StripeCaptureRequestHandler.php:323,360` injects
`ShopAdapterInterface` and writes `shopId: (int) $this->shopAdapter->getShopId()`.

> Note: `PaymentIntentSucceededHandler` is dead today (untagged — see 114.4/114.5).
> If 114.5 deletes it first, drop that file from this sprint's scope. `CaptureService`
> and `WebhookContractFulfillmentHandler` are live and must be fixed regardless.

## 2. Goals

- **G1.** `CaptureService::recordCaptureTransaction()` writes the real shop id.
- **G2.** `WebhookContractFulfillmentHandler` (refund-recording path) writes the real shop id.
- **G3.** No new hardcoded `shopId` literal remains in `src/` (`grep -rn "shopId: 1" src/` → empty, minus any file deleted by 114.5).
- **G4.** `ShopAdapterInterface` is constructor-injected (DIP), not fetched statically.
- **G5.** `./bin/pre-commit-check.sh --full` green; PHPStan level max clean.

## 3. Scope inventory

| File | Change |
|---|---|
| `src/Stripe/Service/CaptureService.php` | Add `private readonly ShopAdapterInterface $shopAdapter` ctor arg (before the optional `?LoggerInterface`); replace `shopId: 1` with `(int) $this->shopAdapter->getShopId()`. |
| `src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php` | Same injection + replacement at :146. |
| `src/Stripe/WebhookHandler/PaymentIntentSucceededHandler.php` | Same (skip if 114.5 deletes it first). |
| `services.yaml` | Add `$shopAdapter: '@OxidEsales\PaymentBase\Adapter\ShopAdapterInterface'` (or the existing alias used by `StripeCaptureRequestHandler`) to the affected service definitions. |
| `tests/Unit/Stripe/Service/CaptureServiceTest.php` | New test (G1). |
| `tests/Unit/Stripe/WebhookHandler/WebhookContractFulfillmentHandlerTest.php` | New test (G2). |

### Not touched
- The `Transaction` value object (payment-base) — its `shopId` arg already accepts the value.
- `StripeCaptureRequestHandler` — already correct.

## 4. TDD plan (RED first)

1. **`captureTransactionUsesInjectedShopId`** — arrange a `ShopAdapterInterface` mock returning `getShopId()` = `5`; a `PaymentContractInterface` mock; a `TransactionRepositoryInterface` mock. Capture the `Transaction` passed to `save()` via a callback/`willReturnCallback`. Assert `$captured->getShopId() === 5` (or the `Transaction` accessor name). **RED** today because the constructor has no `$shopAdapter`.
2. **`refundRecordingUsesInjectedShopId`** — analogous in `WebhookContractFulfillmentHandlerTest`.
3. (Guard) **`noHardcodedShopIdRemains`** — optional structural test or a CI grep in the completion report.

Use real value objects where possible; mock only `ShopAdapterInterface`, repositories, and `PaymentContractInterface` (per "mock interfaces, not concretions").

## 5. Implementation steps

1. Add `use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;` to each file.
2. Inject the dependency; keep `?LoggerInterface $logger = null` last (constructor optional args must stay trailing).
3. Replace the literal. Cast to `int` to match the existing `(int) getShopId()` convention.
4. Wire `services.yaml`. Mirror exactly the argument key `StripeCaptureRequestHandler` uses.
5. `oe:cache:clear` + `docker compose restart php` (opcache) before running integration tests.

## 6. Risks & rollback

- **Risk:** `getShopId()` returns a string in some OXID versions → keep the `(int)` cast (already the established idiom).
- **Risk:** DI mis-wire → `oe:module:activate` fails. Verify with `bin/oe-console oe:module:activate oe_payments_stripe_wallet` (non-silent output expected).
- **Rollback:** single commit; `git revert`.

## 7. Definition of Done

- G1–G5 met; two new RED→GREEN tests committed.
- `grep -rn "shopId: 1" src/` empty (modulo files deleted by 114.5).
- Completion report in `done/` with before/after test counts and the grep proof.
- Memory `project_code_review_114_latent_bugs.md` item #1 marked fixed.
