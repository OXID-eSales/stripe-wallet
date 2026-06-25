# Logging Architecture — payment-base & Stripe module

**Date:** 2026-06-24
**Scope:** How log creation is managed across `payment-base` (provider-agnostic) and the `stripe` extension.

---

## TL;DR

There are **three distinct logging channels**, all defined as abstractions in `payment-base` and consumed/specialised by `stripe`:

| Channel | Abstraction (payment-base) | Backing store | Purpose |
|---------|---------------------------|---------------|---------|
| **Operational logs** | `Psr\Log\LoggerInterface` (PSR-3) | `oxideshop.log` (OXID Monolog) | App-level info/warning/error |
| **File audit logs** | `FileLoggerInterface` + `AbstractFileLoggerFactory` | `log/stripe/stripe_<type>_<date>.log` | Date-rotated structured audit trail |
| **Webhook DB log** | `WebhookLogServiceInterface` / `WebhookLogRepositoryInterface` | `oe_payments_webhooklogs` table | Idempotency + webhook lifecycle status |

**Division of labour:** `payment-base` owns the *interfaces, the file-writing engine, the factory template, and the DB repository*. `stripe` owns the *concrete factories* (which decide filenames/prefixes) and the *wiring* in `services.yaml`.

---

## 0. Test coverage & critical findings

### 🔴 Critical finding — the logging on/off toggle is ORPHANED

There is **only one** logging-related checkbox in the admin UI today:

- **`blStripeLogTransactionInfo`** — label *"Log result of transaction handling"* (DE: *"Ergebnisse von Transaktions-Verarbeitung loggen"*), default `1`, group `STRIPE_GENERAL`, `metadata.php:95`. Help text still points at a legacy path: *"Log file to be found here: SHOPROOT/log/StripeTransactions.log"* — a file the current code never writes.

It is read by `ModuleConfigurationService::isTransactionLoggingEnabled()` (`src/Stripe/Service/ModuleConfigurationService.php:187`), **which is never called by any production code** (verified by grep — only appears in the interface and the coverage cache). A second method `isLoggingEnabled()` (`:295`) reads a config key `blStripeEnableLogging` that **does not exist in `metadata.php`** — also never called.

**Consequence:** all four file-log channels (requests, webhooks, events, reconciliation) and the DB webhook log are **unconditionally always-on**. `AbstractFileLoggerFactory::create()` always returns a real `FileLogger` — it never consults config and never returns `NullFileLogger`. The merchant has *no working way* to switch logging off, despite the checkbox suggesting otherwise. The help text references a log file that no longer exists.

### Test catalogue

**(a) Tests proving logs ARE written — 36 tests**
- `payment-base/tests/Unit/Service/FileLoggerTest.php` (9) — writes to file, timestamp/prefix/JSON-context format, append, dir creation, newline.
- `payment-base/tests/Unit/Service/FileLoggerPermissionsTest.php` (3) — dir created `0750`, existing dir untouched.
- `payment-base/tests/Unit/Service/WebhookLogServiceTest.php` (8) — DB log create/markProcessed/markFailed/exists/find, status constants.
- `stripe/tests/Unit/Stripe/Service/RequestLogServiceTest.php` (9) — `logRequest`/`logException` delegate to fileLogger, params, timestamp, default-500 code.
- `stripe/tests/Unit/Stripe/Service/OxpaidReconciliationServiceTest.php` (2) — reconciliation logs ERROR / processes all.
- `stripe/tests/Unit/Stripe/Service/WebhookLogServicePayloadParsingTest.php` (4) — webhook log reports correct PI id per event type, falls back to "unknown".
- `stripe/tests/Unit/Stripe/EventSystem/Handler/AbstractStripeRequestHandlerTest.php` (1) — `logEvent()` delegates to fileLogger when injected.

**(b) Tests proving logs are NOT written — 4 tests, but only the *null-object* path**
- `payment-base/tests/Unit/Service/NullFileLoggerTest.php` (3) — `NullFileLogger::log()` is a no-op.
- `AbstractStripeRequestHandlerTest::logEventIsNoOpWhenNoLoggerProvided` (1) — handler no-ops when no logger injected.

> ⚠️ These prove the *null object* does nothing. **No test asserts that disabling the config toggle results in no log writes** — because nothing wires the toggle to logger selection in the first place.

**(c) Config-toggle tests — 1 test, existence only**
- `stripe/tests/Integration/Module/MetadataTest.php:172` — asserts `blStripeLogTransactionInfo` is present in metadata. Does not test runtime effect.

