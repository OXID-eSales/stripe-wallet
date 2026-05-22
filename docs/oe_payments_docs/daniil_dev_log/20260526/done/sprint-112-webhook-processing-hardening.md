# Sprint 112 — Webhook processing hardening (post-2026-05-26 live test)

**Module:** `extensions/stripe`
**Branch base:** `b-7.4.x-webhook-STRP-144` (current working branch) or fresh off `b-7.4.x`
**Mode:** TDD-first. Single feature branch, one PR. Three reviewable commits if split (bugs / audit-fidelity / cleanup).
**Driver:** [`../reports/webhook-processing-observations.md`](../reports/webhook-processing-observations.md)
**Sprint principles:** TDD (RED → GREEN → REFACTOR), SOLID, DI, Liskov substitutability, DRY, Clean Code, no overengineering.

## 1. Why

Today's live test against real Stripe webhooks (orders 89, 90, 91 — capture, refund, cancel paths verified) surfaced 8 findings. Three are bugs / correctness issues. Two are audit-fidelity issues. One is diagnostic noise. One is dead code. Two are design questions deferred to a future sprint.

This sprint fixes the five actionable items in one pass. Each fix follows the same RED→GREEN→REFACTOR rhythm; the production change is intentionally small because the issues are localized. No new abstractions invented — each fix attaches to existing collaborators or extracts at most one tiny SRP-shaped helper.

## 2. Goals

- **G1.** A cancelled or failed `PaymentContract` results in a cancelled `oxorder` row (OXTRANSSTATUS ≠ `OK`) so cancelled orders don't look paid in the admin order list. (Finding 7.)
- **G2.** Skipped webhook events still carry `OXCONTRACTID` linkage in `oe_payments_webhooklogs` when the contract was found. Diagnostic queries no longer miss skipped-but-matched events. (Finding 4.)
- **G3.** Capture / refund / cancel webhooks each write a row to `oe_payments_transaction` so the audit log reflects all transactional activity, not just operator-driven actions. (Finding 1.)
- **G4.** The `WEBHOOK_RECEIVED` log line reports the actual payment-intent ID for `charge.*` events instead of the charge's own ID. Greppable by PI across event types. (Finding 3.)
- **G5.** `charge.captured` is removed from registered events + handler branch, since it's structurally dominated by `payment_intent.succeeded` in the production subscription. (Finding 2.)
- **G6.** `./bin/pre-commit-check.sh --full` green — PHPCS, PHPStan max, PHPMD strict against baselines, all unit + integration tests pass.

## 3. Out of scope (explicit)

| Finding | Why deferred |
|---|---|
| 5 — empty `OXPAYLOAD` | Payload persistence needs a PII/GDPR review (Stripe payloads carry email, billing details). Separate sprint with redaction-strategy decision first. |
| 6 — state-machine compression (`authorized` never persisted) | Doc-vs-code question. Either docs need to be updated to reflect the actual state graph, or the flow needs to expose `authorized` as a real intermediate state — each is a bigger change with cross-team alignment. |
| 8 — `OXSTATEREASON` on cancel | Inconclusive from today's test; needs a single repro test with `cancellation_reason=requested_by_customer` first. One-line follow-up, not sprint-worthy. |

If during this sprint we observe that fixing G1 cleanly *requires* persisting `OXSTATEREASON`, scope creeps minimally to include it. Otherwise it stays a follow-up.

## 4. Implementation plan

LOC budget: **~250 prod + ~350 tests**. Each step lists the test to write first, then the production change, then refactor opportunities.

### Step 1 — G2: skipped events get OXCONTRACTID linkage (lowest risk, easiest first)

**Why first:** zero-risk one-line refactor inside the success/skip branching. Makes the rest of the sprint's tests easier to write because we'll be asserting on `OXCONTRACTID` in subsequent steps.

**RED (test):** add to `tests/Unit/Stripe/Webhook/StripeWebhookProcessorMapResultTest.php`:

```php
public function testSkippedResultWithContractFoundStillLinksContractId(): void
{
    // Arrange: contract exists for PI, handler returns false (state-guard skip)
    $processor = $this->makeProcessorWithContract($pi = 'pi_test', $contractId = 'c1');
    $event = $this->makeRefundEvent($pi);

    // Act
    $result = $processor->processEvent($event);

    // Assert
    self::assertSame('skipped', $result->status);
    self::assertSame($contractId, $processor->getContractIdFromResult($result));  // not null
}
```

