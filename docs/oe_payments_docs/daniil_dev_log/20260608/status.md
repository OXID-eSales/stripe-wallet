# Dev log — 2026-06-08

## Planned sprints

| # | Title | Bug | Status |
|---|---|---|---|
| 122 | [Order-now button stays disabled after returning from external payment page](sprints/sprint-122-order-button-stuck-disabled-after-return.md) | bfcache restore on `cl=order` doesn't re-run Stimulus `connect()`; button frozen `disabled` | planned |
| 123 | [T&C checkbox can be unchecked after clicking Order now](sprints/sprint-123-agb-checkbox-lock-after-order-submit.md) | `#checkAgbTop` not locked during in-flight submit | planned |

**Order:** 122 first (introduces the `pageshow` restore path), then 123 (hooks checkbox unlock into it).

## Shared surface
Both bugs live on the standard checkout order page and touch the same two source controllers:
- `resources/build/js/controllers/order_submit_controller.js`
- `resources/build/js/controllers/agb_validation_controller.js`

Test vehicle: **Playwright E2E** (no JS unit harness). Templates:
`tests/checkout/coupon-survives-back-navigation.spec.ts` (bfcache) and
`tests/checkout/stripe-agb-confirmation.spec.ts` (AGB).

## Earlier today
- Resolved prod activation failure: payment-base path-repo was a stale git checkout (missing `Validation/` subtree). See memory `feedback_path_repo_provider_skew`.