### 🔴 Critical finding — frontend `console.log` is uncontrolled

Separate from the backend, the **frontend JS has no logging control at all** — not even an orphaned one:

- **31 raw `console.*` calls** in `resources/build/js/controllers/*.js` (`stripe_order_controller.js`, `order_submit_controller.js`), unconditional, no wrapper/`debug()` helper.
- The **production bundle `assets/js/stripe-frontend.min.js` still ships 25 console calls** — esbuild does `minify: true` but there is **no `drop_console`/`pure_funcs`/terser** (verified absent in `resources/esbuild` + `package.json`). So `console.log` fires for every shopper in live mode.
- **Nothing crosses the PHP→JS boundary to gate it.** `ViewConfig::isStripeDevelopmentMode()` (`src/Stripe/Core/ViewConfig.php:50`) only chooses `.js` vs `.min.js`; both log unconditionally. No `data-*-debug` value, no `window.oStripe.debug`, no Stimulus value. `oStripe` carries i18n strings only.

→ Remediation: same sprint, **Phase 5** (config-gated `debug()` wrapper driven by `sStripeLogLevel == debug`, not the rejected `blStripeLogFrontend` key).

→ **As of the logging-control sprint (Phase 5 + 5b live-verification):** resolved. The flag is surfaced as `oStripe.debug` and drives `app.js`'s `Stimulus.debug` + init logs, the two migrated controllers' `debug()` wrapper, and the OPC footer widget's inline `sdbg()` gate; the prod bundle is `pure`-stripped (keeping `console.error`). **Two non-obvious traps the live run exposed:** (1) the running shop needs `docker compose restart php` + cache clear + asset reinstall — PHP-FPM opcache keeps old always-on classes otherwise; (2) `.local`/`.dev`/`.test` are dev domains, so `isStripeDevelopmentMode()` serves the **non-stripped dev bundle** — frontend logging therefore must be gated at *runtime* (`oStripe.debug`), never on build target / `NODE_ENV` / domain. Residual console noise on dev domains (`[CheckoutFooterManager]`, `apex-*`, `details:{stripe-…}` Stimulus lifecycle) is emitted by the OPC/apex theme's own `Stimulus.debug`, not the Stripe module — out of scope, and silent in production.

### 🔴 Gap summary (as-found / pre-sprint)

| Want | Status |
|------|--------|
| Toggle exists in metadata | ✅ `blStripeLogTransactionInfo` |
| Toggle is read at runtime to gate logging | ❌ orphaned method, never called |
| Separate toggle for webhook logging | ❌ does not exist |
| Control over payment-base DB webhook log | ❌ always-on, no toggle |
| Per-channel control (requests/webhooks/events/reconciliation) | ❌ all-or-nothing at best, currently none |
| Test: logs NOT written when disabled | ❌ 0 tests |
| Help text matches reality | ❌ points at non-existent `StripeTransactions.log` |
| Frontend `console.*` controllable | ❌ uncontrolled; 25 calls ship in prod bundle |

→ Remediation tracked in **Sprint: harmonize-logging-control** (`../sprints/`).

### ✅ Resolution — implemented 2026-06-24/25 (logging-control sprint, Phases 1–6)

All eight gaps above are closed. Summary per item:

| Want | Now | Mechanism |
|------|-----|-----------|
| Toggle exists in metadata | ✅ | `sStripeLogLevel` (off/errors/normal/debug) + `blStripeLogWebhooks` added to `STRIPE_LOGGING` group in `metadata.php`. `blStripeLogTransactionInfo` retained as deprecated legacy seed. |
| Toggle is read at runtime to gate logging | ✅ | `ModuleConfigurationService::getLogLevel()` resolves the select, seeds from legacy bool when unset. Dead `isLoggingEnabled()` removed. Per-channel helpers (`isRequestLoggingEnabled()`, `isReconciliationLoggingEnabled()`, `isEventLoggingEnabled()`, `isWebhookLoggingEnabled()`, `isFrontendDebugEnabled()`) drive factory closures. |
| Separate toggle for webhook logging | ✅ | `blStripeLogWebhooks` bool — independent of level; gates both file-log and DB payload/PSR-3 mirror without touching the idempotency claim row. |
| Control over payment-base DB webhook log | ✅ | `WebhookLogService` (payment-base) gains `?\Closure $shouldLogPayload`. `claimEvent()` row always written (idempotency intact); `OXPAYLOAD` + PSR-3 mirror written only when closure returns true. Stripe wires `isWebhookLoggingEnabled()` as that closure. |
| Per-channel control | ✅ | Level → channel mapping: requests ≥ errors; reconciliation ≥ normal; events == debug; webhooks = `blStripeLogWebhooks` AND ≥ normal; frontend == debug. `AbstractFileLoggerFactory` (payment-base) gains optional `?\Closure $isEnabled = null`; returns `NullFileLogger` when closure returns false. Null-path is byte-identical to previous behavior — PayPal/Unzer untouched. |
| Test: logs NOT written when disabled | ✅ | `FileLoggerFactoryGatingTest` asserts `assertFileDoesNotExist` per level. `WebhookLogServicePayloadGateTest` asserts payload absent / present. Legacy-seed tests in `ModuleConfigurationServiceLogLevelTest`. |
| Help text matches reality | ✅ | Stale `StripeTransactions.log` path removed from both `en/stripe_lang.php` and `de/stripe_lang.php`. `AdminSettingsTranslationsTest` (Phase 2) asserts the key is absent. |
| Frontend `console.*` controllable | ✅ | esbuild **`pure`** list (`console.log/info/debug/warn/trace`) — NOT `drop: ['console']`, which would also strip `console.error`. Runtime `debug()` wrapper (`debug.js`, aliased `globalThis.console`) gated by `data-*-stripe-debug-value`. `ViewConfig::isStripeDebug()` (→ `isFrontendDebugEnabled()`, level==debug) passes the flag. **Phase 5b (live-verified):** the flag is also surfaced globally as `oStripe.debug` (`stripe_i18n.html.twig`); `app.js` drives `Stimulus.debug` from it (not `NODE_ENV`/domain) and gates its init logs; the OPC footer widget (`stripe-footer.html.twig`) routes its 10 inline `console.log/warn` through a runtime `sdbg()` gate (3 `console.error` kept). Prod bundle: 0 stray `console.log/warn/debug`, 7 `console.error`. Full Playwright checkout at level `off` → **0 Stripe-emitted console logs** (`FrontendLoggingGated.spec.ts`). |

**Config model (implemented):**

| Setting | Type | Default | Meaning |
|---------|------|---------|---------|
| `sStripeLogLevel` | select | `normal` | `off` → NullFileLogger everywhere; `errors` → exceptions only; `normal` → requests + reconciliation; `debug` → + events + frontend console |
| `blStripeLogWebhooks` | bool | `1` | file `stripe_webhooks_*.log` + DB `OXPAYLOAD`/PSR-3 mirror, independent of level |

**Legacy back-compat:** when `sStripeLogLevel` is unset/empty, `blStripeLogTransactionInfo == 1` → effective `normal`; `== 0` → effective `off`. Merchant who had logging off stays off. `blStripeLogTransactionInfo` is deprecated (hidden from the form but readable for one release).

---

## 1. Operational logging (PSR-3 / Monolog)

The baseline everywhere. Services accept an optional `?LoggerInterface` and default to `NullLogger` for graceful degradation:

```php
public function __construct(..., ?LoggerInterface $logger = null) {
    $this->logger = $logger ?? new NullLogger();
}
```

- **Binding:** `@oxid_esales.monolog.logger` → writes to `oxideshop.log`.
- **Levels used:** `error()` (failures), `warning()` (recoverable), `info()` (operations), `debug()` (detail).
- **Consumers (stripe):** `CaptureService`, `RefundService`, `CancelAuthorizationService`, `OxidStockRestorationService`, `CheckoutSessionService`, `StripeCustomerService`, all webhook event handlers, `StripeWebhookProcessor`.
- **Fallback path:** Frontend controllers without DI (`WebhookController`, `PaymentController`, `StripeOrderController`) call `Registry::getLogger()` directly — only for init/cleanup failures.

> ⚠️ Reminder from prior lessons: OXID's default log level is `error`, so `->info()`/`->warning()` to Monolog silently drop unless the level is lowered. The file-audit channel below exists partly to sidestep this.

---

## 2. File audit logging (`FileLoggerInterface`)

The structured audit trail. Independent of Monolog's level filtering.

→ As of the logging-control sprint: no longer unconditionally always-on. Each channel factory is constructed with a `?\Closure $isEnabled` that returns false when the effective log level is below the channel's threshold (see Resolution section above). The `NullFileLogger` null-object pattern ensures no file is written without any conditional branching in the business code.

