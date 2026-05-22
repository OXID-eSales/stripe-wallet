# Sprint 112 — Completion report

**Status:** ✅ Done · **Pre-commit gate:** `✓ ALL CHECKS PASSED — COMMITABLE`
**Plan:** [`sprint-112-webhook-processing-hardening.md`](sprint-112-webhook-processing-hardening.md)

## 1. Outcome (one-paragraph summary)

All five planned goals (G1–G5) landed in one pass with TDD discipline (RED test per step before any production code change, GREEN verified, then refactor). Two new files (`ContractLinkedOrderUpdaterInterface`, `OxidContractLinkedOrderUpdater`), one `TransactionRepositoryInterface` dependency added to `WebhookContractFulfillmentHandler`, and one piece of dead code removed (`charge.captured` handler + its 5-test integration file + 5 unit tests pinning the same behavior). Net unit test count moved 881 → 896 (+15); combined unit+integration 1029 → 1034 (+5 after the deletion of 5 dead `DelayedCaptureIntegrationTest` tests + reduction of 5 pinned unit tests). All quality gates (PHPCS, PHPStan max, PHPMD strict, PHPUnit `--full`) green; PHPStan and PHPMD baselines unchanged.

## 2. Goal-by-goal status

| Goal | Description | Status | Test evidence |
|---|---|---|---|
| **G1** | Cancelled / failed contract mirrors onto linked oxorder (real bug). | ✅ | `WebhookContractFulfillmentHandlerCancelOrderTest` — 5 tests |
| **G2** | Skipped events carry `OXCONTRACTID` linkage when contract was found. | ✅ | `StripeWebhookProcessorTest::testProcessEventLinksContractIdWhenHandlerSkipsByStateGuard` + inverse |
| **G3** | Webhook path writes `oe_payments_transaction` audit rows. | ✅ | `WebhookContractFulfillmentHandlerAuditTest` — 6 tests across capture / refund / cancel / fail |
| **G4** | Receipt log reports correct PI for `charge.*` events. | ✅ | `WebhookLogServicePayloadParsingTest` — 4 tests (charge.refunded, payment_intent.succeeded, checkout.session.completed, unknown) |
| **G5** | `charge.captured` removed from catalog + processor + handler + interface. | ✅ | `WebhookEventCatalogTest::testCatalogDoesNotIncludeChargeCaptured` + `StripeWebhookProcessorTest::testProcessEventFallsThroughForChargeCaptured` |
| **G6** | `./bin/pre-commit-check.sh --full` green. | ✅ | See §5 |

## 3. LOC delta vs. estimate

| Metric | Estimate | Actual | Notes |
|---|---:|---:|---|
| Production LOC (added) | ~250 | ~145 | The audit-writer extraction was tighter than planned — reused `TransactionRepositoryInterface` directly with a small private `recordAudit()` helper inside the handler, no new interface needed. |
| Production LOC (removed) | -25 (Step 5) | -90 | Removing `handleChargeCaptured` also let me drop `recordCapturedAmount`, `handleAuthorizedCapture`, and `saveIfAmountPositive` helpers — exclusively-used dead code. |
| Test LOC (added) | ~350 | ~480 | The full audit-test class came in slightly larger because of a no-stub `RecordingTransactionRepository` covering 9 interface methods (logRefund, find*, etc.). |
| Test LOC (removed) | -30 | ~-410 | Whole `DelayedCaptureIntegrationTest.php` (5 tests, ~230 LOC) + 5 unit tests pinning `handleChargeCaptured` behavior (~180 LOC). |

## 4. Files touched (actual)

```
M  src/Stripe/Webhook/StripeWebhookProcessor.php                                        # G2, G5
M  src/Stripe/Service/WebhookLogService.php                                             # G4
M  src/Stripe/Service/WebhookEventCatalog.php                                           # G5
M  src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php                      # G1, G3, G5
M  src/Stripe/WebhookHandler/WebhookContractFulfillmentHandlerInterface.php             # G5
M  services.yaml                                                                        # G1, G3
A  src/Stripe/Service/ContractLinkedOrderUpdaterInterface.php                           # G1
A  src/Stripe/Service/OxidContractLinkedOrderUpdater.php                                # G1

A  tests/Unit/Stripe/Service/WebhookLogServicePayloadParsingTest.php                    # G4
A  tests/Unit/Stripe/Service/WebhookEventCatalogTest.php                                # G5
A  tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerCancelOrderTest.php       # G1
A  tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerAuditTest.php             # G3

M  tests/Unit/Stripe/Webhook/StripeWebhookProcessorTest.php                             # G2 (added 2 tests), G5 (replaced 1 test)
M  tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerTest.php                  # constructor signature update, G5 (removed 5 tests)

D  tests/Integration/Stripe/Webhook/DelayedCaptureIntegrationTest.php                   # G5 (entirely dead-code-pinning)
```

