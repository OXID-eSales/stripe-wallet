# 2026-05-18 — Stripe module dev log

_Continues from `../20260513/`._

| # | Task | Scope | Status | Landed |
|---|---|---|---|---|
| 1 | CI fix on `b-7.4.x` — commit `0f4e4d2` ("external refund requests") registered `OxidEsales\PaymentBase\Service\PaymentCaptureStatusQueryInterface` in `services.yaml` but forgot to `git add` the class file. Activation step in the Development workflow failed: `Invalid service ...: class "OxidEsales\Payments\Stripe\Service\StripePaymentCaptureStatusQuery" does not exist.` | `stripe`, `b-7.4.x` | ✅ Landed — commit `8854893` pushed to `origin/b-7.4.x`. Development run [26020934415](https://github.com/OXID-eSales/stripe-wallet/actions/runs/26020934415) ✓ green. Playwright run failed for unrelated infrastructure reason (`pay1.oxid.dev` docker stack down). | 2026-05-18 |
| 2 | [Report 01](reports/01-partial-capture-negative-refund-amount.md) — analysed why the admin Stripe tab on a partially-captured order shows "Available for refund: −197 EUR" and rejects every refund attempt with *"Minimum value (0.01) must be less than the maximum value (-197)"*. Root cause: Stripe encodes the auth-released uncaptured remainder as a Refund object on the charge, increments `charge.amount_refunded` by it, and our two display helpers (`OrderRefundViewDataProvider::getRemainingRefundableRaw`, `Order::getStripeRefundedAmount`) treat that field as the customer-refunded total. | `stripe` (analysis only) | ✅ Filed | 2026-05-18 |
| 3 | [Sprint 103](done/sprint-103-fix-partial-capture-negative-refund-amount.md) — fix the partial-capture refund-form bug from Report 01. Introduces `StripeChargeAmountResolver` (final, behind `ChargeAmountResolverInterface`) that owns the formula `R_customer = max(0, amount_refunded − max(0, amount − amount_captured))`. Re-routes `OrderRefundViewDataProvider::getRemainingRefundableRaw` / `isOrderRefundable` and `Order::getStripeRefundedAmount` / `hasStripeRefunds` through the resolver. | `stripe` | ✅ Done — 19 new unit tests (6 resolver + 7 provider + 6 model) pass; PHPCS / PHPStan max / PHPMD all clean; DRY grep gate returns zero. Uncommitted (batched with Sprint 104). | 2026-05-18 |
| 4 | [Report 02](reports/02-stripe-payment-tab-latency.md) — analysed the admin Stripe tab sluggishness. Inventoried ≈10 Stripe API round-trips per panel render: two render-path methods pass `refresh=true` (defeating the provider's in-request cache), the `Order` class extension makes its own PI+Charge fetch for each of its three accessors, and the transaction-history uses a separate expanded fetch. Includes a deterministic Playwright timing-harness spec and a three-layer fix proposal. | `stripe` (analysis only) | ✅ Filed | 2026-05-18 |
| 5 | [Sprint 104](done/sprint-104-layer-a-dedup-stripe-api-calls.md) — Layer A. Dropped `refresh=true` at the two render-path call-sites; memoised `Order::getStripeCharge()`; promoted the expanded PaymentIntent as the canonical source via the new `fetchExpandedPaymentIntent()` seam. Collapses ≈10 round-trips → **1** per render. See [completion report](done/sprint-104-completion-report.md). | `stripe` | ✅ Done — 4 new call-count tests pass; combined Sprint 103+104 suite **23/23**; pre-commit `--full` `ALL CHECKS PASSED — COMMITABLE`; PHPCS / PHPStan max / PHPMD all clean; DRY grep gate (`refresh=true` in render path) returns zero. Uncommitted (batched with Sprint 103). | 2026-05-18 |
| 6 | [Sprint 105](sprints/sprint-105-layer-b-contract-snapshot-blob-cache.md) — Layer B. Cross-module contract-row snapshot blob with digest idempotency. | `payment-base` + `stripe` | 🧊 Frozen — Layer A delivered the ~80 % win alone; the warm-render zero-API-call optimisation is not justified by current operator feedback. Plan kept intact for future resumption. | — |
| 7 | [Sprint 106](sprints/sprint-106-layer-c-async-transaction-history-spinner.md) — Layer C. Async transaction-history fragment + new admin XHR endpoint. | `stripe` | 🧊 Frozen — over-engineered for the actual operator pain point. Sprint 107 covers the in-flight-action UX gap with ~80 lines of CSS+JS. Plan kept intact. | — |
| 8 | [Sprint 107](sprints/sprint-107-busy-overlay-on-admin-actions.md) — busy overlay (blur + spinner) on Refund / Capture / Cancel after the operator confirms the JS `confirm()` popup. Prevents double-clicks and contradictory actions during the in-flight window between confirm-OK and page reload. Pure presentation: ~80 lines diff (1 template anchor, ~25 lines CSS, ~30 lines vanilla JS, 3 Playwright cases). | `stripe` | ⬜ Planned | — |

## Legend
- ⬜ Not started
- 🟡 In progress
- ✅ Done
- 🚫 Blocked
- 🧊 Frozen — paused with the plan kept intact; resume only if conditions change

## Summary

Two threads today:

**Thread 1 — partial-capture refund correctness (✅ done).** A
ticket on `daniil.oxiddev.de` reported that the admin Stripe tab
refused to refund a partially-captured order — the form's `max`
input attribute rendered as `-197`, blocking every keystroke.
Report 01 traced the issue to Stripe representing the
auth-released uncaptured remainder as a Refund object that
increments `charge.amount_refunded`, while our two display helpers
treated that field as the customer-refund total. Sprint 103
introduced a single resolver service (`StripeChargeAmountResolver`)
that owns the corrected formula `R_customer = max(0,
amount_refunded − max(0, amount − amount_captured))`, and re-routed
both helpers through it. 19 new tests pin the behaviour for the
screenshot case plus 5 adjacent shapes.

**Thread 2 — admin tab latency (Layer A ✅; Layers B+C 🧊).** Report
02 inventoried ≈10 Stripe API round-trips per Stripe-tab render
and proposed a three-layer fix. Sprint 104 (Layer A) shipped: the
fan-out is now 1 call per render via the expanded-PI canonical
source, memoised Order-extension charge, and dropped `refresh=true`
flags. Layers B (cross-request blob cache) and C (async fragment)
are frozen — Layer A alone delivered the ~80 % wall-clock win, and
the remaining marginal optimisations don't justify their cost
(cross-module schema migration for B; new admin XHR endpoint and
fragment template for C). Sprint 107 covers the in-flight-action
UX gap (double-click / contradictory action between confirm-OK and
reload) with a small CSS+JS busy overlay — much smaller than the
Sprint 106 architecture would have been.

## Test baseline

| Suite | Pre-day baseline | Post-Sprint 103 | Post-Sprint 104 |
|---|---:|---:|---:|
| Unit (Stripe, isolated) | 831 | 850 (+19) | 854 (+4) |
| Pre-commit `--full` total | 998 | 998 | **1002** |
| Assertions (full) | 2318 | 2324 | **2441** |
| Errors | 35 (env-flaky) | 30 (env-flaky) | **0** |
| Failures | 2 (env-flaky) | 0 | **0** |
| Pre-commit status | — | non-commitable (env) | **`ALL CHECKS PASSED — COMMITABLE`** |

The earlier 30 `DoctrineIdempotencyRepositoryTest` integration
errors turned out to be a transient environment issue; the final
post-Sprint-104 run is genuinely clean.

## Pending / follow-ups

- **Sprints 103 + 104 commit batch.** Both implementations are
  green and uncommitted on `b-7.4.x-partial-refund-STRP-131`.
  Batch them as two commits (one per sprint) or one combined
  commit at user discretion.
- **Sprint 107.** Lowest-effort UX win — implementation expected
  ≤ ½ day. No backend touch.
- **103.1 follow-up** — transaction-history badge for the
  auth-release row (label "Authorization release" when
  `amount == amount − amount_captured`). Deferred from Sprint
  103 to keep the seam atomic.
- **CI Playwright workflow.** Today's `b-7.4.x` CI fix did not
  green the Playwright job — it failed at the SSH-deploy step
  because the docker stack on `pay1.oxid.dev` is not running.
  Out-of-band; needs an infra ping.
- **Frozen sprints (105, 106).** Re-evaluate if any operator
  feedback indicates the post-Sprint-104 latency is still
  insufficient, or if a future product requirement reintroduces
  the assumptions (e.g. cold-blob first paint, iframe embedding).

## Artifacts

- Reports: [`reports/01-partial-capture-negative-refund-amount.md`](reports/01-partial-capture-negative-refund-amount.md),
  [`reports/02-stripe-payment-tab-latency.md`](reports/02-stripe-payment-tab-latency.md)
- Done sprints:
  [`done/sprint-103-fix-partial-capture-negative-refund-amount.md`](done/sprint-103-fix-partial-capture-negative-refund-amount.md),
  [`done/sprint-104-layer-a-dedup-stripe-api-calls.md`](done/sprint-104-layer-a-dedup-stripe-api-calls.md),
  [`done/sprint-104-completion-report.md`](done/sprint-104-completion-report.md)
- Frozen sprints:
  [`sprints/sprint-105-layer-b-contract-snapshot-blob-cache.md`](sprints/sprint-105-layer-b-contract-snapshot-blob-cache.md),
  [`sprints/sprint-106-layer-c-async-transaction-history-spinner.md`](sprints/sprint-106-layer-c-async-transaction-history-spinner.md)
- Planned sprints:
  [`sprints/sprint-107-busy-overlay-on-admin-actions.md`](sprints/sprint-107-busy-overlay-on-admin-actions.md)
- CI fix commit: `8854893` on `b-7.4.x`
  ([Development run](https://github.com/OXID-eSales/stripe-wallet/actions/runs/26020934415) ✓)
