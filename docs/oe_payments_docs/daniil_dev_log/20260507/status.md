# 2026-05-07 — Status

## Test baseline (as of Sprint 101 completion)

- Unit: **821 tests**, 1976 assertions
- Integration: **157 tests**, 417 assertions (53 skipped, pre-existing DB-dependency)
- PHPCS: 0 errors | PHPStan level 6 src/: 0 new errors | PHPMD: 0 new (4 baselined)
- Branch: `b-7.4.x`

## Closed today

- [Sprint 101](done/sprint-101-agb-confirmation-enforcement-on-stripe-order.md) — DONE.
  AGB confirmation gate in `createCheckoutSession()` + Stimulus frontend wiring.
  14 new tests green. See [completion report](done/sprint-101-completion-report.md).

## Closed reports

- [01](reports/01-stripe-order-button-bypasses-agb-confirmation.md) — RESOLVED by Sprint 101.
