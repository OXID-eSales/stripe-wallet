# Sprint: Harmonize logging control

**Date:** 2026-06-24
**Status:** TODO — decisions locked, then revised after a balance review (see "Decisions")
**Related report:** `../reports/01-logging-architecture.md`

## Core requirements (non-negotiable)

Every commit in this sprint must satisfy these. They are acceptance gates, not aspirations.

- **TDD-first** — write the failing test before the implementation, in every phase. Phase 0 establishes the characterization (parity) net first. No production code lands without a test that failed before it.
- **SOLID**
  - *SRP* — the level resolver, the per-channel helpers, the factory gating, and the frontend `debug()` wrapper are each one responsibility; don't fold logging policy into unrelated services.
  - *OCP* — adding a future log channel must not edit `AbstractFileLoggerFactory`; it extends via a new factory + closure.
  - *LSP* — `NullFileLogger` must be a drop-in for `FileLogger` everywhere `FileLoggerInterface` is typed; no caller may branch on the concrete type.
  - *ISP* — no fat logging interface; the closure seam carries exactly one boolean question.
  - *DIP* — consumers depend on `FileLoggerInterface`, never on `FileLogger`/`NullFileLogger` concretely.
- **DI** — all gating is wired through `services.yaml` (closures/factory args); no `Registry::getConfig()` reach-ins inside services, no `new` of loggers in business code. Config is read at the composition root / factory, injected downward.
- **LI (Liskov)** — swapping the real logger for the null logger (level `off`) changes nothing observable except that no file is written; behavior, signatures, and exceptions stay identical.
- **DRY** — one level-resolution path (`getLogLevel()` + legacy seed) feeds backend channels AND `isStripeDebug()`; the channel→level mapping lives in exactly one place; no duplicated "is logging on?" logic across factories.
- **Clean Code** — meaningful names (`isWebhookLoggingEnabled`, not `chk2`), small methods (15–25 lines), early returns / no `else`, explicit imports, null-safety on nullable config reads.
- **No overengineering** — a bare `?\Closure $isEnabled`, not a `LoggingTogglesInterface`/enum, until a 2nd provider consumes it. Two admin controls, not five. No speculative channels, no config abstraction layer, no premature generalization in payment-base.

### Execution requirement — use the `tdd-solid-engineer` agent

All implementation phases (1–6) **must be carried out via the `tdd-solid-engineer` agent**, one dispatch per phase (Phase 0 characterization too). It is the agent contracted to enforce the gates above (failing-test-first, SOLID, DI, quality gates green before commit).

- **One phase per dispatch** — do not let the agent collapse multiple phases into a single commit. If a phase is large, split it (e.g. `phase-3.a` resolver / `phase-3.b` wiring) to make granularity a hard boundary.
- **Sequential, never parallel** — modules are docker-mounted on a shared MySQL/cache; parallel dispatch flakes. No `isolation: "worktree"` (breaks the docker mount). Run on the real working tree.
- **Verify each dispatch** — after the agent returns, grep/read-check its high-impact claims (literal removed? `assertFileDoesNotExist` present? suite counts unchanged? no new baseline?). Don't trust the summary.
- **payment-base (Phase 1) commits separately** in its own git repo, additive-only, with PayPal + OPC counts proven equal before/after.

## Problem

Logging in the Stripe + payment-base stack is **always-on with a dead off-switch**, on both backend and frontend:

1. **Orphaned backend toggle.** `blStripeLogTransactionInfo` ("Log result of transaction handling") is the only logging checkbox. `ModuleConfigurationService::isTransactionLoggingEnabled()` reads it but **nothing calls that method**. A sibling `isLoggingEnabled()` reads `blStripeEnableLogging`, a key that isn't even in `metadata.php`. Both are dead.
2. **No gating in the factories.** `AbstractFileLoggerFactory::create()` (payment-base) always returns a real `FileLogger`, never `NullFileLogger`. Requests, webhooks, events, and reconciliation logs are written unconditionally.
3. **No webhook-specific control.** Merchants can't separate noisy webhook logging from transaction logging.
4. **payment-base DB webhook log always-on.** `oe_payments_webhooklogs` writes on every event with no opt-out. (Also drives idempotency via `claimEvent()` — see Risk.)
5. **Stale help text.** Help points at `SHOPROOT/log/StripeTransactions.log`, which the current code never writes.
6. **Frontend `console.log` uncontrolled.** 31 raw console calls in `resources/build/js/controllers/*.js`; the production bundle `assets/js/stripe-frontend.min.js` still ships **25** of them (no `drop_console`/terser). No runtime wrapper, no config flag crosses PHP→JS.
7. **Zero tests** assert "logging disabled ⇒ no writes" (backend or frontend).