### Engine (payment-base)
- **Interface:** `payment-base/src/Service/FileLoggerInterface.php` — `log(string $message, array $context = []): void`
- **Impl:** `payment-base/src/Service/FileLogger.php` — writes `[YYYY-MM-DD HH:MM:SS] PREFIX message {json-context}`, creates dirs at mode `0750`, uses `LOCK_EX`.
- **No-op:** `payment-base/src/Service/NullFileLogger.php` — used when logging disabled.

### Factory template (payment-base)
`payment-base/src/Service/Factory/AbstractFileLoggerFactory.php` — template-method `create()` joins `getShopDirectory()` + `getLogFile()` and returns a `FileLogger` with `getPrefix()`. Subclasses fill the three template methods.

→ As of the logging-control sprint: `AbstractFileLoggerFactory` accepts an optional `?\Closure $isEnabled = null` constructor arg. When the closure returns `false`, `create()` returns `NullFileLogger` instead of `FileLogger`. Passing `null` (the default) preserves the previous always-on behavior — PayPal/Unzer constructors are unchanged.

### Concrete factories (stripe) — these decide filename + prefix
All under `stripe/src/Stripe/Service/Factory/`, shop dir from `Registry::getConfig()->getConfigParam('sShopDir')`:

| Factory | File | Prefix | Service id |
|---------|------|--------|-----------|
| `RequestFileLoggerFactory` | `log/stripe/stripe_requests_<date>.log` | `REQUEST` | `stripe.request_file_logger` |
| `WebhookFileLoggerFactory` | `log/stripe/stripe_webhooks_<date>.log` | `WEBHOOK` | `stripe.webhook_file_logger` |
| `EventFileLoggerFactory` | `log/stripe/stripe_events_<date>.log` | `EVENT` | `stripe.events.file_logger` |
| `ReconciliationFileLoggerFactory` | `log/stripe/stripe_reconciliation_<date>.log` | `RECONCILE` | `stripe.reconciliation.file_logger` |

Wiring pattern in `stripe/services.yaml`:
```yaml
stripe.webhook_file_logger:
  class: OxidEsales\PaymentBase\Service\FileLoggerInterface
  factory: ['@OxidEsales\Payments\Stripe\Service\Factory\WebhookFileLoggerFactory', 'create']
```

### Services that wrap the file loggers
- **`RequestLogService`** (stripe) impl of `RequestLogServiceInterface` (payment-base) — `logRequest()` / `logException()` for Stripe API calls. Injected with `$fileLogger: @stripe.request_file_logger` **plus** `$fallbackLogger: @oxid_esales.monolog.logger` (falls back to Monolog if the file write throws). Used by capture/refund/cancel handlers.
- **`WebhookLogService`** (stripe) impl of `WebhookLogServiceInterface` — `logReceived()` / `logResult()`. Injected with `@stripe.webhook_file_logger`. **Must be `public: true`** because `WebhookController.init()` pulls it from the container directly (no constructor DI on frontend controllers).
- **`OxpaidReconciliationService`** (stripe) — `logReconciliation(...)` via `@stripe.reconciliation.file_logger`.
- **Event handlers** take an optional `?FileLoggerInterface $eventLogger` (`@stripe.events.file_logger`) and log flow through a private `logEvent()` guard (no-op if null). E.g. `StripeContractCreationHandler`, `StripeCheckoutSessionHandler`, `OrderPaymentCompletedHandler`, `EarlyOrderCreationHandler`, `PaymentAuthorizedEventHandler`.

---

## 3. Webhook DB logging (`oe_payments_webhooklogs`)

This is the part most easily confused. **The DB table is for idempotency + lifecycle status, NOT for the file audit trail.** Webhook *audit* text goes to `stripe_webhooks_<date>.log` (channel 2); webhook *state* goes to the DB.

### Schema (payment-base migration `Version20251031140200.php`)
`oe_payments_webhooklogs`: `OXID`, `OXEVENTID` (UNIQUE — the idempotency key), `OXEVENTTYPE`, `OXPROVIDER` (stripe/paypal/…), `OXCONTRACTID` (FK→contract), `OXSTATUS` (received/claimed/processed/failed), `OXRECEIVEDAT`, `OXPROCESSEDAT`, `OXERROR`, `OXPAYLOAD` (JSON).

