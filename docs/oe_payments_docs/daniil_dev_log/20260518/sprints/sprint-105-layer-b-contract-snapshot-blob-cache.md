> **🧊 FROZEN — 2026-05-18**
>
> Sprint 104 (Layer A) collapsed the panel render's Stripe API fan-out
> from ≈10 round-trips to 1, delivering the ~80 % wall-clock win on
> its own. The cross-request blob cache proposed here optimises the
> remaining one cold round-trip down to zero on warm renders — a real
> but smaller gain, and one that brings real costs (cross-module
> migration, digest/TTL/webhook-invalidation surface, contract-row
> blob growth).
>
> Not justified by current operator feedback. Resume only if:
> (a) operators report that even the post-Sprint-104 render is too
> slow, or (b) the latency budget tightens (e.g. dashboards needing
> sub-100 ms panel paint).
>
> Plan is kept intact for that scenario; no design changes needed
> to resume.

# Sprint 105 — Stripe panel: cross-request snapshot blob with digest invalidation

**Modules:** `extensions/payment-base` (one schema change),
`extensions/stripe` (the consumer). Cross-module sprint —
coordinate the two patches in one merge window.
**Mode:** two-step split.
- **105.1** adds the storage column to `oe_payments_contract` in
  `payment-base`, with the new accessor on `PaymentContract`.
- **105.2** lands the Stripe-side read/write/digest/invalidation
  logic and the listener wiring.
105.1 ships first (additive schema), 105.2 ships once 105.1 is
in main. Between the two sub-sprints the column exists but is
never written — zero behaviour change.
**Gates on:** [Sprint 104](sprint-104-layer-a-dedup-stripe-api-calls.md)
must be merged. Layer A removes the in-request fan-out so Layer B's
performance work is measurable.
**Trigger:** [report `02-stripe-payment-tab-latency.md`](../reports/02-stripe-payment-tab-latency.md) — Layer B.

## 1. Why

After Sprint 104, one panel render = one expanded-PI Stripe API
call (~150 ms). That one call is still paid on **every** render,
warm or cold. An admin who clicks back into the same order three
times in a minute pays it three times. The pattern is wrong for
display state: stale-tolerant, cheap to evict, never the source of
truth.

Sprint 105 caches the expanded-PI response in a single JSON column
on the existing `oe_payments_contract` row (1:1 with the order),
keyed on a digest of mutating fields so we can tell when the cache
is stale without re-fetching the full object. Webhook handlers
hard-invalidate; a 5-minute TTL is the belt-and-braces.

The reporter has **explicitly vetoed** persisting per-transaction
rows. The audit log table (`oe_payments_transaction`) is left
untouched — the new column lives on the *contract* row, which
already exists 1:1 with the order. Storage growth is bounded by
order count, not by event volume.

## 2. Goals

- **G1.** `oe_payments_contract` gains one `LONGTEXT NULL` column
  (proposed name: `OXPROVIDERSNAPSHOT` — generic, so PayPal can
  reuse it later without a second migration).
- **G2.** New service `StripePanelSnapshotCache` (in Stripe module)
  owns reads, writes, digest computation, and TTL checks. The
  service is the single seam — the panel view-data builder calls
  it and gets back an expanded PaymentIntent-shaped object,
  regardless of whether the data came from the API or the blob.
