# 114 — Code Review: Principle Violations in `src/` and `tests/`

**Date:** 2026-05-27
**Reviewer:** Claude (Opus 4.7)
**Scope:** `source/extensions/stripe/src/` (134 PHP files, ~15.6k LOC) and `source/extensions/stripe/tests/` (112 PHP files)
**Lens:** TDD · SOLID · Liskov Substitution (LI) · DRY · No Overengineering · Clean Code · Provider-agnostic pattern
**Method:** Five parallel deep reviews (adapters, services, event-system/webhooks, controllers/models, tests), every High finding re-verified directly against the source. Findings marked **[verified]** were confirmed by me via grep/read after the review.

---

## Executive Summary

The module is, on the whole, well-structured: the adapter layer is genuinely thin in most paths, the event system is real (not ceremonial), and a previous refactor already dissolved the `OrderRefund` god-controller into `OrderActionDispatcher` + `OrderRefundViewDataProvider` + a panel triad. The findings below are concentrated, not pervasive.

That said, there are **two functional bugs hiding inside "style" findings** and a **cluster of dead/duplicated subsystems** that should be deleted, not maintained.

### Severity rollup

| # | Severity | Principle | Title | Status |
|---|----------|-----------|-------|--------|
| H1 | **High** | Clean Code (latent bug) | Hardcoded `shopId: 1` in transaction audit — breaks multi-shop | **[verified]** |
| H2 | **High** | Liskov / Clean Code (latent bug) | `Order::validateDeliveryAddress()` blanket-returns `0` for Stripe | **[verified]** |
| H3 | **High** | No Overengineering / DRY | Two parallel webhook subsystems; one untagged, one unregistered | **[verified]** |
| H4 | **High** | No Overengineering | Dead duplicate `StripeCaptureService` / `StripeRefundService` | **[verified]** |
| H5 | **High** | No Overengineering | `ConfigurationValidator` — 4 of 5 public methods unused | **[verified]** |
| H6 | **High** | DRY | Currency cents math (`*100` / `/100`) duplicated across 14 files | **[verified]** |
| H7 | **High** | Agnostic leak | Raw `\Stripe\*` SDK types leak through adapter interfaces into services/model/view | **[verified]** |
| H8 | **High** | DRY | `getToken()` and `getSecretKey()` are byte-identical | **[verified]** |
| H9 | **High** | DRY | Refund-recording sequence triplicated across 3 handlers | reviewed |
| H10 | **High** | DRY | Handler skeleton (try/catch + logEvent + PI-resolution) duplicated ×3–9 | reviewed |
| H11 | **High** | TDD | Security tests assert a re-implementation, not the SUT | reviewed |
| M* | Medium | OCP / SRP / DIP / Clean Code / Agnostic | 14 findings (see below) | reviewed |
| L* | Low | Clean Code / DRY / No Overeng. | 12 findings (see below) | reviewed |

**Headline:** fix H1 and H2 immediately (correctness/security). Delete the code behind H3, H4, H5 (≈ 600 LOC of dead/duplicate code). Centralize H6/H8 and tighten the H7 boundary as the structural follow-up.

---

## 1. TDD

### T1 — [High] Security tests verify a copy-pasted re-implementation, not the production code
- **File:** `tests/Unit/Stripe/Controller/StripeOrderControllerSecurityTest.php:190,267`
- **Evidence:** `public function createCheckoutSession(): void { /* Minimal version that tests the output format */ ... }` and a `checkoutSuccess()` testable subclass with a **single** "Payment verification failed" branch, while the real `StripeOrderController::checkoutSuccess()` has **five** (src lines 292, 314, 319, 325, 338).
- **Problem:** The testable subclass re-declares the very methods under test. `@covers StripeOrderController` is false — production could regress on four of the five rejection paths and these tests stay green. Same pattern in `WebhookControllerGuardIntegrationTest.php:165` ("Reimplements render() without OXID Registry calls"), which is the security-critical webhook entry point.
- **Fix:** Follow the correct seam-only pattern already used in `StripeOrderControllerTest.php:636` — override DI/IO only, exercise the real method.

### T2 — [Medium] `markTestIncomplete` on an already-shipped feature
- **File:** `tests/Unit/Stripe/Model/OrderAddressValidationTest.php:39`
- **Evidence:** `$this->markTestIncomplete('... The fix is to override validateDeliveryAddress() ...');` — but `validateDeliveryAddress()` is implemented (`src/Stripe/Model/Order.php:111`). The sibling `testExpectedBehaviorForStripeFix` only asserts on local literals (`strpos($paymentId,...) === 0`), exercising nothing on the SUT.
- **Problem:** Zero real coverage for a security-relevant address-validation bypass (see **H2**), behind a green-looking skip.
- **Fix:** Replace with a real testable-subclass test driving request/session hash inputs through the actual override.

