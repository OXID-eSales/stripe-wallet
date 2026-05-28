# Sprint 114.4 — Unify webhook dispatch + delete dead handlers

**Module:** `extensions/stripe`
**Priority:** P1 (OCP + dead duplicate subsystem on the security-critical webhook path)
**Findings:** O1 (No Overengineering — two parallel subsystems), S1 (OCP — hardcoded `match`)
**Mode:** 1–2 commits, TDD-first. Touches the webhook processor, `services.yaml`, deletes/realigns 2 handlers.
**Depends on:** none. **Blocks:** 114.5 (dead-code sweep) and 114.8 (handler DRY) — run this first.
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-7** (dispatch via tagged handlers), **R-2.2** (OCP — kill the `match`), **R-9** (delete the dead duplicate subsystem), **R-1.4** (characterization net before refactor).

## 1. Why

There are **two** webhook-handling mechanisms, and they disagree:

- **Live:** `StripeWebhookProcessor::processEvent()` (`src/Stripe/Webhook/StripeWebhookProcessor.php:92`) — a hardcoded `match ($event->type)` with **7** branches (`payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`, `charge.refunded`, `charge.dispute.created`, `checkout.session.completed`, `checkout.session.expired`), each delegating to a private `handleXxx`, most ending in `WebhookContractFulfillmentHandler`.
- **Dead:** `WebhookHandler/PaymentIntentSucceededHandler` (registered at `services.yaml:1035` but **untagged** — nothing collects it) and `WebhookHandler/ChargeRefundedHandler` (**not in `services.yaml` at all**). Both implement `WebhookEventHandlerInterface::supports()/handle()` — the very pattern that would make dispatch open-for-extension — yet nothing invokes them. They re-implement contract lookup, fulfillment, refund recording, and audit logging.

Every new event type currently forces editing `processEvent()` + adding a
private method (OCP violation), while a perfectly good handler interface sits
unused.

## 2. Decision (pick one, default = A)

- **A (recommended): adopt the tagged registry.** Convert each of the 7
  `match` branches into a `WebhookEventHandlerInterface` (reuse/extend the
  two dead handlers where they fit), tag them `webhook.event_handler`,
  inject a `!tagged_iterator` into `StripeWebhookProcessor`, loop calling
  `supports()`. This deletes the `match`, kills the dead/duplicate code, and
  makes new events additive.
- **B (smaller): keep the `match`, delete the dead handlers.** Lower effort,
  but leaves the OCP smell. Choose only if A's risk on the live webhook path
  is unacceptable this cycle.

The rest of this sprint assumes **A**.

## 3. Goals

- **G1.** `StripeWebhookProcessor` resolves handlers via an injected iterator of `WebhookEventHandlerInterface`; the `match` is gone.
- **G2.** All 7 currently-handled event types still produce identical `WebhookResult`s (success/skip/failure + `lastContractId`) — behavior-preserving.
- **G3.** Each handler is `final`, single-event (`supports()` exact match), constructor-injected (incl. `ShopAdapterInterface` for `shopId` — see 114.1), and `services.yaml`-tagged.
- **G4.** No duplicate refund-recording/fulfillment logic across handlers (defer the shared `ContractRefundRecorder` extraction to 114.8 if it bloats this sprint; at minimum do not *add* duplication).
- **G5.** Unknown event type → `WebhookResult::skipped(...)` (preserve current default).
- **G6.** Idempotency + signature verification path unchanged.
- **G7.** `./bin/pre-commit-check.sh --full` green.

## 4. TDD plan (RED first)

1. **`processorDispatchesToSupportingHandler`** — fake handler whose `supports('charge.refunded')` is true; assert it receives the event and its result propagates. **RED** until the iterator wiring exists.
2. **`unknownEventTypeIsSkipped`** — no handler supports → `skipped`.
3. **Per-type characterization tests** — for all 7 types, assert the post-refactor `WebhookResult` equals the pre-refactor one (capture current behavior first as a safety net before deleting the `match`).
4. **`lastContractIdPropagates`** — handler sets contract id → `getContractIdFromResult()` returns it.
5. Migrate/retire the existing `PaymentIntentSucceededHandlerTest` / `ChargeRefundedHandlerTest` to target the unified handlers.

## 5. Implementation steps

1. Write characterization tests for the 7 branches against today's processor (green) — this is the regression net.
2. Define/confirm `WebhookEventHandlerInterface` (handle + supports). Add a `priority` if order matters (dispute vs succeeded).
3. For each branch, create or repurpose a handler:
   - `PaymentIntentSucceededHandler` (un-dead it: add tag) — reconcile with `WebhookContractFulfillmentHandler`'s fulfillment path; keep ONE implementation.
   - `ChargeRefundedHandler` — register + tag; route through the (114.8) refund recorder or, interim, the existing path.
   - Wrap the remaining 5 (`payment_failed`, `canceled`, `dispute.created`, `checkout.completed`, `checkout.expired`).
4. Inject `iterable $handlers` (tagged) into `StripeWebhookProcessor`; replace `match` with a `foreach ($handlers as $h) { if ($h->supports($event->type)) return $h->handle($event); }` + skipped default.
5. Wire tags in `services.yaml`; remove the untagged orphan registration; `oe:cache:clear` + `restart php`.
6. Run the characterization suite — must stay green.

## 6. Risks & rollback

- **Risk (high):** this is the payment webhook entry point. The characterization tests (step 1) are mandatory before deleting the `match`.
- **Risk:** handler ordering for overlapping types (e.g. dispute) — encode priority explicitly and test it.
- **Risk:** DI mis-tag → handlers silently not collected → all events skipped. Add an integration assertion that the iterator is non-empty.
- **Rollback:** revert to the `match` commit; keep the new tests (they pass against both).

## 7. Definition of Done

- G1–G7 met; `grep -n "match (\$event->type)" src/Stripe/Webhook/StripeWebhookProcessor.php` empty (option A).
- Both formerly-dead handlers are either live+tagged or deleted — none left registered-but-unreachable.
- Characterization tests prove behavior parity for all 7 event types.
- Completion report in `done/` documenting the chosen option and the handler→event map.