## Goal

Give merchants **simple, working control** of logging — one volume knob plus a dedicated webhook switch — enforced across backend file channels, the payment-base DB webhook log, and frontend console output, and covered by tests.

## Decisions

**Original (locked 2026-06-24):** five per-channel booleans (`blStripeLog{Requests,Webhooks,Events,Reconciliation,Frontend}`), a provider-agnostic `LoggingTogglesInterface` injected into factories, runtime-only frontend gating.

**Revised (2026-06-24, after balance review):** three changes simplify the design and align it with the project's "no overengineering / ≥2-consumers-before-sharing" principles:

1. **No `LoggingTogglesInterface`.** Gate the factory with an optional `?\Closure $isEnabled = null` (lazy, reads config only at logger construction). Default `null` = enabled = current behavior. Promote to an interface later only when a 2nd provider needs channel control — Phase 0 characterization tests make that refactor safe.
2. **One level select + one webhook switch**, replacing the five booleans:
   - `sStripeLogLevel`: `off | errors | normal | debug`
   - `blStripeLogWebhooks`: on/off (the explicitly-requested separate webhook control)
   - `blStripeLogFrontend` is **dropped** — frontend console turns on at `debug` level.
3. **Frontend = build-time strip + runtime wrapper (defense in depth).** Add `drop: ['console']` (keep `console.error`) to the production esbuild config, AND keep a runtime `debug()` wrapper gated by the `debug` level. Build strips stray raw calls a dev adds later; `debug()` survives for intentional opt-in live diagnostics.

## Config model

Dedicated `STRIPE_LOGGING` group — **2 controls**:

| Setting | Type | Default | Meaning |
|---------|------|---------|---------|
| `sStripeLogLevel` | select | `normal` | `off` → all `NullFileLogger`; `errors` → exceptions only; `normal` → requests + reconciliation; `debug` → + events + frontend console |
| `blStripeLogWebhooks` | bool | `1` | `stripe_webhooks_*.log` **+** DB `OXPAYLOAD`/PSR-3 mirror (NOT the claim row) — independent of level so merchants can silence chatty webhooks without going dark elsewhere |

**Channel → level mapping** (consumed by the gating closures):

| Channel (`stripe_*.log`) | Enabled when |
|--------------------------|--------------|
| requests | level ≥ `errors` (exceptions) / `normal` (full request/response) |
| reconciliation | level ≥ `normal` |
| events | level == `debug` |
| webhooks (file + DB payload) | `blStripeLogWebhooks` AND level ≥ `normal` |
| frontend console | level == `debug` |

**Back-compat (replaces the old "repurpose `blStripeLogTransactionInfo`" decision):** keep reading the legacy bool as the *seed* when `sStripeLogLevel` is unset — `blStripeLogTransactionInfo == 1` → effective `normal`, `== 0` → effective `off`. So a merchant who had logging off stays off; everyone else gets `normal`. Mark `blStripeLogTransactionInfo` deprecated/hidden in the form but keep it readable for one release; remove in a follow-up.

## Design

### Backend (payment-base, additive)
- `AbstractFileLoggerFactory` gains an optional `?\Closure $isEnabled = null` ctor arg. `create()` returns `NullFileLogger` when `$isEnabled !== null && ($isEnabled)() === false`; otherwise the real `FileLogger`. With `null` it behaves exactly as today → PayPal/Unzer untouched.
- **Additive only** (memory: append-only, safe defaults). Run PayPal + OPC suites before/after — counts must match exactly.
- No new interface, no `getChannel()` template method.

### Backend (stripe)
- Add a `LogLevel` resolver on `ModuleConfigurationService`: `getLogLevel(): string` (resolves `sStripeLogLevel`, seeding from legacy bool) + per-channel helpers `isRequestLoggingEnabled()`, `isReconciliationLoggingEnabled()`, `isEventLoggingEnabled()`, `isWebhookLoggingEnabled()`, `isFrontendDebugEnabled()`.
- Each factory in `services.yaml` is constructed with a closure wrapping the matching helper (e.g. `$isEnabled: !closure '@=service(...).isWebhookLoggingEnabled()'`, or a tiny factory-side lambda — pick whatever the OXID/Symfony DI version supports cleanly).
- Remove dead `isLoggingEnabled()`; fold `isTransactionLoggingEnabled()` into the legacy-seed logic.