### T3 — [Medium] Tautological tests — assert the mock's own return value
- **File:** `tests/Unit/Stripe/Controller/Admin/OrderRefundViewDataProviderTest.php:71-84`
- **Evidence:** Builds `buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 29700)`, then `$this->resolver->method('availableForRefund')->willReturn(100.0); ... assertSame(100.0, $result);`. The SUT just returns `$resolver->availableForRefund($charge)`, so the fixture is never read.
- **Problem:** `willReturn(X) → assertSame(X)` tests the mock, not behavior. The decorative fixture comments ("Pre-fix: −197.0") imply coverage that lives elsewhere (`StripeChargeAmountResolverTest`).
- **Fix:** Inject the real resolver so the fixture drives the result, or drop the misleading fixtures.

### T4 — [Medium] Test-of-a-test-helper masquerading as webhook coverage
- **File:** `tests/Unit/Helper/StripeWebhookTestHelperTest.php` (e.g. `:157`)
- **Evidence:** `generatedSignatureWorksWithStripeVerifier` → `assertTrue(StripeWebhookTestHelper::verifySignature(...))` — the helper verifies **its own** HMAC output; Stripe's real `Webhook::constructEvent` is never called.
- **Problem:** 11 tests on scaffolding, giving a false sense of signature-verification coverage.
- **Fix:** Assert helper output against Stripe's real verifier in an integration test, or delete the self-verification tests.

### T5 — [Low] No-value assertions
- **File:** `StripeCaptureRequestHandlerTest.php:72` (`$this->assertTrue(true); // No exception means success`), `RequestLogServiceTest.php:67,95`, `StripeRefundServiceValidationTest.php:29,53`, `OxpaidReconciliationServiceTest.php:71` (`assertInstanceOf(...)` on the object built in `setUp`).
- **Problem:** Pass unconditionally or assert a fact guaranteed by `new`. Where `expects()` already verifies behavior, the trailing `assertTrue(true)` is dead weight.
- **Fix:** Remove; rely on `expects()` / `expectException`.

### T6 — [Low] Integration suite reports green while ~53 tests silently skip
- **File:** `tests/SKIPPED_TESTS_REASON.md`, `tests/Integration/Stripe/StripeIntegrationTestCase.php:45`
- **Evidence:** `Tests: 157, Assertions: 431, Skipped: 53.` — ~40 skip without Stripe creds, ~10 when the container fails to boot.
- **Problem:** On a normal CI run the Stripe-facing integration layer effectively does not execute, yet the suite is green; the "18 integration tests" claim is optimistic.
- **Fix:** Fail (not skip) in CI when preconditions are absent, or gate credential-dependent specs into an explicit suite so default coverage is honest.

---

## 2. SOLID

### S1 — [High] OCP: `StripeWebhookProcessor` dispatches via a hardcoded `match` on event type **[verified]**
- **File:** `src/Stripe/Webhook/StripeWebhookProcessor.php:92`
- **Evidence:** `return match ($event->type) { 'payment_intent.succeeded' => ..., 'charge.refunded' => ..., 'checkout.session.completed' => ..., 'checkout.session.expired' => ..., default => WebhookResult::skipped(...) };`
- **Problem:** Every new webhook type forces editing this method plus adding a private `handleXxx`. The `WebhookEventHandlerInterface::supports()` pattern exists precisely to make this open-for-extension but is bypassed (see **H3**).
- **Fix:** Inject a `!tagged_iterator` of `WebhookEventHandlerInterface`, loop calling `supports()`, drop the `match`.

### S2 — [Medium] SRP: `ModuleConfigurationService` is a config god-object
- **File:** `src/Stripe/Service/ModuleConfigurationService.php:238-267,290-301`
- **Evidence:** alongside ~20 setting getters it builds the webhook URL (`getWebhookUrl`), resolves shop/SSL base URLs via `Registry::getConfig()`, reads `oxconfig` directly (`readOxConfigVar`), and extracts `metadata.php` descriptions (`getModuleDescription`).
- **Problem:** A "type-safe config accessor" also does URL construction, raw `oxconfig` reads, and metadata extraction — at least three responsibilities.
- **Fix:** Extract URL-building and module-description access into separate collaborators.

### S3 — [Medium] SRP: `StripeCaptureRequestHandler` owns capturable-state policy + PI resolution + dual-mode dispatch
- **File:** `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php:87-140`
- **Evidence:** `processCapture()` branches on direct vs contract mode, inspects `getState()->isAuthorized()/isCommitted()`, resolves the PI ID three ways, builds two metadata variants, then calls the service. Its own docblock lists six responsibilities — this is the class carrying the baselined `ExcessiveClassComplexity`.
- **Problem:** A "thin handler" should delegate to `CaptureService`, but capturable-state policy and PI resolution live in the handler.
- **Fix:** Push the state check and PI resolution into `CaptureService`; the handler should only translate context → service call → context.

