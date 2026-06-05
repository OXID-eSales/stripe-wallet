# Sprint 121 — Completion Report — Admin Amount Validation + Remaining Free-Text Fields

**Date completed:** 2026-06-05
**Branch:** `b-7.4.x-STRP-129-admin-amounts` (off the Sprint 120 result)
**Sprint plan:** [`../sprints/sprint-121-strp-129-admin-amount-validation.md`](../sprints/sprint-121-strp-129-admin-amount-validation.md)
**Repos touched:** `extensions/stripe` ONLY

## Commits (one per phase, as planned)

| Commit | Phase | Content |
|---|---|---|
| `aa473bb` | A | `AdminAmountValidator` + `AmountValidationResult` VO (34 data-provider tests) |
| `0a360ba` | B | `AdminActionBounds` seam + reason-whitelist pins + by-charge whitelist fix |
| `7d6d937` | C | Amount gates in `handleCapture`/`handleRefund`; `parseAmount()` deleted; `refundDescription` gate |
| `256f4d6` | D | Per-code amount messages + `refundDescription` rules entry + en/de admin-lang keys |
| `2a235ea` | E | Non-positive amount guards in `CaptureService` + `RefundService` (convergence points) |

## What shipped — the footgun is dead

Before: `StripePaymentPanelProvider::parseAmount()` turned ANY malformed
amount (`12,30 EUR`, `1.234,50`, `abc`) into `null`, and `null` means **full
capture / full refund**. After: amounts run through `AdminAmountValidator`
pre-dispatch —

- absent → `ok(null)` = full action (unchanged semantics, no PSP bound lookup wasted)
- malformed → failure (`amountMalformed`) — **never null**
- `<= 0` → `amountNotPositive`
- currency-aware precision via `AmountConverter::decimalsFor` (JPY=0) → `amountPrecision`
- over the PI/charge-derived bound (minor-units comparison, no IEEE-754 drift) → `amountExceedsBound`
- PSP unreachable during bound resolution → **fail closed** (`amountBoundUnavailable`)

The PARSED float travels to the dispatcher (parse once). `refund_description`
(POST-reachable free text into Stripe metadata; not in the panel form —
`PaymentAdminController::collectActionRequest()` forwards `$_POST` wholesale)
gets the same char-level gate as `captureReason`. Amount + text failures from
one POST are stored together and rendered as per-code translated messages in
the Sprint 120 alert block.

## Bonus fix surfaced by the Phase B pins

`RefundService::processRefundByCharge()` (the chargeId-carrying event path)
**bypassed `validateReason()`** and passed the raw string to Stripe's refund
`reason` enum param. Now applies the same whitelist as `processRefund`.
Single production caller (`StripeRefundRequestHandler`); both broker paths
send enum values — behavior-preserving for legitimate flows.

## Already-safe items pinned, not built (plan §1.3)

- `refund_reason` select → `RefundService::VALID_REASONS` whitelist (pinned on both paths)
- `cancel_reason` select → `PaymentIntentHelper` match-default to
  `requested_by_customer` (pinned in new `PaymentIntentHelperCancelReasonTest`)

## Test counts (Unit suite, measured)

| | Tests | Assertions |
|---|---:|---:|
| After Sprint 120 | 1180 | 2842 |
| After Sprint 121 | **1243** | **3028** |
| Δ | **+63** | **+186** |

Integration suite standalone: 87 OK. Module deactivate/activate cycle clean
after all wiring changes.

## Quality gates

PHPCS clean · PHPStan level max 0 errors · PHPMD baseline unchanged
(3 entries) · zero new suppressions (one `@phpstan-ignore` for an OXID magic
property read, matching the documented core-pattern exception).

## Deviations from plan

1. **§4.4 numeric interpolation dropped from amount messages** — interpolating
   the bound would require storing or re-fetching PSP amounts at render time;
   the panel already displays capturable/refundable figures next to the forms.
   Messages are static per code.
2. **Currency read via magic-wrapper property, not `getFieldData()`** —
   `getFieldData()` triggers a PHP warning on partially loaded models
   (`BaseModel.php:1260`); the wrapper read matches the builder's existing
   `readField()` pattern.
3. **`StripePanelViewDataBuilder` gained a `LanguageTranslatorInterface` arg**
   for the per-code amount keys (plan implied formatter-only routing).

## Behaviour changes to be aware of (documented per plan §7/§8)

- **C8 fail-closed:** if the PI/charge bound cannot be resolved, a PARTIAL
  amount action is rejected (previously it would have sailed through for
  Stripe to judge). Full (absent-amount) actions are unaffected — they skip
  the bound lookup entirely.
- **Malformed amounts now error instead of silently full-capturing** — that
  is the fix, pinned forever by C1/A4.

## Pre-existing issue (NOT from this sprint)

`ModuleLifecycleTest` combined-suite errors reproduce on clean `b-7.4.x`
(4 errors base vs 3 on these branches — strictly fewer). See the Sprint 120
report for details.

## Open items / follow-ups

1. Manual browser verification (en + de) of both sprints' alerts on the admin
   Payment tab — caches cleared, php restarted, ready to test.
2. Stripe per-currency minimum pre-flight (driver report G5) — unplanned.
3. Playwright admin specs for both sprints — CI runs Playwright manual-only.
4. PayPal panel adoption of the same gates — pattern documented, out of scope.