### Frontend
- `ViewConfig::isStripeDebug(): bool` returns `getLogLevel() === 'debug'` via `ModuleSettingServiceInterface`.
- Template passes `data-stripe-debug-value="{{ oViewConf.isStripeDebug() ? 'true' : 'false' }}"` onto the controller root in `checkout/order.html.twig`.
- Add a shared `debug(...args)` helper (Stimulus mixin / base controller) that no-ops unless the value is true; replace raw `console.log/debug/warn` in controllers with it. **Genuine `console.error` stays unconditional.**
- Production esbuild config: `drop: ['console']` with `console.error` preserved (esbuild `drop` removes all `console.*`; keep error via `pure`-exclusion or route real errors through a retained `reportError`-style call — settle the exact mechanism in Phase 5).

## Phases (TDD, one commit each)

**Phase 0 — Characterization tests**
- Assert CURRENT always-on behavior (backend factories return real `FileLogger`; frontend ships raw console) as the parity net before refactor.

**Phase 1 — payment-base: closure gating seam (additive)**
- Add `?\Closure $isEnabled = null` to `AbstractFileLoggerFactory`; `create()` returns `NullFileLogger` when the closure returns false.
- Unit tests: enabled→`FileLogger`, disabled→`NullFileLogger`, null-closure→`FileLogger` (back-compat).
- PayPal + OPC suites: counts unchanged.

**Phase 2 — Stripe: config + lang**
- Add `sStripeLogLevel` (select) and `blStripeLogWebhooks` (bool) to `metadata.php` in `STRIPE_LOGGING`; deprecate/hide `blStripeLogTransactionInfo`.
- `SHOP_MODULE_GROUP_STRIPE_LOGGING`, `SHOP_MODULE_<name>`, `HELP_SHOP_MODULE_<name>`, and the select option labels in **both** `views/admin_twig/{en,de}/stripe_lang.php`. Fix stale `StripeTransactions.log` help.
- Guard: `payment-base/tests/Unit/Metadata/SettingsTranslationsTest.php` passes.

**Phase 3 — Stripe: level resolver + wire factories**
- Implement `getLogLevel()` (+ legacy seed) and the per-channel helpers; wire closures into the 4 factories in `services.yaml`.
- Remove dead `isLoggingEnabled()`.
- Tests: per level, the right channels get `NullFileLogger` and **no file is written** (`assertFileDoesNotExist`); legacy-seed cases (`blStripeLogTransactionInfo` 0/1 with `sStripeLogLevel` unset).

**Phase 4 — DB webhook log policy**
- Keep `claimEvent()` row always; gate `OXPAYLOAD` write + PSR-3 webhook mirror behind `isWebhookLoggingEnabled()`.
- Tests: idempotency/dedup still works with webhook logging OFF; payload empty/omitted when off.

**Phase 5 — Frontend: build strip + runtime wrapper**
- Add `ViewConfig::isStripeDebug()` + PHP unit test.
- esbuild prod: `drop` console (preserve real errors); confirm prod bundle has no stray `console.log`.
- Add `data-stripe-debug-value`; add `debug()` helper; replace raw console.log/debug/warn in `stripe_order_controller.js` / `order_submit_controller.js`.
- `npm run build`; verify: prod bundle silent at `normal`, verbose at `debug`. Manual check noted in status if no JS test harness.

**Phase 6 — Cleanup + docs**
- Remove remaining orphaned methods/keys; update `01-logging-architecture.md` (incl. Frontend section + new config model).
- Full pre-commit `--full`; record new baseline counts in MEMORY.md.

## Per-commit checklist

**Every commit** (run before staging — `./bin/pre-commit-check.sh`, or `--full` where noted):

