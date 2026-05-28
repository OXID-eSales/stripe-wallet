# Engineering Requirements (shared) — Code-Review 114 Remediation

**Applies to:** every sub-sprint `sprint-114.1 … 114.13` (and `114.0`).
**Status:** binding. A sub-sprint is **not Done** until every requirement below
that touches its scope is satisfied and demonstrable in its completion report.

These are the *guards* (acceptance gates) for all remediation work. They
restate and tighten the rules in `extensions/stripe/CLAUDE.md` and the
smart-contract architecture docs. Each requirement has an ID (`R-XX`) so
sprints and reviews can cite it precisely.

---

## R-1 — TDD (Test-Driven Development)

**Goal:** no production change without a test that first failed for the right reason.

- **R-1.1** Write the failing test (**RED**) before the fix; commit it red or show the red run in the report.
- **R-1.2** Implement the minimum to pass (**GREEN**), then **REFACTOR** with the test green.
- **R-1.3** AAA structure (Arrange–Act–Assert); one behavior per test; the test name states the behavior.
- **R-1.4** For refactors/deletions, the safety net is a **characterization test** proving behavior parity *before* the change.
- **R-1.5** Never re-implement the method under test inside a testable subclass — override only seams (the pattern at `tests/Unit/Stripe/Controller/StripeOrderControllerTest.php:636`). Asserting against a copy is a TDD failure (see finding T1).
- **Verify:** net meaningful assertion count does not drop; deleted tests are justified.

## R-2 — SOLID

**Goal:** small, single-purpose, open-for-extension units depending on abstractions.

- **R-2.1 SRP** — one reason to change per class; no god-objects (config + URL + persistence + view in one).
- **R-2.2 OCP** — new variants are additive, not edits to a central `switch`/`match` (e.g. webhook dispatch via tagged handlers, not a type `match`).
- **R-2.3 ISP** — narrow, role-specific interfaces; do not widen an interface with methods only one caller needs.
- **R-2.4 DIP** — depend on interfaces; the concretion is wired in `services.yaml`, never `new`ed in business code.
- **Verify:** PHPMD `ExcessiveClassComplexity`/`TooManyMethods` do not grow; new baseline entries are forbidden (refactor instead — see [R-9]).

## R-3 — LI (Liskov Substitution)

**Goal:** a subtype is usable anywhere its supertype is, with no surprises.

- **R-3.1** An override must not weaken a precondition or strengthen a postcondition; in particular it must not silently disable a parent's **security** behavior (cf. `Order::validateDeliveryAddress()`, finding L1).
- **R-3.2** No `instanceof Concrete` downcasts to reach methods missing from the interface — add the method to the interface instead (finding L3).
- **R-3.3** A class claiming to "mirror" an interface must actually implement it and be substitutable (cf. `LazyStripeAdapter`, finding L2).
- **Verify:** PHPStan level max passes with the downcasts removed.

## R-4 — DI (Dependency Injection)

**Goal:** dependencies are explicit constructor parameters, resolved by the container.

- **R-4.1** Constructor-inject interface-typed collaborators; default optional `?LoggerInterface $logger = null` stays the trailing arg.
- **R-4.2** No `ContainerFactory::getInstance()` / service-locator in business code. OXID controllers/models that cannot constructor-inject resolve **once** in `init()` into typed `protected` properties exposed via getters (the testable seam) — never mid-request, never twice.
- **R-4.3** No static `Registry::get*()` where an interface can be injected; `Registry`/`oxNew` are allowed only as documented OXID seams wrapped behind an `Oxid*` adapter.
- **R-4.4** Every new collaborator is registered in `services.yaml`; mocks target the **interface**, never a concrete class or a `final` class.
- **Verify:** `grep` for `ContainerFactory::getInstance` in touched business classes returns nothing new.

## R-5 — Clean Code

**Goal:** code reads like the rule set in CLAUDE.md.

- **R-5.1** Methods 15–25 lines; extract helpers beyond that.
- **R-5.2** No `else`/`elseif` — early returns / guard clauses.
- **R-5.3** Explicit `use` imports — no inline `\Exception`, `\RuntimeException`, `\Throwable`, or FQCN parameter types.
- **R-5.4** No magic strings/numbers — payment ids, provider, statuses, modes, currencies, amounts go through `StripeDefinitions` / `StripeStatusMapper` / enums / the `AmountConverter`.
- **R-5.5** Null-safety: guard nullable getters consistently within a class.
- **R-5.6** No leftover `@TODO`, dead constants, or docblocks that contradict the code.
- **Verify:** `composer phpcs` (PSR-12) clean.

## R-6 — DevOps-first

**Goal:** the gate runs before the commit, not after.

- **R-6.1** `./bin/pre-commit-check.sh --full` (PHPCS + PHPStan level max + PHPMD + PHPUnit Unit+Integration) is green for every commit.
- **R-6.2** No new static-analysis suppressions; suppress only genuine OXID-core patterns (oxNew, Registry, virtual parent classes), and prefer the PHPMD **baseline** over inline `@SuppressWarnings`. Do not raise PHPMD thresholds to hide complexity.
- **R-6.3** After class-level or `services.yaml` changes: `bin/oe-console oe:cache:clear` **and** `docker compose restart php` (FPM opcache). Empty `bin/oe-console` output = failure — verify the success line.
- **R-6.4** Never edit `vendor/` or generated `var/configuration/...`; edit `metadata.php` / `services.yaml` / `extensions/` canonically.
- **Verify:** completion report records before/after `Tests / Assertions` and the green gate.

## R-7 — Event-driven