Also add the inverse: skipped + contract-not-found → `OXCONTRACTID` stays null.

**GREEN (production):** in `StripeWebhookProcessor::mapHandlerResult`, move the `setContractIdFromProviderOrderId` call out of the success branch:

```php
private function mapHandlerResult(?bool $result, string $paymentIntentId, string $successAction, string $skipReason): WebhookResult
{
    if ($result !== null) {
        // contract was found (true=acted, false=state-skipped) — link it either way
        $this->setContractIdFromProviderOrderId($paymentIntentId);
    }

    if ($result === true) {
        return WebhookResult::success($successAction);
    }

    return $result === false
        ? WebhookResult::skipped($skipReason)
        : WebhookResult::skipped('Contract not found');
}
```

**REFACTOR:** none needed — this is a 3-line reorganization, not a structural change. Method already <25 lines, clean early-return shape preserved. Method name still describes what it does.

**Estimated LOC:** prod +2, tests +60 (2 new test methods + arrange helper).

### Step 2 — G4: receipt log reports correct PI for charge events

**Why second:** trivial, completely orthogonal to the rest, lets us validate steps 3+ by greppable PI.

**RED (test):** add to `tests/Unit/Stripe/Controller/Webhook/WebhookControllerReceiptLogTest.php`:

```php
public function testReceiptLogReportsPaymentIntentIdForChargeRefundedEvent(): void
{
    $event = $this->makeChargeRefundedEvent(
        chargeId: 'ch_xxx',
        paymentIntent: 'pi_yyy'
    );

    $line = $this->captureReceiptLogLine($event);

    self::assertStringContainsString('"payment_intent_id":"pi_yyy"', $line);
    self::assertStringNotContainsString('"payment_intent_id":"ch_xxx"', $line);
}

public function testReceiptLogReportsObjectIdForPaymentIntentSucceededEvent(): void
{
    $event = $this->makePaymentIntentSucceededEvent(paymentIntent: 'pi_zzz');

    $line = $this->captureReceiptLogLine($event);

    self::assertStringContainsString('"payment_intent_id":"pi_zzz"', $line);
}
```

**GREEN (production):** locate where `WebhookController` builds the receipt log payload. Replace the unconditional `data.object.id` lookup with an event-type-aware extractor. **Reuse the existing `StripeWebhookEventParser`** rather than introducing a parallel implementation (DRY). Pseudo-diff:

```php
// before
'payment_intent_id' => $object['id'] ?? 'unknown',

// after
'payment_intent_id' => $this->parser->resolveIdForReceiptLog($event) ?? 'unknown',
```

Add `StripeWebhookEventParser::resolveIdForReceiptLog(WebhookEvent $event): ?string` that:
- For `charge.*` event types → returns `extractPaymentIntentIdFromCharge($event)`
- For `payment_intent.*` → returns `extractPaymentIntentId($event)` (the existing method)
- For any other type → returns `$event->getObjectId()`

**REFACTOR:** if WebhookController already has the parser injected, no new wiring. If not, add it via constructor injection — single dependency, no factory needed. **Liskov check:** `StripeWebhookEventParser` should be type-hinted by interface if the controller composes other parsers; otherwise concrete type is fine (the codebase only has one parser today).

**Estimated LOC:** prod +12 (parser method + controller line), tests +40.

### Step 3 — G1 (the genuine bug): cancelled contract → cancelled oxorder

**Why this matters most:** today's live test on order 91 left an `oxorder` row with `OXTRANSSTATUS=OK` and `OXTRANSID=pi_…` for a payment that was cancelled at Stripe. In an admin order list filtered/coloured by `OXTRANSSTATUS`, this is indistinguishable from a paid order. Customer order-history may also misrepresent state.

**Design:**

The fix attaches to two existing fulfillment-handler methods:
- `WebhookContractFulfillmentHandler::handlePaymentCanceled(string $providerOrderId, string $cancellationReason): ?bool`
- `WebhookContractFulfillmentHandler::handlePaymentFailed(string $providerOrderId, string $failureReason): ?bool`