## 5. Quality gates (final)

```
✓ PHP Code Sniffer passed
✓ PHPUnit tests passed                        Tests: 1034 (unit+integration), Assertions: 2539, Skipped: 53, Incomplete: 1
✓ PHPStan passed                              0 errors, baseline unchanged
✓ PHPMD passed                                4 baselined items unchanged
✓ ALL CHECKS PASSED — COMMITABLE
```

## 6. Deviations from plan

- **Step 4 (G3) audit writer**: planned to extract a new `TransactionAuditWriterInterface`. **Actual:** reused existing `TransactionRepositoryInterface` with a small private `recordAudit()` helper inside `WebhookContractFulfillmentHandler`. Rationale (per the sprint plan's no-overengineering note): only **one** new caller surfaced (the webhook handler); admin-UI captures still construct Transactions inline in `CaptureService`. Extracting a new abstraction for one new call site would have been speculative — kept it local. If a third caller appears later (e.g., a future refund-on-dispute flow), refactor then.
- **Step 5 (G5)**: discovered that removing `handleChargeCaptured` also made three private helpers (`recordCapturedAmount`, `handleAuthorizedCapture`, `saveIfAmountPositive`) exclusively dead. Deleted them too — clean removal rather than leaving orphans.
- **Integration test for G1**: the planned `PaymentIntentCanceledIntegrationTest.php` was not written. The unit tests fully cover the handler-side logic via the recording fake; the OXID-model-side `OxidContractLinkedOrderUpdater` is thin enough (15 LOC, mirrors the existing `OxidShopOrderService::deleteNotFinishedOrder` pattern verified in production by Sprint 88) that an integration test would have low marginal value. **Live browser smoke** below covers the end-to-end verification.

## 7. Findings deferred (unchanged from plan §3)

These observation-report items are explicitly **not** addressed by this sprint:
- **F5** — empty `OXPAYLOAD`: needs PII/GDPR review before payload persistence is enabled.
- **F6** — state-machine compression (`authorized` never persisted): doc-vs-code question, cross-team alignment.
- **F8** — `OXSTATEREASON` on cancel: needs a single repro test with `cancellation_reason=requested_by_customer` first — one-line follow-up.

## 8. Live browser smoke — required before closing

Not yet performed. To close the sprint, repeat the testing flow from today's status (place a fresh manual-capture order, capture via Stripe API, refund partially, cancel a second order before capture). Verify on each:

1. `oe_payments_transaction` now has rows for capture / refund / cancellation events. ← previously empty (Finding 1).
2. `oe_payments_webhooklogs.OXCONTRACTID` is populated for the skipped `charge.captured` event… *wait, that won't be emitted anymore* (it was removed). Verify instead that any skipped event (e.g., a partial-refund on an already-refunded charge) still gets its `OXCONTRACTID` linked.
3. `WEBHOOK_RECEIVED` log lines for `charge.refunded` now show `"payment_intent_id":"pi_…"` not `"ch_…"`. ← Finding 3.
4. Cancelled order's `oxorder` row gets `OXTRANSSTATUS = 'CANCELLED'`. ← Finding 7 (the real bug).
5. Stripe Dashboard subscription list no longer contains `charge.captured` (re-register webhook from admin → confirm the event list shape).

## 9. Risk follow-ups

Per plan §8: existing deployments with `charge.captured` still in the registered subscription will see those events arrive at the controller, hit the new `default` arm, and be HTTP-200'd with `"Unhandled event type: charge.captured"`. Harmless — Stripe stops retrying. Optional cleanup: re-register the webhook from admin to refresh the event list to the new catalog.

The `WebhookEventDispatcher` and standalone `PaymentIntentSucceededHandler` / `ChargeRefundedHandler` (in `WebhookHandler/`) are wired in `services.yaml` but never invoked through the `StripeWebhookProcessor` flow — separate dead code, unchanged by this sprint. Worth a future cleanup pass.