**Goal:** lifecycle changes flow through the PSR-14 event system, not ad-hoc calls.

- **R-7.1** State-changing actions are expressed as events dispatched on the dispatcher; handlers (tagged `payment.event_handler` / the webhook handler tag) react.
- **R-7.2** New behavior is added by registering a handler, not by editing a central dispatcher `match`/`switch` (ties to [R-2.2]).
- **R-7.3** Do not dispatch an event no handler consumes, and do not register a handler nothing dispatches (cf. findings O1, O5).
- **R-7.4** Admin and storefront paths for the same operation must converge on the **same** event (e.g. refund via opalreturns "Close" and the Stripe-tab "Refund" both terminate in the same `*RefundRequestEvent`).
- **Verify:** handler↔event map in the report; an integration assertion that tagged-handler iterators are non-empty.

## R-8 — Contract-aware

**Goal:** the `PaymentContract` state machine is the source of truth for the payment lifecycle.

- **R-8.1** Lifecycle decisions (capture vs nothing, refund vs cancel, fulfill) are driven by **contract state**, never by a field that "should be" populated (e.g. `OXCAPTUREDAMOUNT`) or by inbound webhook side-effects.
- **R-8.2** Use the named transition methods (`captureAuthorization()`, `fail()`, `cancel()`, fulfillment service) — never a generic `setState()`.
- **R-8.3** Honor the documented state graph (`DRAFT → NOT_FINISHED → PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED`, plus `CANCELLED/EXPIRED/FAILED`); guard terminal states before transitioning.
- **R-8.4** Webhooks are inbound-only signals; they advance the contract through the same transitions, they are not a write channel of their own.
- **Verify:** transitions covered by tests asserting pre/post state.

## R-9 — No overengineering

**Goal:** implement exactly what is needed.

- **R-9.1** No speculative abstraction: no single-implementation interface that exists only "for the future", no unused parameters/methods, no proxy that forwards without adding value.
- **R-9.2** Delete dead code rather than maintaining it; before deleting, `grep src/ tests/ views/ out/` and sibling modules (paypal, one-page-checkout) to prove non-reachability.
- **R-9.3** Prefer reusing an existing collaborator over introducing a parallel one (cf. duplicate `StripeCaptureService`/`CaptureService`, finding O2).
- **Verify:** LOC and PHPMD baseline trend **down** across the remediation.

## R-10 — Persistence boundary: events write, reads may go direct (CQRS-lite)

**Goal:** all state mutation is funnelled through events → services → repositories; queries may read directly.

- **R-10.1 (writes)** Business/controller/handler code **must not** write to the database directly (no `oxNew(...)->save()` for domain persistence, no direct `INSERT/UPDATE/DELETE`, no SQL writes). To persist a change, **dispatch an event**; an event handler (a service) performs the write **through a repository** (`ContractRepositoryInterface`, `TransactionRepositoryInterface`, …).
- **R-10.2** The write chain is exactly: **dispatch event → handler/service → `repository->save()/persist()`**. Repositories are the only components that touch the write store, and they are invoked **from within services reached by events**, not called ad-hoc from controllers.
- **R-10.3 (reads)** Direct **`SELECT`/read** access is allowed — controllers/view-data providers/services may query the DB or call repository read methods (`findById`, `findByX`) directly without an event. Reads need no event.
- **R-10.4** A "select-then-mutate" sequence is a write: the mutation half must still go through the event→service→repository path; only the pure read half may be direct.
- **R-10.5** OXID framework persistence that is genuinely outside our domain (e.g. OXID core completing its own `oxorder` during its own flow) is exempt, but any **domain** state we own (contract, transaction, payment-state/OXPAID reconciliation we initiate) obeys R-10.1–R-10.4.
- **R-10.6** No new repository method is called from a controller/handler purely to write; if a write is needed there, raise an event.
- **Verify:** in each sprint, `grep` the touched files for `->save(`, `oxNew(`+`->save`, and raw write SQL; every write hit is either inside a repository or inside an event-reached service, or is justified as R-10.5. Read-only `SELECT`/`find*` hits are fine.

---

## How to use in a sprint

1. In the sprint header, cite this file (all sprints already do).
2. In **Goals**, only restate the requirements that need sprint-specific thresholds (e.g. "PHPMD baseline entry X removed").
3. In **Definition of Done**, the implicit gate is: *all applicable R-1…R-10 satisfied*; call out by ID any that needed special handling (e.g. "R-10.1: refund now persisted via `*RefundRequestEvent` → recorder, no direct save").

## Quick gate checklist (paste into each completion report)

- [ ] R-1 TDD: RED shown before GREEN; no method-under-test re-implemented in a double
- [ ] R-2 SOLID: no god-object / central `match`; PHPMD baseline not grown
- [ ] R-3 LI: no security-weakening override; no `instanceof` downcast
- [ ] R-4 DI: constructor-injected interfaces; no new `ContainerFactory` in business code
- [ ] R-5 Clean Code: ≤25-line methods, no else, explicit imports, no magic literals
- [ ] R-6 DevOps-first: `pre-commit-check.sh --full` green; no new suppressions; cache cleared + php restarted
- [ ] R-7 Event-driven: behavior added via handler; no orphan event/handler; admin+storefront converge
- [ ] R-8 Contract-aware: decisions from contract state; named transitions; terminal-state guards
- [ ] R-9 No overengineering: dead code deleted with grep proof; no speculative abstraction
- [ ] R-10 Persistence: writes via event→service→repository; only `SELECT`/`find*` direct; write-grep proof attached