### S4 — [Medium] SRP: `OrderRefundViewDataProvider` does fetching + caching + status mapping + currency formatting + history assembly
- **File:** `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php:62-270`
- **Evidence:** `getPaymentIntent`/`getLastCharge` (caching), `mapPiStatusToLabel` (mapping), `formatPrice` (currency), `getStripeTransactionHistory` (~55-line builder, `:215-270`).
- **Problem:** Five distinct responsibilities — the residual concentration after the `OrderRefund` split.
- **Fix:** Extract a `StripeTransactionHistoryBuilder` and a shared money/cents helper.

### S5 — [Medium] DIP: service-locator (`ContainerFactory::getInstance()`) scattered across model + controllers **[verified]**
- **File:** `src/Stripe/Model/Order.php:244,270`; `src/Stripe/Controller/Admin/ModuleConfiguration.php:55,67,78,139,366`; `src/Stripe/Controller/Webhook/WebhookController.php:47-50,165-167`
- **Evidence:** `$container = ContainerFactory::getInstance()->getContainer(); $this->processor = $container->get(StripeWebhookProcessor::class);` (and `Order` resolving `StripeOrderApiService`/`ChargeAmountResolverInterface` from the container).
- **Problem:** A domain model and admin controllers reach into the DI container directly; `WebhookController::cleanupStaleNotFinishedOrders()` re-fetches the container a second time mid-request. `ModuleConfiguration` hand-rolls the same locator five times while a `ServiceContainer` trait already exists. Two parallel locator mechanisms.
- **Fix:** Resolve collaborators once in `init()` into typed properties (with protected getters for the testable-subclass pattern); route `Order`/`ModuleConfiguration` through the single `ServiceContainer` seam.

### S6 — [High] DIP: `StripeWebhookEndpointApi` instantiates `new StripeClient($apiKey)` directly **[verified]**
- **File:** `src/Stripe/Adapter/StripeWebhookEndpointApi.php:129-131`
- **Evidence:** `private function client(string $apiKey): StripeClient { return new StripeClient($apiKey); }`
- **Problem:** Bypasses `StripeClientFactory` (which pins `stripe_version` at `StripeClientFactory.php:46`), so the webhook-registration path uses a different, **un-versioned** client than every other adapter path.
- **Fix:** Inject a client-factory abstraction and build the keyed client through it.

### S7 — [Low] ISP/LSP: `getPriority()` not in `HandlerInterface`, yet 3 of 9 handlers define it
- **File:** payment-base `HandlerInterface` (declares only `handle()` + `getHandledEventClass()`) vs `StripeCaptureRequestHandler.php:56`, `StripeCheckoutSessionHandler.php:55`, `StripeContractCreationHandler.php:51`.
- **Problem:** Priority is sometimes code, sometimes the `services.yaml` `priority:` tag — inconsistent contract; the three in-code overrides duplicate what the tag already sets.
- **Fix:** Either add `getPriority()` to the interface and implement everywhere, or drop the in-code overrides and rely on the tag.

---

## 3. Liskov Substitution (LI)