Both should update the linked `oxorder` after the contract transition succeeds.

**SOLID consideration (SRP):** the fulfillment handler currently knows about contracts only. Loading and updating an `oxorder` is a different responsibility. Extract a thin collaborator:

```php
interface ContractLinkedOrderUpdaterInterface
{
    public function markCancelled(string $contractOxorderId): void;
    public function markFailed(string $contractOxorderId, string $reason): void;
}
```

Inject into the fulfillment handler via constructor (DI). Concrete implementation uses `oxNew(Order::class)` + `load()` + setter + `save()`. **Liskov:** anyone implementing the interface can be swapped (a test fake implementation lives in `tests/Unit/Stripe/Fakes/InMemoryContractLinkedOrderUpdater.php` for unit tests, the production class wraps OXID `oxOrder`).

**No overengineering:** we are *not* adding events, an event dispatcher, a transaction wrapper, or a state-mapping configuration. The interface has exactly two methods — one per state transition that needs oxorder-side mirroring. If a third transition surfaces later, add a third method then.

**RED (tests):**

Three unit tests on `WebhookContractFulfillmentHandler`:
1. `testHandlePaymentCanceledMarksLinkedOrderAsCancelled` — fake updater, assert `markCancelled('order-uuid')` called once with the contract's `oxorderid`.
2. `testHandlePaymentCanceledOnContractWithoutLinkedOrderDoesNotCallUpdater` — contract row with `OXORDERID = NULL` — updater never called.
3. `testHandlePaymentCanceledOnAlreadyCancelledContractStillReturnsTrue` — idempotency.

One integration test in `tests/Integration/Stripe/Webhook/PaymentIntentCanceledIntegrationTest.php`:
- Place a contract row + matching oxorder row (`OXTRANSSTATUS = OK`).
- Process a synthetic `payment_intent.canceled` event through the real `StripeWebhookProcessor` (with the real fulfillment handler + the real `OxorderUpdater`).
- Assert oxorder row now has `OXTRANSSTATUS != 'OK'` and OXTRANSID cleared.

**GREEN (production):**

1. New interface `OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface` — 2 methods, ~15 LOC.
2. New class `OxidEsales\Payments\Stripe\Service\OxidContractLinkedOrderUpdater` implementing it. ~30 LOC, no else, early returns, no comments unless WHY-non-obvious.
3. Register both in `services.yaml` with `autowire: true` (interface alias to concrete).
4. Inject into `WebhookContractFulfillmentHandler` via constructor (one new param).
5. Call `$this->orderUpdater->markCancelled($contract->getOrderId())` inside `handlePaymentCanceled` *after* the state transition + save succeeds. Same for `markFailed`.

**OXTRANSSTATUS choice:** check what value OXID uses for "cancelled" orders elsewhere (search core for `OXTRANSSTATUS` constants). If no canonical "CANCELLED" exists, use empty string + clear OXTRANSID — that's what OXID's own cancel flow does. Document the chosen value in a one-line comment if non-obvious; otherwise no comment.

**REFACTOR:** none expected unless `WebhookContractFulfillmentHandler` grows past 200 LOC after this change. If so, extract the two new code paths into a `PaymentCancellationCoordinator` SRP-shaped collaborator. **Don't pre-extract** — wait until the file actually hurts to read.

**Estimated LOC:** prod +55 (interface + impl + services.yaml + 2 caller edits), tests +120 (3 unit + 1 integration + a fake).

### Step 4 — G3: webhook path writes oe_payments_transaction audit rows

**Why this matters:** today's test confirmed the audit table is empty for both orders 89 and 90 despite multiple real capture+refund events. CLAUDE.md says this table is the auth/capture/refund audit log; in practice it's only the operator-action log. Either reality changes or docs change. This sprint changes reality.

**Design:**

The admin-UI path already writes to this table via `OrderActionDispatcher`. Don't duplicate logic. Locate the existing `TransactionAuditLogger` (or whatever class wraps the insert) — `payment-base` probably owns it. Inject the same collaborator into `WebhookContractFulfillmentHandler` (or its delegate) and call it after each successful contract mutation.