### Classes (payment-base)
- **Service:** `WebhookLogService` / `WebhookLogServiceInterface` — `logEventReceived()`, `markEventProcessed()`, `markEventFailed()`, `eventExists()`, `findByEventId()`. (Provider-agnostic; logs PSR-3 alongside.)
- **Repository:** `DoctrineWebhookLogRepository` / `WebhookLogRepositoryInterface` — `save()`, `existsByEventId()`, `findByEventId()`, `updateStatus()`, and the critical **`claimEvent($eventId, $provider, $eventType)`**: an *atomic* insert against the `OXEVENTID` unique constraint that prevents duplicate/concurrent processing.

→ As of the logging-control sprint: `WebhookLogService` (payment-base) accepts an optional `?\Closure $shouldLogPayload = null`. The `claimEvent()` path is NEVER gated (idempotency must remain unconditional). `OXPAYLOAD` storage and PSR-3 logging inside `logEventReceived()` / `logEventResult()` are written only when the closure returns `true` (or is `null`). Stripe wires `fn(): bool => $this->config->isWebhookLoggingEnabled()` as that closure.
- **Entity:** `Webhook/WebhookLog.php`.

### How stripe uses it
`StripeWebhookProcessor` extends `AbstractWebhookProcessor` (payment-base), which holds the `WebhookLogRepositoryInterface`. Stripe calls `claimEvent()` for the race-safe idempotency gate — **not** for human-readable logging.

> Note: payment-base's own `WebhookLogService` (the DB one) and stripe's `WebhookLogService` (the file one) **share a class name but are different classes in different namespaces** serving different stores. Don't conflate them.

---

## Webhook request flow (where each channel fires)

1. `WebhookController.init()` → fetches `WebhookLogServiceInterface` (file) + `StripeWebhookProcessor` from container.
2. `webhookLogger->logReceived(payload, signature, remoteIp)` → **file** (`stripe_webhooks_*.log`).
3. `StripeWebhookProcessor`:
   - `claimEvent()` → **DB** (idempotency).
   - dispatches to tagged `stripe.webhook_handler` handlers (e.g. `PaymentIntentSucceededWebhookHandler`) → **PSR-3/Monolog** operational logs.
   - `markEventProcessed()` / `markEventFailed()` → **DB** status.
4. `webhookLogger->logResult(payload, result, httpCode)` → **file**.

So a single webhook touches all three channels, each with a clear role: file = audit narrative, DB = state+idempotency, Monolog = operational detail.

---

## What stripe consumes from payment-base

- `Psr\Log\LoggerInterface` (PSR-3, re-exposed via OXID Monolog)
- `FileLoggerInterface` + `FileLogger` + `NullFileLogger` + `AbstractFileLoggerFactory`
- `RequestLogServiceInterface` (stripe provides the impl)
- `WebhookLogServiceInterface` + `WebhookLogRepositoryInterface` + `DoctrineWebhookLogRepository` (DB, used via `AbstractWebhookProcessor`)

stripe adds: the 4 concrete factories, `RequestLogService`/`WebhookLogService`/`OxpaidReconciliationService` impls, and all the `services.yaml` wiring.

---

## Design notes / gotchas

- **Provider-agnostic by construction:** every logging abstraction lives in payment-base so PayPal/Unzer/Klarna reuse identical plumbing — only the concrete factory (filename/prefix) is provider-local.
- **File channel bypasses Monolog level gating** — that's the point; audit must persist regardless of `oxideshop.log` level. → As of the logging-control sprint: "always-on" is now conditional on `sStripeLogLevel != off`; the bypass-of-Monolog-level reasoning still applies within that gate.
- **`public: true` on `WebhookLogServiceInterface` (stripe)** is load-bearing — the frontend `WebhookController` has no constructor DI and resolves it from the container at `init()`.
- **Date-rotated filenames** (`_<date>.log`) — rotation is implicit via the filename, no logrotate dependency.
- **payment-base is a separate git repo** — changes to the logging interfaces there won't appear in the stripe repo's `git log`; audit both.
- **`claimEvent()` is never gated** — the idempotency row must be written unconditionally. Only payload/PSR-3 mirror are behind the webhook-logging switch. This invariant is test-covered in `WebhookLogServicePayloadGateTest`.
- **No `LoggingTogglesInterface`** — the closure seam (`?\Closure $isEnabled`) was chosen deliberately over an interface because only one provider (Stripe) needs channel control. Promote to an interface when a second provider needs it; the Phase 0 characterization tests make that refactor safe.
- **`blStripeLogTransactionInfo` is deprecated, not removed** — it seeds `getLogLevel()` when `sStripeLogLevel` is absent, preserving the pre-sprint merchant configuration. Remove it in a follow-up release after merchants have had time to migrate.