- [ ] **TDD**: a test that *failed first* now passes; committed in the same change (test + impl together, or test-only commit immediately preceding).
- [ ] **Green**: full Unit suite passes; no skipped/`markTestSkipped` without a real precondition.
- [ ] **Style**: PHPCS clean (PSR-12).
- [ ] **Static**: PHPStan level max — 0 errors, no new baseline entries, no inline suppressions (except documented OXID-core patterns).
- [ ] **PHPMD**: 0 new findings; no threshold raised to hide one.
- [ ] **DI**: no `Registry::getConfig()`/`oxNew()` reach-ins or `new FileLogger`/`new NullFileLogger` added inside services; loggers arrive by injection.
- [ ] **No overengineering**: no new interface/enum/abstraction beyond what this commit's test needs.
- [ ] **Scope**: one responsibility per commit (don't collapse phases — split if it grows).
- [ ] **Commit msg**: ends with the `Co-Authored-By` trailer.

**Per-phase gates** (in addition to the above):

| Phase | Must clear before commit |
|-------|--------------------------|
| **0 — Characterization** | Tests assert *current* always-on behavior and pass against unmodified code (parity baseline). No production change in this commit. |
| **1 — PB closure seam** | `?\Closure $isEnabled = null` only; null-path test proves byte-identical behavior (**LSP/DIP**). PayPal + OPC suites run `--full` before & after — **counts match exactly** (additive proof). Commit in payment-base repo separately. |
| **2 — Config + lang** | `sStripeLogLevel` + `blStripeLogWebhooks` in metadata; group + field + help + every select-option label in **both** `admin_twig/{en,de}`. `SettingsTranslationsTest` green. Stale `StripeTransactions.log` help removed. No logic wired yet (config-only commit). |
| **3 — Resolver + wiring** | `getLogLevel()` + helpers are small, early-return, no `else` (**Clean Code**); single resolution path reused by all channels (**DRY**). `assertFileDoesNotExist` per level. Legacy-seed tests (0→off, 1→normal). Dead `isLoggingEnabled()` removed. |
| **4 — DB webhook policy** | Test proves `claimEvent()` row still written with webhook logging OFF (idempotency intact); `OXPAYLOAD`/PSR-3 mirror gated. No change to the claim path's signature. |
| **5 — Frontend** | `isStripeDebug()` PHP test; prod bundle grep shows no stray `console.log`; `console.error` survival mechanism documented & verified; `debug()` is a single shared helper (**DRY**, no per-controller copies). |
| **6 — Cleanup + docs** | No orphaned methods/keys remain (grep-verified); no `LoggingTogglesInterface` exists; `01-logging-architecture.md` updated; `--full` green; MEMORY.md baseline counts recorded. |

## Acceptance criteria

- [ ] **2 admin controls** (`sStripeLogLevel` + `blStripeLogWebhooks`) replace the orphaned single checkbox; webhook logging independently switchable.
- [ ] `off` ⇒ no file written on any channel (`assertFileDoesNotExist`); `debug` ⇒ all channels + frontend console active.
- [ ] Legacy `blStripeLogTransactionInfo` value preserved via level seeding (0→off, 1→normal); merchant never silently flipped.
- [ ] DB `claimEvent()` row always written; only `OXPAYLOAD`/PSR-3 mirror gated; idempotency unaffected (tested).
- [ ] Production JS bundle has no stray `console.log` (build-strip) AND `debug()` is silent unless level==`debug`.
- [ ] No orphaned config-read methods; no `LoggingTogglesInterface`; help text accurate.
- [ ] payment-base changes additive; null-closure path preserves old behavior; PayPal + OPC suite counts unchanged.
- [ ] PHPCS / PHPStan (max) / PHPMD clean; no new suppressions/baseline entries.

## Risks / gotchas

- **`oe_payments_webhooklogs` doubles as the idempotency ledger** — never gate `claimEvent()`; only the payload/mirror.
- **payment-base is a separate git repo** — commit there separately; path-symlink repo, so `composer update` won't advance its git state.
- **Keep the closure seam minimal** — a bare `?\Closure $isEnabled`. Do NOT reintroduce an interface/enum until a 2nd provider consumes it.
- **`drop: ['console']` removes `console.error` too** — settle how genuine errors survive (pure-exclusion list, or a retained reporter) in Phase 5; don't silently swallow real failures.
- **Level-seeding edge case** — once a merchant sets `sStripeLogLevel` explicitly, stop consulting the legacy bool; only seed when the select is unset/empty.
- **Lang keys split admin vs storefront** — logging settings are admin-only; every setting (incl. each select option) needs keys in `views/admin_twig/{en,de}` or the admin tab errors.
- **Caches** — clear `source/tmp/*` after template changes; `docker compose restart php` after class changes.