**SOLID / DRY check:** if no shared collaborator exists today and the admin path inlines the SQL/save, **extract one first** before this sprint adds a second caller. The extraction is a tiny prerequisite refactor:
- Find the admin-UI write site (`OrderActionDispatcher` or `CaptureService` / `RefundService`).
- Pull the row-construction + save into `TransactionAuditWriterInterface::recordCapture/recordRefund/recordCancellation(ContractId, Amount, ProviderRef)`.
- Make both call sites depend on the interface.

**No overengineering:** interface has one method per concrete transaction type — no generic `record(TransactionType $type, …)` enum-driven dispatch unless we have three+ call sites that genuinely vary in the same way. Two call sites = two methods each.

**Mapping (Stripe events → audit row type):**

| Webhook | Audit type | Amount source |
|---|---|---|
| `payment_intent.succeeded` (handled via `handlePaymentSucceeded`) | `capture` | `data.object.amount_received` |
| `charge.refunded` | `refund` | `data.object.amount_refunded` |
| `payment_intent.canceled` | `cancellation` | `data.object.amount_capturable` (the released hold) |
| `payment_intent.payment_failed` | `failure` | `data.object.amount` |

**RED (tests):** for each of the four event types, one unit test asserting the audit writer receives the expected call. Add integration tests for capture + refund — those are the highest-traffic events.

**GREEN (production):** depends on extraction shape from §4 above. Roughly:
- (If extraction needed) ~40 LOC for the writer interface + concrete + admin-side refactor.
- ~10 LOC per fulfillment-handler call site to invoke the writer.

**REFACTOR:** if the writer ends up with four near-identical methods, consider whether they actually differ. If yes (different DB fields per type) keep them separate. If they're trivially renames over the same SQL, collapse to one method taking a `TransactionType` value object — but only then, not pre-emptively.

**Estimated LOC:** prod +70, tests +130. May come down if a shared writer already exists.

### Step 5 — G5: remove charge.captured dead path

**Why last:** structurally safest after the audit-log work, because the audit log will now record captures from `payment_intent.succeeded` (no functional regression by removing `charge.captured`).

**RED:**

1. Update the existing event-list test (`WebhookEventCatalogTest` or similar) to assert `charge.captured` is **NOT** in the registered event list.
2. Update `StripeWebhookProcessorTest` to assert that `charge.captured` falls through to the `default` arm returning `skipped: Unhandled event type: charge.captured`.

**GREEN (production):**

1. Remove `'charge.captured'` from `WebhookEventCatalog` event list.
2. Delete the `'charge.captured' => $this->handleChargeCaptured($event)` line from `StripeWebhookProcessor::processEvent`.
3. Delete the `handleChargeCaptured` private method.
4. Delete `WebhookContractFulfillmentHandler::handleChargeCaptured` if not referenced elsewhere (grep first).
5. Delete the corresponding interface method on `WebhookContractFulfillmentHandlerInterface`.

**Verification commands (must produce empty output after this step):**

```bash
grep -rn 'handleChargeCaptured\|charge\.captured' src/ tests/ \
  | grep -v 'docs/oe_payments_docs'  # docs may reference it for history
```

**REFACTOR:** any tests left checking the now-removed handleChargeCaptured method get deleted, not commented out. Per memory rule: "Avoid backwards-compatibility hacks like renaming unused _vars, re-exporting types, adding // removed comments for removed code." Delete cleanly.

**Estimated LOC:** prod **−25** (deletion), tests **+10 / −30** (one new fall-through test, several removed).

## 5. Files touched (estimate)

