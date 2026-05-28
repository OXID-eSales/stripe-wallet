# Sprint 114.9 — Consolidate config & session helpers

**Module:** `extensions/stripe`
**Priority:** P2 (DRY — smaller, low-risk consolidations)
**Findings:** D2 (`getToken` ≡ `getSecretKey`), D7 (payment-prefix duplicated 6×), D6 (stale-checkout cleanup ×4), D8 (OPC modal-id ×2), D9 (`isConfigured` two definitions)
**Mode:** one commit per finding, TDD-first. Small surface each.
**Depends on:** none. (114.2 may consume the D7 prefix helper — land D7 early if 114.2 wants it.)
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-5.4** (prefix/mode constants), **R-9.3** (one accessor / one cleanup), **R-10.4** (D6 stale-cleanup mutations go through the service, only reads stay direct).

## 1. Why

Five independent small duplications, each with drift risk:

- **D2** — `ModuleConfigurationService::getToken()` and `getSecretKey()` (lines 114-137) are byte-identical; consumers are split between them.
- **D7** — the `'oe_payments_stripe_'` payment-id prefix is hardcoded in 6 sites (`Model/Order.php:84,132`, `Model/Payment.php:85,176,177`, `Controller/PaymentController.php:127`, `PaymentHandler/StripePaymentHandler.php:47`) with two idioms (`strpos(...)===0` vs `str_starts_with`). `StripeDefinitions` has exact-match `isStripePayment()` but no prefix helper.
- **D6** — stale-checkout cleanup duplicated 4× (`StripeOrderController:80,387,423` + `PaymentController:58`); `PaymentController` reaches `Registry::getSession()` directly and forgets `stripe_payment_intent_id`/`stripe_client_secret`.
- **D8** — `getOpcModalId()` + `'oe_opc_modal_session'` key copy-pasted across `OpcModalSuccessUrlHandler` and `OpcModalCancelUrlHandler`.
- **D9** — `isConfigured()` defined two divergent ways (service requires webhook secret, controller doesn't).

## 2. Goals & per-finding plan

### D2 — one token accessor
- **G:** Keep one of `getToken()`/`getSecretKey()`; make the other a thin alias or delete + repoint callers (`ConfigurationValidator`, `StripeClientFactory`, `ContractTokenService`).
- **TDD:** existing `ModuleConfigurationServiceTest` — assert the surviving accessor returns the mode-correct key; assert callers still resolve the same value.

### D7 — shared prefix helper
- **G:** Add `StripeDefinitions::PAYMENT_PREFIX = 'oe_payments_stripe_'` + `public static function isStripePaymentMethod(string $id): bool`. Route all 6 sites through it (one idiom). Correct the misleading `Payment` docblock/`@example` (C7 overlap).
- **TDD:** `StripeDefinitionsTest::isStripePaymentMethod` data provider (wallet id → true, `oxidcashondel` → false, empty → false). Then update the 6 call sites; their existing tests stay green.

### D6 — single stale-cleanup
- **G:** Move "cleanup previous Stripe checkout attempt" into `RetryCleanupService` (or a `StaleCheckoutCleaner`) with the **complete** session-key clear set (incl. `stripe_payment_intent_id`/`stripe_client_secret`). All 4 sites call it via `ControllerRequestHelper`. `PaymentController` stops touching `Registry::getSession()` directly.
- **TDD:** `RetryCleanupServiceTest` (or new) — asserts the full key set is cleared and `cleanupPreviousAttempt()` invoked once; controller tests assert delegation.

### D8 — OPC modal-session reader
- **G:** Extract `OpcModalSessionReader` injected into both handlers; centralize the `'oe_opc_modal_session'` key constant.
- **TDD:** `OpcModalSessionReaderTest` (request param → session var → `modalId`, with the `is_string`/try-catch guards). Then both handlers delegate. (Closes a coverage gap — these handlers are untested today, see 114.13.)

### D9 — single `isConfigured`
- **G:** Controller `ModuleConfiguration::isConfigured()` delegates to the service's `isConfigured()`. Decide the canonical definition (token + webhook secret) and document it.
- **TDD:** test the chosen definition once on the service; controller test asserts delegation.

## 3. Global goals

- **G-all.** One commit per finding (independently revertable).
- No new magic literals; everything via `StripeDefinitions`.
- `./bin/pre-commit-check.sh --full` green after each.

## 4. Risks & rollback

- **D6 risk:** broadening the cleared key set could clear a var another flow still needs mid-checkout. Diff the union of keys across the 4 sites; clear exactly that union; test the retry path still works.
- **D9 risk:** tightening the controller's `isConfigured()` to also require the webhook secret may hide the config form earlier than today — confirm that's the intended UX (it is, per the service definition) or pick the looser definition deliberately.
- **Rollback:** per-finding commits.

## 5. Definition of Done

- D2/D6/D7/D8/D9 each Fixed with a dedicated commit + test.
- `grep -rn "oe_payments_stripe_'" src/` → only `StripeDefinitions` (D7).
- Completion report enumerates the unified session-key set (D6) and the canonical `isConfigured` rule (D9).