### L1 — [High] `Order::validateDeliveryAddress()` weakens the parent's security contract **[verified]**
- **File:** `src/Stripe/Model/Order.php:111-138` (`:132-133`)
- **Evidence:** `if (strpos($paymentId, 'oe_payments_stripe_') === 0) { return 0; }`
- **Problem:** The override unconditionally returns `0` ("address OK") for **all** Stripe payments, silently disabling OXID's address-tamper validation regardless of the actual address state — a Liskov-weakening of a security-relevant parent contract callers don't expect. The preceding `$oBasket !== null` guard is also dead (OXID's `getBasket()` never returns null here).
- **Fix:** Narrow the skip to the documented checkout-return case only (gate on a session "return" flag), not a blanket bypass; drop the dead null check. **(Functional/security finding — prioritize.)**

### L2 — [Medium] `LazyStripeAdapter` is not substitutable for `StripeAdapter`
- **File:** `src/Stripe/Adapter/LazyStripeAdapter.php:47`
- **Evidence:** `final class LazyStripeAdapter implements PaymentAdapterInterface` — implements only the agnostic subset, while the real adapter is `StripeAdapter implements StripeAdapterInterface` (adds `createCheckoutSession`, `retrievePaymentIntent`, `createRefundByCharge`, `cancelPaymentIntent`, `createStripeCustomer`, …).
- **Problem:** A proxy advertised as "mirroring the interface" silently drops the Stripe-specific half — it cannot stand in where a `StripeAdapterInterface` is expected.
- **Fix:** Implement the full interface, or rename/document it explicitly as an agnostic-only facade (and see **OE3** — it may be deletable entirely).

### L3 — [Medium] `instanceof PaymentContract` downcast in webhook handler
- **File:** `src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php:100,131`
- **Evidence:** `if ($contract instanceof PaymentContract) { $contract->fail($failureReason); ... }`
- **Problem:** `PaymentContractInterface` lacks `fail()/cancel()`, so the handler downcasts to the concrete class to call them — an LSP smell where the interface is too narrow for its real use.
- **Fix:** Add `fail()/cancel()` to `PaymentContractInterface` (in payment-component) and drop the downcasts.

---

## 4. DRY

### D1 — [High] Currency cents conversion (`*100` / `/100`) duplicated across 14 files, ~28 sites **[verified]**
- **Files (sample):** `Adapter/Helper/PaymentIntentHelper.php:53,135,304,318,97-102`; `Adapter/Helper/RefundHelper.php:83,102`; `Service/RefundService.php:60,171`; `Service/CheckoutSessionService.php:169,192`; `Service/CheckoutReturnService.php:100`; `Service/Result/CheckoutReturnResult.php:140`; `Controller/Admin/OrderRefundViewDataProvider.php:136,191,231,248,264`; `Model/Order.php`; `Admin/StripePanelViewDataBuilder.php:56`; `WebhookHandler/{ChargeRefunded,PaymentIntentSucceeded}Handler.php`; `Webhook/StripeWebhookEventParser.php`.
- **Evidence:** `(int) ($request->amount * 100)`, `(int) round($amount * 100)`, `$paymentIntent->amount / 100`, `$amountTotal / 100`, … Only `StripeChargeAmountResolver` bothers with a `CENTS_PER_UNIT` constant.
- **Problem:** The major↔minor-unit conversion with a hardcoded `100` is reimplemented ~28 times. It is **also a latent bug**: wrong for zero-decimal currencies (JPY, KRW). Inconsistent too — some sites `round`, some truncate.
- **Fix:** Introduce a single currency-aware `AmountConverter` (`toMinorUnits()` / `toMajorUnits()`) and route every site through it.

### D2 — [High] `getToken()` and `getSecretKey()` are byte-identical **[verified]**
- **File:** `src/Stripe/Service/ModuleConfigurationService.php:114-137`
- **Evidence:** both read `sStripeTestToken` / `sStripeLiveToken` by mode with identical bodies; consumers are split (`ConfigurationValidator`/`StripeClientFactory` call `getToken()`, `ContractTokenService` calls `getSecretKey()`).
- **Problem:** Two public accessors with the same implementation reading the same keys — load-bearing confusion.
- **Fix:** Keep one; make the other a one-line alias or delete it and repoint callers.

### D3 — [High] Refund-recording sequence triplicated, with a divergent guard
- **File:** `EventSystem/Handler/StripeRefundRequestHandler.php:212`; `WebhookHandler/ChargeRefundedHandler.php:68`; `WebhookHandler/WebhookContractFulfillmentHandler.php:73`
- **Evidence:** `$contract->addRefundedAmount($refundAmount); $contract->setRefundedAt(new \DateTimeImmutable()); $this->contractRepository->save($contract);` — two guard on `!$contract->getState()->isFulfilled()`; `ChargeRefundedHandler` does **not**.
- **Problem:** Identical sequence copy-pasted in three places, and the missing guard means the three paths can diverge in behavior.
- **Fix:** Extract a `ContractRefundRecorder` service consumed by all three.

### D4 — [High] Handler skeleton + PI-resolution + log helpers duplicated ×3–9
- **File:** `StripeCaptureRequestHandler.php:61-85,150,370`; `StripeRefundRequestHandler.php:51-76,115`; `StripeCancelAuthorizationRequestHandler.php:52-76,104,219` (its docblock literally says "Mirrors StripeCaptureRequestHandler::getPaymentIntentId()").
- **Evidence:** The `handle()` shape (`logEvent START` → `instanceof` guard → `try { processXxx } catch (\Throwable) { handleException }`), the PI-resolution chain (`getProviderOrderId` → metadata fallback → "No PaymentIntent ID found" error), and the `logEvent()` / `logExceptionToRequestLog()` helpers are duplicated — `logEvent()` is copy-pasted into all 9 handlers.
- **Fix:** Introduce an `AbstractStripeRequestHandler` base (or trait) holding the `handle()` template, `logEvent`, and a shared PI-resolution collaborator.

### D5 — [High] Idempotency wrapper duplicated between `PaymentIntentHelper` and `RefundHelper`
- **File:** `Adapter/Helper/PaymentIntentHelper.php:266-376` vs `Adapter/Helper/RefundHelper.php:144-259`
- **Evidence:** near-identical `captureWithIdempotency()` / `refundWithIdempotency()` — same `STATUS_PROCESSING/COMPLETED/FAILED` constants, same find/short-circuit/save/execute/save flow, same `catch (\Throwable) { setStatus(FAILED); setResult(json_encode(['error'=>...])); throw; }`.
- **Fix:** Extract a generic `IdempotentExecutor` (the existing `IdempotencyHelper` is the natural home) taking a key + closure + serializer callbacks.

### D6 — [Medium] Stale-checkout cleanup duplicated across two controllers + within one
- **File:** `Controller/StripeOrderController.php:80-102,387-408,423-440`; `Controller/PaymentController.php:58-79`
- **Evidence:** four near-identical blocks: read `stripe_contract_id` → `RetryCleanupService::cleanupPreviousAttempt()` in `try/catch \Throwable` → log → clear session vars. `PaymentController` bypasses `ControllerRequestHelper` and reaches `Registry::getSession()` directly, so it forgets `stripe_payment_intent_id`/`stripe_client_secret`.
- **Fix:** Move "cleanup-stale-attempt" into `RetryCleanupService`/a `StaleCheckoutCleaner`, call from all four sites via `ControllerRequestHelper`.

### D7 — [High/Medium] `'oe_payments_stripe_'` prefix hardcoded in 6 sites with two idioms **[verified]**
- **File:** `Model/Order.php:84,132`; `Model/Payment.php:85,176,177`; `Controller/PaymentController.php:127`; `PaymentHandler/StripePaymentHandler.php:47`
- **Evidence:** `strpos($paymentId, 'oe_payments_stripe_') === 0` (Order) vs `str_starts_with(...)` (Payment/handler); `StripeDefinitions` has `STRIPE_WALLET_PAYMENT_ID` and exact-match `isStripePayment()` but no prefix constant/helper.
- **Fix:** Add `StripeDefinitions::PAYMENT_PREFIX` + a static `isStripePaymentMethod(string $id): bool`; route all six sites through it.

### D8 — [Low] OPC modal-id extraction duplicated across the two URL handlers
- **File:** `EventSystem/Handler/OpcModalSuccessUrlHandler.php:47-71` and `OpcModalCancelUrlHandler.php:57-79`
- **Evidence:** identical `getOpcModalId()` (request param → `getVariable('oe_opc_modal_session')` → `modalId`, same try/catch and `is_string` guards).
- **Fix:** Extract an `OpcModalSession` reader service; centralize the session-key constant.

### D9 — [Low] `isConfigured()` defined two divergent ways
- **File:** `Service/ModuleConfigurationService.php:307` (`!empty(getToken()) && !empty(getWebhookSecret())`) vs `Controller/Admin/ModuleConfiguration.php:115` (`!empty(getToken())`).
- **Fix:** Controller should call the service's `isConfigured()`.

---

## 5. No Overengineering

### O1 — [High] Two parallel webhook subsystems; the second is wholly dead **[verified]**
- **File:** `WebhookHandler/PaymentIntentSucceededHandler.php` (registered at `services.yaml:1035` **with no tag**) and `WebhookHandler/ChargeRefundedHandler.php` (**not in `services.yaml` at all**).
- **Evidence:** Nothing collects `WebhookEventHandlerInterface`; the live path is `StripeWebhookProcessor::processEvent()`'s `match` (see **S1**) delegating to `WebhookContractFulfillmentHandler`. The dead pair re-implements contract lookup, fulfillment, refund recording, and audit logging.
- **Fix:** Delete both dead handlers, **or** replace the `match` with a real tagged `WebhookEventHandlerInterface` registry and remove the duplicated paths from `WebhookContractFulfillmentHandler`. Do not keep both.

### O2 — [High] Dead duplicate `StripeCaptureService` / `StripeRefundService` **[verified]**
- **File:** `Service/StripeCaptureService.php:33`, `Service/StripeRefundService.php:24` (registered `public: true`, **zero non-test/non-def callers in `src/`**).
- **Evidence:** the wired paths use the unrelated `final CaptureService` (`services.yaml:701`) and `RefundService` (`:764`). `StripeRefundService::validateRefundAmount()` merely calls `parent::` with no added behavior.
- **Fix:** Delete both classes and their registrations.

### O3 — [Medium] `LazyStripeAdapter` proxy adds no value over the already-lazy factory
- **File:** `Adapter/LazyStripeAdapter.php:56-182`
- **Evidence:** 16 methods of the form `return $this->getAdapter()->createPayment($request);` where `getAdapter()` calls `adapterFactory->getStripeAdapter()`. Only 2 services use it (`services.yaml:789,798`), both via agnostic methods only. `getAdapter()` also caches, defeating the factory's fresh-client-per-call contract.
- **Fix:** Inject `StripeAdapterFactoryInterface` into those 2 services (as every other service already does) and delete the proxy (~130 LOC).

### O4 — [High] `ConfigurationValidator` — 4 of 5 public methods are dead **[verified]**
- **File:** `Service/ConfigurationValidator.php`
- **Evidence:** only `getKeyValidationError()` has production callers (`ModuleConfiguration.php:142`, `StripeOrderController.php:181`). `validateConfiguration`, `validateKeyPair`, `testConnection`, and (external) `validateApiKeyFormat` appear only in the interface — no production callers. The class also mixes format-validation + key-pair matching + live connectivity.
- **Fix:** Remove the unused methods + their interface entries, or fold the single live method into `ModuleConfigurationService`.

### O5 — [High/Medium] `Stripe3DSRequiredEvent` is dispatched but has no handler **[verified]**
- **File:** dispatched at `EventSystem/Handler/StripePaymentStatusHandler.php:155`; `Stripe3DSHandler` referenced only in docblocks (`Stripe3DSRequiredEvent.php:17`, `StripePaymentStatusHandler.php:25`) — **no such class, no listener**.
- **Problem:** Dispatching an event nobody handles is dead indirection; the event's `getClientSecret()/getReturnUrl()` are never read.
- **Fix:** Implement/register the 3DS handler, or inline the context-setting in `handlePending()` and delete the event.

### O6 — [Medium] Dead `StripeStatusMapper` methods kept alive only by tests
- **File:** `Adapter/StripeStatusMapper.php:83-110,129-132,162-170` (`fromPaymentIntent`, `isProcessing`, `isAuthorized`).
- **Evidence:** no production callers (`grep src/`); `fromPaymentIntent` contains speculative refund-detection logic nothing exercises.
- **Fix:** Delete the unused methods (and their tests), or wire them into the real status path.

### O7 — [Medium] Speculative `Payment` model "Stripe-helper" grab-bag
- **File:** `Model/Payment.php:102-219` (`isOtherSourced`, `getPaymentProvider`, `requiresStripeConfiguration`, `getStripePaymentMethodType`, `supportsStripeFeature(...)`).
- **Evidence:** `supportsStripeFeature` returns hardcoded `true` for refunds/partial_refunds regardless of method (dead logic); none of these have callers in the reviewed surface.
- **Fix:** Delete the speculative methods; keep only `isStripePaymentMethod()`.

### O8 — [Medium] Dead methods on otherwise-focused services
- **File:** `Service/CheckoutReturnService.php:114-130` (`getSessionDetails()` — interface + impl, only a unit-test caller); `Service/ModuleConfigurationService.php:251-258` (`getShopBaseUrl()` — only a test comment references it; every real path uses `getSslShopBaseUrl()`).
- **Fix:** Remove from interface + class; drop the `ShopAdapterInterface` ctor arg if `getShopBaseUrl()` was its only use.

### O9 — [Low] Dead constant preserved by a suppression (violates the project's own rule)
- **File:** `Service/ReturnSessionSecurityService.php:31-32`
- **Evidence:** `/** @phpstan-ignore classConstant.unused */ private const QUICK_RETURN_MAX = 900;`
- **Problem:** Suppressing the analyzer to keep dead code contradicts "never suppress static analysis; fix the underlying code."
- **Fix:** Delete the constant.

### O10 — [Low] `StripeEventTranslator` is a 1:1 `instanceof` ladder
- **File:** `EventSystem/Translator/StripeEventTranslator.php:49-58`
- **Evidence:** `if ($event instanceof RefundRequestedEvent) return new StripeRefundRequestEvent($context); ...` → mini-OCP violation per new type; the `!$context instanceof EventContext` early return is effectively unreachable.
- **Fix:** Drive from a `[abstract => concrete]` map, or have Stripe events accept `EventContextInterface`.

---

## 6. Clean Code

### C1 — [High] Hardcoded `shopId: 1` in transaction audit — **multi-shop bug** **[verified]**
- **File:** `Service/CaptureService.php:117` (**active path**); `WebhookHandler/WebhookContractFulfillmentHandler.php:146`; `WebhookHandler/PaymentIntentSucceededHandler.php:120`.
- **Evidence:** `shopId: 1,`
- **Problem:** Audit transactions are always written to shop 1, breaking multi-shop. The request handlers correctly use `$this->shopAdapter->getShopId()` — so this is an inconsistency that is a real functional bug, not a magic number. `CaptureService` is the **wired** capture path, so this ships.
- **Fix:** Inject `ShopAdapterInterface` and use `getShopId()` (or read the shop from the contract). **(Prioritize.)**

### C2 — [Medium] Long methods exceeding the 15–25 line budget
- **File:** `Controller/StripeOrderController.php:159-235` (`createCheckoutSession`, ~76 lines: JSON I/O + challenge check + AGB + cleanup + key validation + basket/user validation + event dispatch + 2 session writes + catch-all); `Controller/Admin/OrderRefundViewDataProvider.php:215-270` (`getStripeTransactionHistory`, ~55 lines); `Adapter/OxidShopOrderService.php` (`createOrder` / `validateOrderState` mixing log + throw).
- **Problem:** Far exceeds the CLAUDE.md target and contradicts the "thin controller" claim in `StripeOrderController`'s own header.
- **Fix:** Extract a JSON responder (mirror `ModuleConfiguration::respondJson`) and split validation/dispatch/session-write into helpers.

### C3 — [Medium] `else`/`elseif` branches that should be early returns
- **File:** `Service/ReturnSessionSecurityService.php:120-135,161-167` (nested `if/else` building `[$score,$warnings]`; `elseif` in `validateTiming`); `EventSystem/Handler/StripePaymentStatusHandler.php:144-159` (`if (in_array(...)) {...} else { handleFailure() }`); `Service/ModuleConfigurationService.php` (`getMode`/`getCaptureMode`).
- **Problem:** CLAUDE.md mandates "no else expressions — use early returns."
- **Fix:** Invert to guard clauses.

### C4 — [Medium] Stringly-typed status/mode/capture literals instead of constants
- **File:** Stripe statuses `'requires_capture'`/`'succeeded'`/`'canceled'`/`'paid'` (`OrderRefundViewDataProvider.php:116,272-281`, `StripeWebhookProcessor.php:220`, `PaymentIntentSucceededHandler.php:124-125`); audit types `'capture'`/`'refund'`/`'completed'`; mode `'test'`/`'live'` + capture `['automatic','manual']` (`ModuleConfigurationService.php:81,91-94,321-324`, `CheckoutSessionService.php:56`); currency default literal `'EUR'` (`CaptureService.php:124`); cancellation-reason whitelist duplicated in `PaymentIntentHelper.php:187` and as literals in `IdempotencyHelper.php:33`.
- **Problem:** A `StripeStatusMapper`/`StripeDefinitions` already exists for exactly this; scattered literals invite drift and silent behavior change on typo.
- **Fix:** Promote to constants/enums in `StripeDefinitions`/`StripeStatusMapper` and reference everywhere.

### C5 — [Low] Inline `\Exception`/`\RuntimeException`/`\Throwable` instead of imported
- **File:** `Service/ContractTokenService.php:47`; `Controller/Webhook/WebhookController.php:52,61`; `EventSystem/Handler/StripeCaptureRequestHandler.php:284`; `Service/RetryCleanupService.php:81` (inline FQCN interface param); `Adapter/LazyStripeAdapter.php:26` (unused `use CreatePaymentResponse`).
- **Problem:** Violates the explicit-imports rule; inconsistent (e.g. `StripeCheckoutSessionHandler` correctly `use`s `RuntimeException`).
- **Fix:** Add `use` statements / remove the dead import.

### C6 — [Low] Inconsistent null handling on the same call within one class
- **File:** `Adapter/OxidShopOrderService.php:158` reads `$basket->getPrice()->getBruttoPrice()` unguarded, while `:93` guards the identical call (`$price ? ... : 0.0`).
- **Fix:** Null-guard `getPrice()` consistently.

### C7 — [Low] Leftover `@TODO`s and misleading PHPDoc in shipped code
- **File:** `Controller/Admin/ModuleConfiguration.php:119` (`@TODO Find a more descriptive name`); `Core/ViewConfig.php:122` (`@TODO probably needs to be enhanced`); `Model/Payment.php:51-69` (docblock claims a `'stripe'` prefix and `->load('stripecreditcard')` example that the code — using `oe_payments_stripe_` — does not implement).
- **Fix:** Resolve the TODOs; correct the docblocks to the real prefix.

### C8 — [Low] Environment-specific Connect URLs hardcoded inline
- **File:** `Controller/Admin/ModuleConfiguration.php:154-162` (`https://stripe-middleware-test.oxid-esales.com/...`, `https://osm.oxid-esales.com/...`, `admin/index.php?cl=StripeConnect&fnc=...`).
- **Fix:** Move base URLs to config/constants and the route to a small URL builder.

---

## 7. Provider-Agnostic Pattern

### A1 — [High] Raw `\Stripe\*` SDK types leak through the adapter interfaces into services/model/view **[verified]**
- **File:** `Adapter/StripeCheckoutAdapterInterface.php:26,31` (`: Session`), `StripePaymentIntentAdapterInterface.php` (`: PaymentIntent`), `StripeRefundAdapterInterface.php` (`: Refund`), `StripeCustomerAdapterInterface.php` (`: Customer`/`: Charge`); consumed raw in `Service/ChargeAmountResolverInterface.php:12` (`use Stripe\Charge`), `Controller/Admin/OrderRefundViewDataProvider.php:16-17,28` (`private ?\Stripe\Charge $cachedCharge`), `Model/Order.php:150,216,234`.
- **Problem:** The adapter is meant to be the boundary that maps to agnostic DTOs, yet it returns/accepts raw SDK objects, spreading `\Stripe\Session/PaymentIntent/Refund/Charge/Customer` across the service, admin-view, and even the OXID `Order` model — coupling them to Stripe SDK v19 shape (`latest_charge`, `refunds->data`).
- **Fix:** Map to neutral value objects (e.g. `ChargeSummary`, `TransactionView`) at the adapter boundary; keep raw SDK types inside the Stripe adapter layer only.

### A2 — [Medium] Agnostic vocabulary owned by the Stripe layer
- **File:** `Adapter/StripeStatusMapper.php:25-31` — `STATUS_PENDING/AUTHORIZED/.../PARTIALLY_REFUNDED` documented "used across all providers" but defined inside Stripe; consumers read `StripeStatusMapper::STATUS_CAPTURED` to get an agnostic value.
- **Problem:** Inverts the agnostic/provider boundary — the normalized vocabulary belongs in `payment-component`.
- **Fix:** Move normalized-status constants to payment-component; keep only Stripe→normalized mapping here.

### A3 — [Medium] Refund handler reaches into the OXID `Order` model + magic DB field
- **File:** `EventSystem/Handler/StripeRefundRequestHandler.php:101-113,126`
- **Evidence:** `$order = oxNew(Order::class); if (!$order->load($orderId)) {...}` then `$transId = $order->oxorder__oxtransid->value ?? null;`
- **Problem:** Capture/cancel handlers resolve the PI ID via the agnostic `ContractRepository::getProviderOrderId`; this one binds a provider event handler to the shop ORM — two resolution strategies for the same value.
- **Fix:** Resolve the PI ID via the contract repository (or the shared resolver from **D4**).

### A4 — [Low] Local `'other'` provider literal next to `StripeDefinitions::PROVIDER`
- **File:** `Model/Payment.php:123` — `return $this->isStripePaymentMethod() ? StripeDefinitions::PROVIDER : 'other';`
- **Fix:** Add `StripeDefinitions::PROVIDER_OTHER` (consistent with the [provider-name-constant] rule).

---

## 8. Coverage Gaps (production classes with no dedicated test)

- `Adapter/StripeWebhookEndpointApi.php` — 133 LOC, the recent STRP-144 webhook-endpoint CRUD against Stripe; **untested** (only the `WebhookEndpointRegistrar` wrapper has a test, and it doesn't touch this class).
- `EventSystem/Handler/OpcModalSuccessUrlHandler.php` / `OpcModalCancelUrlHandler.php` — no test.
- `EventSystem/Translator/StripeEventTranslator.php` — no test.
- `Service/OxidContractLinkedOrderUpdater.php` — `markCancelled`/`markFailed` order-state mutation; only the interface is mocked, the OXID impl is untested.
- `Controller/OxidSessionWriter.php` — `writeSessChallenge()` (CSRF/session-challenge) untested.
- `Component/Widget/StripeCheckoutFooter.php` — `render`/`getCheckoutData`/`getStripeConfig` untested.

---

## 9. What's Already Good (balance)

- The `OrderRefund` god-controller (historically `ExcessiveClassComplexity 62`) **has been dissolved** into `OrderActionDispatcher` + `OrderRefundViewDataProvider` + a `StripePanelProvider`/`StripePanelViewDataBuilder` triad. Good direction (residual SRP work in S4 only).
- The event system is real (PSR-14, tagged handlers, priorities) rather than ceremonial.
- Most adapter methods genuinely delegate to focused helpers; the `StripeChargeAmountResolver` correctly centralizes its `CENTS_PER_UNIT` (the model the rest of D1 should follow).
- The correct testable-subclass seam pattern exists (`StripeOrderControllerTest.php:636`) — T1/T2 should adopt it rather than invent new doubles.

---

## 10. Prioritized Remediation Backlog

**P0 — correctness/security (fix now):**
1. **C1** — `shopId: 1` → `ShopAdapterInterface::getShopId()` in `CaptureService` + the two webhook handlers.
2. **L1** — narrow `Order::validateDeliveryAddress()` to the checkout-return case; add the real test (**T2**).
3. **T1** — rewrite the security tests to exercise the real controller methods (they currently guarantee nothing).

**P1 — delete dead/duplicate code (~600 LOC, lowers risk surface):**
4. **O1** + **S1** — pick ONE webhook dispatch mechanism (tagged registry preferred); delete the other.
5. **O2** — delete `StripeCaptureService` / `StripeRefundService`.
6. **O4** — strip the 4 dead `ConfigurationValidator` methods.
7. **O5/O6/O7/O8/O9/O10/O3** — remove the remaining speculative/dead code; evaluate deleting `LazyStripeAdapter`.

**P2 — centralize duplication:**
8. **D1** — `AmountConverter` (also fixes the JPY/KRW correctness latent bug).
9. **D4/D3/D5** — `AbstractStripeRequestHandler` + `ContractRefundRecorder` + `IdempotentExecutor`.
10. **D2/D7/D6/D8/D9** — single token accessor, payment-prefix helper, shared stale-cleanup, OPC-session reader.

**P3 — structural / boundary:**
11. **A1** — introduce neutral DTOs at the adapter boundary; **A2/A3/A4** follow.
12. **S2/S3/S4/S5** — SRP/DIP cleanups; **C2/C3/C4** clean-code pass; close coverage gaps (§8).

---

*Note: all "High" findings were re-verified against the source after the parallel review (grep/read), and are marked **[verified]** where I personally confirmed them. Line numbers reflect the `b-7.4.x` branch at review time.*
