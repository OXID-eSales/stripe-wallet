# Sprint 114.11 — SRP / DIP cleanups

**Module:** `extensions/stripe`
**Priority:** P3 (structural — responsibility + dependency hygiene)
**Findings:** S2 (`ModuleConfigurationService` god-object), S3 (`StripeCaptureRequestHandler` owns capturable policy), S4 (`OrderRefundViewDataProvider` 5 responsibilities), S5 (service-locator scattered), S6 (`StripeWebhookEndpointApi` `new StripeClient`), S7 (`getPriority()` inconsistency)
**Mode:** one commit per finding, TDD-first. Behavior-preserving refactors.
**Depends on:** 114.6 (factory wiring), 114.10 (DTOs available for S4). **Coordinate with:** 114.8 (S3 overlaps the handler base).
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-2.1** (split god-objects), **R-4.2** (eliminate the scattered service-locator), **R-6.2** (PHPMD baseline trends down).

## 1. Why

Six responsibility/dependency-inversion issues. Each is a behavior-preserving
refactor — the existing tests are the safety net; add tests for any newly
extracted collaborator.

## 2. Per-finding plan

### S2 — split `ModuleConfigurationService`
- It mixes ~20 setting getters + webhook-URL building + raw `oxconfig` reads + `metadata.php` description extraction.
- **Extract:** `StripeUrlBuilder` (webhook/shop/SSL URL construction) and `ModuleDescriptionProvider` (metadata extraction). `ModuleConfigurationService` keeps only typed setting access.
- **TDD:** new collaborator tests; `ModuleConfigurationServiceTest` shrinks accordingly.

### S3 — thin the capture handler
- `StripeCaptureRequestHandler::processCapture()` owns capturable-state policy (`isAuthorized`/`isCommitted`), PI resolution, and dual-mode dispatch — the `ExcessiveClassComplexity 62`-baselined class.
- **Move:** capturable-state check + PI resolution into `CaptureService` (PI resolution via the 114.8 `PaymentIntentResolver`). Handler becomes context→service→context.
- **TDD:** move the state-policy tests to `CaptureServiceTest`; handler test asserts delegation. Target: drop the PHPMD baseline entry.

### S4 — split `OrderRefundViewDataProvider`
- 5 responsibilities (fetch+cache, status mapping, currency formatting, history assembly).
- **Extract:** `StripeTransactionHistoryBuilder` (the ~55-line `getStripeTransactionHistory`) consuming `TransactionView` DTOs from 114.10; reuse `AmountConverter` (114.7) for formatting; move status-label mapping to `StripeStatusMapper`.
- **TDD:** `StripeTransactionHistoryBuilderTest` with DTO fixtures.

### S5 — kill the scattered service-locator
- `ContainerFactory::getInstance()` appears in `Model/Order.php:244,270`, `ModuleConfiguration.php` (5×), `WebhookController.php` (2×, incl. a mid-request re-fetch).
- **Fix:** resolve collaborators once in `init()` into typed properties with `protected` getters (testable-subclass seam); route `Order`/`ModuleConfiguration` through the existing `ServiceContainer` trait — one locator mechanism, not two. `WebhookController::cleanupStaleNotFinishedOrders()` stops re-fetching the container.
- **TDD:** testable subclasses override the getters; assert no `ContainerFactory` call mid-request.

### S6 — `StripeWebhookEndpointApi` via factory
- `StripeWebhookEndpointApi::client()` does `new StripeClient($apiKey)`, bypassing `StripeClientFactory` (which pins `stripe_version`) → un-versioned client on the webhook-registration path.
- **Fix:** inject a client-factory abstraction that builds a keyed client *with* the pinned version (extend `StripeClientFactory` with a `forKey(string $apiKey)` method). (Also closes the 114.13 coverage gap on this class.)
- **TDD:** `StripeWebhookEndpointApiTest` asserts the factory is used and the version is pinned (mock the factory).

### S7 — consistent handler priority
- `getPriority()` is in only 3 of 9 handlers; the rest use the `services.yaml` `priority:` tag.
- **Fix (pick one):** either add `getPriority()` to `HandlerInterface` and implement everywhere, OR drop the 3 in-code overrides and rely solely on the tag. Prefer the tag (config-driven, already the majority).
- **TDD:** assert the dispatcher orders handlers by the chosen mechanism.

## 3. Global goals

- **G1.** One commit per finding; each behavior-preserving (existing suite green).
- **G2.** New collaborators are `final`, constructor-injected, interface-typed, tagged in `services.yaml`.
- **G3.** PHPMD baseline shrinks (S3, S2); no new suppressions.
- **G4.** `./bin/pre-commit-check.sh --full` green after each.

## 4. Risks & rollback

- **Risk:** S5 in OXID admin controllers — they can't constructor-inject; the `init()`-resolve + `protected` getter seam is the sanctioned pattern (don't fight the framework).
- **Risk:** S3 overlaps 114.8's handler base — do 114.8 first, then S3 moves policy into the service cleanly.
- **Rollback:** per-finding commits.

## 5. Definition of Done

- S2–S7 each Fixed with tests; PHPMD `ExcessiveClassComplexity`/`TooManyMethods` baseline entries for the touched classes removed where they drop under threshold.
- One service-locator mechanism remains (S5). `StripeWebhookEndpointApi` uses the versioned factory (S6).
