# OPC-175 · S2b — One order, one contract (enforced) — done 2026-10-29

**Commit:** payment-base `2fa1706` on `b-7.4.x-coupon-reaplying-OPC-175` (not pushed).

## What changed
- Migration `Version20261029100000`: `oe_payments_contract.OXORDERID` is unique (`UNQ_ORDER` replaces `IDX_ORDER`). NULL (no order yet) stays allowed. When an order has more than one contract, the migration stops and names the order and the contract ids. It does not choose a row.
- `PaymentContract::commitToOrder()` refuses an order id other than the one linked at `transitionToNotFinished()`. No method changes the order of a contract.
- `DoctrineContractRepositoryOpenByBasketTest` left contracts on the fixed order ids `order-a`…`order-d` on each run (its transaction does not cover the repository's connection; 135 rows since 2026-10-06). It now uses its own ids and removes its rows (plain `TestCase`).
- `ContractCaptureRefundTest` committed to a second order id; fixed. `PaymentContractsMigrationTest` expects `UNQ_ORDER`.

## Red → green
- Unit `PaymentContractTest::testCommitToOrder_WithAnotherOrderId_IsRefused` red → green.
- Integration `OneOrderOneContractMigrationTest` (6): unique index replaces the old one; a second contract for the same order is refused by the DB; many contracts without an order are allowed; up on a migrated shop plans nothing; up with duplicates stops and names them; down restores `IDX_ORDER`.

## Local DB (shared dev shop)
- Before the migration: backup of the affected rows in the job tmp (`opc175-duplicate-contracts-backup.sql`). Deleted: 135 test rows on `order-a`…`order-d`; the older of the two cancelled Mollie contracts of order `43f7616558396a16507272872d7d5106` (`1e3ffdd6…`, test data of 2026-09-03). Then `vendor/bin/oe-payment-base-migrate`: 1 migration executed.

## Gates
- payment-base `./bin/pre-commit-check.sh`: all checks passed (unit 1951).
- Integration: payment-base suite — only the known GraphQL schema-cache unlink races (they move between runs) and two flaky tests that pass on re-run and with the change stashed. Mollie unit 956, PayPal unit 844, Stripe commit/capture tests (unit + 3 integration) green.