```
M  src/Stripe/Webhook/StripeWebhookProcessor.php                                            (steps 1, 5)
M  src/Stripe/Webhook/StripeWebhookEventParser.php                                          (step 2)
M  src/Stripe/Controller/Webhook/WebhookController.php                                      (step 2)
M  src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php                          (steps 3, 4, 5)
M  src/Stripe/WebhookHandler/WebhookContractFulfillmentHandlerInterface.php                 (step 5)
M  src/Stripe/Service/WebhookEventCatalog.php                                               (step 5)
M  services.yaml                                                                            (steps 3, 4)

A  src/Stripe/Service/ContractLinkedOrderUpdaterInterface.php                               (step 3)
A  src/Stripe/Service/OxidContractLinkedOrderUpdater.php                                    (step 3)
A  src/Stripe/Service/TransactionAuditWriterInterface.php                                   (step 4, if not in payment-base)
A  src/Stripe/Service/TransactionAuditWriter.php                                            (step 4, if not in payment-base)

A  tests/Unit/Stripe/Webhook/StripeWebhookProcessorMapResultTest.php                        (step 1)
A  tests/Unit/Stripe/Controller/Webhook/WebhookControllerReceiptLogTest.php                 (step 2)
A  tests/Unit/Stripe/WebhookHandler/WebhookContractFulfillmentHandlerCancelTest.php         (step 3)
A  tests/Unit/Stripe/WebhookHandler/WebhookContractFulfillmentHandlerAuditTest.php          (step 4)
A  tests/Integration/Stripe/Webhook/PaymentIntentCanceledIntegrationTest.php                (step 3)
A  tests/Integration/Stripe/Webhook/CaptureAuditTrailIntegrationTest.php                    (step 4)
A  tests/Unit/Stripe/Fakes/InMemoryContractLinkedOrderUpdater.php                           (step 3 helper)

M  tests/Unit/Stripe/Service/WebhookEventCatalogTest.php                                    (step 5)
D  (any tests pinning the charge.captured handler behavior)                                 (step 5)
```

## 6. Quality gates (must all pass before commit)

| Tool | Threshold |
|---|---|
| PHPCS | 0 errors |
| PHPStan level max | 0 new errors (baseline unchanged) |
| PHPMD strict | 0 new (4 baselined items unchanged) |
| Unit tests (Stripe, isolated) | green; expect ~25 new tests, ~5 deletions, net **+20** |
| Integration tests | green; expect 2 new |
| `./bin/pre-commit-check.sh --full` | `✓ ALL CHECKS PASSED — COMMITABLE` |

## 7. RED-first discipline reminder

For each step, the production change is **not** made until at least one failing test for that step exists in the diff. Verify via `git status` before any `src/` edit:

```bash
git status -s | awk '/^A/ && /tests/' | wc -l   # must be >= 1 per step
```

The pre-commit script enforces this only loosely (tests + code in the same commit pass the gate); the discipline is your responsibility. **If a test never went red, you didn't TDD it — you wrote a regression check.** Both are useful; only the first is what this sprint commits to.

## 8. Risk and rollback

- **Risk: G3 changes to `oe_payments_transaction` writes break the admin "Transaction History" view.** Mitigation: the admin view sources from Stripe API (`getStripeTransactionHistory()` in `OrderRefund`), not from this table. New writer adds rows; the view is unaffected. Verify by loading the admin Stripe tab for a fresh order after the change.
- **Risk: G1 oxorder mutation triggers OXID side effects (search index rebuild, etc.).** Mitigation: integration test loads the real oxorder row through OXID's `Order::load()` + asserts post-save state. If side effects surface, fall back to a direct DB UPDATE bypassing the model — but only if the model approach demonstrably breaks something.
- **Risk: G5 removal of `charge.captured` breaks a deployment whose webhook subscription was registered before this sprint and still includes the event.** Mitigation: the controller's `default` match arm already returns `skipped: Unhandled event type`. Existing subscriptions continue to deliver `charge.captured`, controller responds HTTP 200, no DB effect. Optional cleanup: a follow-up admin "Re-sync webhook events" button that calls `WebhookEndpoint::update` with the new (smaller) event list.

Rollback: each step is its own commit. Revert in reverse order if any step regresses an unrelated area.

## 9. Definition of Done

- All 5 goals (G1–G5) have at least one passing test that locked the behavior in green after the production change.
- Pre-commit `--full` green on the working branch.
- This sprint plan moves from `sprints/` to `done/sprint-112-completion-report.md` with a 1-paragraph outcome summary + actual LOC vs. estimate + any deviations from this plan called out.
- Status.md row 7 flipped to ✅ with the completion-report link.
- The observation report's items 1, 2, 3, 4, 7 are crossed off (items 5, 6, 8 explicitly remain open per §3).

## 10. Not committed by this sprint

- Live browser smoke (manual): place an order, complete checkout, capture in admin, refund in admin, cancel in admin — confirm the new audit rows show up where expected. Required for closing the sprint but not part of automated gates.