- **G3.** Webhook handlers that already exist
  (`PaymentIntentSucceededHandler`, `ChargeRefundedHandler`,
  `PaymentIntentPaymentFailedHandler` — full list grep'd in §3.4)
  add one line: clear the snapshot on the matching contract.
- **G4.** Local admin actions (capture, refund, cancel) clear the
  snapshot for the affected contract immediately, so the next
  panel render reads fresh data.
- **G5.** 5-minute hard TTL — any snapshot older than that is
  treated as stale regardless of digest.
- **G6.** Steady-state warm render issues **zero** Stripe API
  calls. Cold render issues exactly one (the expand fetch).
  Stale-but-present render issues one **cheap** PI retrieve (no
  expand) to compare digests; if digest matches, the blob is
  promoted to fresh and no expand fetch happens.
- **G7.** `./bin/pre-commit-check.sh --full` green.

## 3. Scope inventory

### 3.1 Sub-sprint 105.1 — payment-base migration

| File | Concern |
|---|---|
| `extensions/payment-base/src/Migrations/Version<NEW>...php` | One new migration: `ALTER TABLE oe_payments_contract ADD OXPROVIDERSNAPSHOT LONGTEXT NULL` plus a corresponding `down()` that drops the column. |
| `extensions/payment-base/src/Contract/PaymentContractInterface.php` | Add `getProviderSnapshot(): ?string` and `setProviderSnapshot(?string $json): void`. Documented as opaque to payment-base — the column is owned by the consuming provider module. |
| `extensions/payment-base/src/Contract/PaymentContract.php` | Concrete getters/setters; serialise/hydrate alongside existing fields. |
| `extensions/payment-base/src/Repository/...` | The contract repository's hydration mapping picks up the new column. |
| `extensions/payment-base/tests/Unit/Contract/PaymentContractTest.php` | Round-trip test: set / save / load / read back. |

### 3.2 Sub-sprint 105.2 — Stripe consumer

| File | Concern |
|---|---|
| `src/Stripe/Service/StripePanelSnapshotCache.php` (new) | The cache service. Methods: `getOrFetch(Order $order): ?PaymentIntent`, `invalidate(string $contractId): void`. |
| `src/Stripe/Service/StripePanelSnapshotDigest.php` (new) | Pure function: `digest(PaymentIntent $pi): string`. SHA-1 of `status ‖ amount_received ‖ latest_charge.id ‖ latest_charge.amount_captured ‖ latest_charge.amount_refunded ‖ count(refunds)`. |
| `src/Stripe/Admin/StripePanelViewDataBuilder.php` | Replace `$provider->getPaymentIntent(...)` and the transaction-history fetch with one read through `StripePanelSnapshotCache::getOrFetch()`. |
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | `getPaymentIntent()` and `getLastCharge()` read from the cache, not the adapter, when a fresh-enough snapshot is available. |
| `services.yaml` | Two new service registrations: `StripePanelSnapshotCache` and `StripePanelSnapshotDigest`. `OrderRefundViewDataProvider` and `StripePanelViewDataBuilder` get the cache injected. |
| `src/Stripe/WebhookHandler/PaymentIntentSucceededHandler.php` | One line: `$this->snapshotCache->invalidate($contract->getId())` after the existing contract-state update. |
| `src/Stripe/WebhookHandler/ChargeRefundedHandler.php` | Same one line. |
| `src/Stripe/WebhookHandler/<all-other-PI-handlers>` | Inventory in §3.4; all get the same invalidation hook. |
| `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php` | Invalidate after successful capture. |
| `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` | Same. |
| `src/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandler.php` | Same. |
| `tests/Unit/Stripe/Service/StripePanelSnapshotCacheTest.php` (new) | Pins read/write/digest/TTL behaviour. |
| `tests/Unit/Stripe/Service/StripePanelSnapshotDigestTest.php` (new) | Pins the digest formula and that it changes on every relevant field. |
| `tests/Integration/Stripe/Service/StripePanelSnapshotCacheIntegrationTest.php` (new) | Hits a real contract row in the test DB — write blob, read back, assert digest. |

### 3.3 Storage shape

The blob is the full Stripe expanded PaymentIntent JSON, wrapped
in a thin envelope:

```json
{
  "digest":     "<sha1>",
  "fetched_at": "<RFC3339 timestamp>",
  "payload":    { /* expanded PI as Stripe returned it */ }
}
```

The envelope keeps the digest and timestamp out-of-band from the
PI's own fields so a hostile future change to Stripe's schema
can't accidentally collide with our keys.

Sized: typical expanded PI ≈ 3–10 KB; LONGTEXT supports up to 4 GB
per row, so headroom is irrelevant. At 10 KB × 100 K orders ≈ 1 GB
of contract-table growth — comparable to the per-transaction
audit-log alternative the user vetoed, but with **bounded** growth
(orders, not events) and zero index pressure (the column is read
by primary key only).

### 3.4 Webhook handler inventory (to be confirmed at sprint kickoff)

```bash
grep -lr 'implements WebhookEventHandlerInterface' \
        source/extensions/stripe/src/Stripe/WebhookHandler/
```

Current handlers (per `src/Stripe/WebhookHandler/`):

- `PaymentIntentSucceededHandler`
- `ChargeRefundedHandler`
- `PaymentIntentPaymentFailedHandler` (if present)

Plus the auth-related handlers in the EventSystem path. The
authoritative list is whatever the above grep returns — sprint
exit gate asserts every match has an `invalidate()` call.

### 3.5 Explicitly *not* touched

- The `oe_payments_transaction` audit log table. Untouched, by
  user veto.
- The webhook payload-storage table (`oe_payments_webhooklogs`).
  Untouched — that table stores raw events, not derived state.
- Frontend templates, OXID admin templates other than the
  view-data builder.
- The contract state machine.

## 4. Design

### 4.1 The cache decision flow

```
getOrFetch(Order $order):
    contractId = contract for this order
    blob       = contract.getProviderSnapshot()

    if blob is null OR JSON-parse fails:
        return fullFetchAndStore(order, contractId)

    if blob.fetched_at < now() - 5m:
        return digestCompareThenMaybeFullFetch(order, contractId, blob)

    return parse(blob.payload)               # fast path — no API call
```

`digestCompareThenMaybeFullFetch`:

```
fresh  = stripe.PaymentIntent.retrieve(piId)    # NO expand — cheap
digest = computeDigest(fresh)

if digest == blob.digest:
    # PSP-side state unchanged. Promote the blob: rewrite envelope
    # with new fetched_at, keep the (still-valid) payload.
    contract.setProviderSnapshot(envelope with new fetched_at)
    return parse(blob.payload)

return fullFetchAndStore(order, contractId)
```

`fullFetchAndStore`:

```
pi = stripe.PaymentIntent.retrieve(piId, expand=[latest_charge.refunds])
contract.setProviderSnapshot({digest: computeDigest(pi),
                              fetched_at: now,
                              payload:    pi.toJson()})
return pi
```

### 4.2 Invalidation channels

- **Webhook channel.** Every relevant handler ends with
  `$this->snapshotCache->invalidate($contract->getId())`. The
  call is idempotent — setting `OXPROVIDERSNAPSHOT` to NULL.
- **Local action channel.** Listeners on the existing
  `StripeCaptureRequestEvent` / `StripeRefundRequestEvent` /
  `StripeCancelAuthorizationRequestEvent` post-event hooks call
  the same `invalidate()`.
- **TTL channel.** 5 minutes. The TTL fires only when the other
  two channels missed (webhook gap, Stripe Dashboard edits).

### 4.3 Why this is one service, not three

`StripePanelSnapshotCache` owns the *control flow* — when to read
from the blob, when to digest-check, when to full-fetch. The
*content* of the snapshot envelope is bytes from Stripe; the
*invalidation* is a one-line setter. Splitting the service across
"reader / writer / invalidator" would scatter the cache invariants
across three classes for no testability gain. One class, one
responsibility (panel-snapshot freshness), three test surfaces
(getOrFetch, invalidate, digestCompare) tested independently.

## 5. The five pillars

### 5.1 SOLID

- **SRP.** `StripePanelSnapshotCache` owns snapshot freshness.
  `StripePanelSnapshotDigest` owns the formula. Webhook handlers
  own their domain transitions and call `invalidate()` as a
  domain-irrelevant detail (no new domain knowledge).
- **OCP.** Adding a new mutating Stripe event later means
  registering one more webhook handler and adding the same
  one-line invalidation call. The cache service itself does not
  change.
- **LSP.** `StripePanelSnapshotCache` is `final`. The injected
  abstraction across the panel-builder boundary is a small
  interface (`PanelSnapshotCacheInterface`) — substitutability
  rules: returns either a parsed `PaymentIntent` or `null` (the
  fetch failed); never throws across the boundary.
- **ISP.** The interface is two methods (`getOrFetch`,
  `invalidate`); webhook handlers see only `invalidate` via a
  narrow `PanelSnapshotInvalidatorInterface` subset.
- **DIP.** Webhook handlers depend on the invalidator interface,
  not the concrete cache class. Already today they receive
  dependencies via constructor DI — adding one more argument is
  symmetric with the existing pattern.

### 5.2 TDD

Walking order:

1. **105.1 first.** Write the payment-base round-trip test on
   `PaymentContract::set/getProviderSnapshot()`. Implement.
   Ship 105.1.
2. **Digest red.** Write `StripePanelSnapshotDigestTest` first.
   Six cases — one per mutating field — plus a stability case
   (identical PIs produce identical digests). Red.
3. **Digest green.** Implement `StripePanelSnapshotDigest`.
4. **Cache red.** Write `StripePanelSnapshotCacheTest`. Cases in
   §6.
5. **Cache green.** Implement the cache service.
6. **Wire panel builder.** Existing tests for the builder run
   green against the cache (resolver injection identical to
   Sprint 103's pattern).
7. **Wire webhook handlers.** Each handler's existing unit test
   gains one expectation: `invalidate()` is called with the
   contract id.
8. **Integration test.** Hits a real DB row; round-trip blob.
9. **Pre-commit gate.**

### 5.3 DRY

The digest formula exists in `StripePanelSnapshotDigest::digest()`
and nowhere else. Grep gate:

```bash
grep -rn 'sha1\|md5\|hash(' source/extensions/stripe/src/ \
   | grep -i 'snapshot\|digest\|amount_refunded\|amount_captured'
```

returns only matches inside `StripePanelSnapshotDigest.php`.

### 5.4 Liskov

`PanelSnapshotCacheInterface::getOrFetch(Order $order):
?PaymentIntent`:

- **Precondition.** `$order` is a Stripe-paid order with a
  non-empty `oxtransid` and a hydrated contract row.
- **Postcondition.** Returns either a `PaymentIntent` whose
  `id` matches the order's `oxtransid`, or `null` if either the
  blob was unreadable AND the API fetch failed. Never throws
  across the boundary.

`PanelSnapshotInvalidatorInterface::invalidate(string
$contractId): void`:

- **Postcondition.** After the call returns, the next
  `getOrFetch` for any order linked to that contract performs a
  full fetch and rewrites the blob.

### 5.5 Clean Code / DI

- The cache service is constructor-injected with
  `StripeOrderApiService`, `ContractRepositoryInterface`,
  `StripePanelSnapshotDigest`, `LoggerInterface`,
  `ClockInterface` (for testable `now()`).
- Methods ≤ 25 lines. The decision flow in §4.1 is implemented
  as four small methods (`readBlob`, `isFresh`,
  `digestCompareAndPromote`, `fullFetchAndStore`).
- No `Registry::get(...)`. No `new` in business methods.
- One `private const TTL_SECONDS = 300`.

## 6. Test matrix

### 6.1 `StripePanelSnapshotDigestTest`

| # | Test | Asserts |
|---|---|---|
| 1 | `testIdenticalPaymentIntentsProduceIdenticalDigests` | Same PI fixture → same digest |
| 2 | `testStatusChangeChangesDigest` | `requires_capture` vs `succeeded` differ |
| 3 | `testAmountReceivedChangeChangesDigest` | 100 vs 200 differ |
| 4 | `testAmountCapturedChangeChangesDigest` | 100 vs 200 differ |
| 5 | `testAmountRefundedChangeChangesDigest` | 0 vs 50 differ |
| 6 | `testRefundsCountChangeChangesDigest` | 1 refund vs 2 refunds differ |
| 7 | `testUnrelatedFieldChangeDoesNotChangeDigest` | Changing `description` or `metadata` does **not** change digest |

### 6.2 `StripePanelSnapshotCacheTest`

| # | Test | Scenario | Asserts |
|---|---|---|---|
| 1 | `testColdBlobMissTriggersFullFetchAndWrite` | Contract has NULL snapshot | One expand fetch; one `setProviderSnapshot` write |
| 2 | `testFreshBlobUnderTtlReturnsBlobNoFetch` | Snapshot fetched_at = now − 2 m | Zero API calls; returns parsed payload |
| 3 | `testStaleBlobWithMatchingDigestPromotesNoExpandFetch` | Snapshot fetched_at = now − 10 m, PSP digest matches | One *plain* PI retrieve; zero expand fetches; one `setProviderSnapshot` write (envelope refreshed) |
| 4 | `testStaleBlobWithMismatchedDigestTriggersFullFetch` | Snapshot fetched_at = now − 10 m, PSP digest differs | One plain retrieve; one expand retrieve; one `setProviderSnapshot` write (payload replaced) |
| 5 | `testInvalidateClearsBlob` | Call `invalidate()` then `getOrFetch` | After invalidate: setProviderSnapshot(null); after getOrFetch: full fetch + write |
| 6 | `testJsonParseFailureFallsBackToFullFetch` | Snapshot column contains malformed JSON | One full fetch + write; no exception thrown |
| 7 | `testApiFailureOnStaleBlobReturnsCachedPayload` | Stale blob, API throws | Returns parsed payload from blob; logs warning; does not invalidate |

### 6.3 Webhook integration

For each handler in §3.4, the existing unit test gains one
expectation:

```php
$invalidator->expects($this->once())
    ->method('invalidate')
    ->with($this->equalTo($contract->getId()));
```

## 7. Acceptance gates

- [ ] 105.1 schema migration runs cleanly on a fresh DB.
- [ ] 105.1 + 105.2 deploy in order; no behaviour change
      between them (105.1 is additive only).
- [ ] `./bin/pre-commit-check.sh --full` green. Test total ≥
      pre-sprint baseline + (7 digest + 7 cache + N webhook
      cases).
- [ ] PHPStan max: 0 new errors. PHPCS: 0 errors. PHPMD:
      0 new findings.
- [ ] DRY grep gate from §5.3 returns matches only inside
      `StripePanelSnapshotDigest.php`.
- [ ] Manual smoke: open Stripe tab on a fresh order → 1
      Stripe API call in Network panel. Reload within 5 min →
      0 API calls. Trigger a refund → reload → 1 API call.
- [ ] DB inspection: `OXPROVIDERSNAPSHOT` populated after the
      first render; cleared after a webhook arrives.

## 8. Out of scope / explicit deferrals

- **Async transaction-history UI.** Sprint 106 (Layer C).
- **Pre-fetching from the order list.** Vetoed in the latency
  report §5 (recreates the original fan-out at N=list-size).
- **PayPal usage of `OXPROVIDERSNAPSHOT`.** The column is
  generic but no PayPal consumer is wired in this sprint; that
  is a follow-up if PayPal sees the same latency.
- **Compression of the blob.** LONGTEXT + 10 KB JSON is fine.
  If contract row counts cross 1 M orders, revisit with gzip.

## 9. Risk register

- **Risk: webhook gap on `charge.refunded`.** If the endpoint's
  subscribed events list does not include the event, the digest
  channel is the only invalidation path and the user sees up to
  5 minutes of stale data. **Mitigation:** at sprint kickoff,
  audit `metadata.php` and the runtime webhook-endpoint config
  in Stripe Dashboard. Document the required event list.
- **Risk: digest collision.** SHA-1 truncated to small field set
  has small but nonzero collision probability. **Mitigation:**
  the TTL bounds the staleness; the next 5-minute boundary
  refreshes regardless.
- **Risk: parallel render writes.** Two concurrent admin opens of
  the same order both miss the blob and both fetch + write.
  **Mitigation:** last-write-wins is acceptable for a cache —
  the payloads are equivalent.
- **Risk: schema migration on production with hot writes.**
  `ALTER TABLE oe_payments_contract ADD COLUMN ... LONGTEXT NULL`
  on a populated table requires care. **Mitigation:** the
  column is nullable; MySQL 8 supports `ALGORITHM=INSTANT` for
  NULLABLE-column adds — verify before scheduling the rollout.
- **Risk: `oe_payments_contract` row missing for legacy orders.**
  Some pre-payment-base orders may not have a contract row.
  **Mitigation:** `getOrFetch` falls back to direct API path
  (today's behaviour) if the contract row is null.

## 10. Done definition

- [ ] §7 acceptance, every box.
- [ ] Sprint markdown moves to `done/` with a completion report
      recording: pre/post API-call counts on warm renders,
      manual smoke screenshots, schema-migration runtime on a
      copy of production data.
- [ ] `status.md` updated.
- [ ] Sprint 106 follow-up confirmed unblocked.
- [ ] payment-base release note records the new column.
