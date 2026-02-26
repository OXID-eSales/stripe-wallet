# 2026-02-26 Status

## Fixed
- [BUG] Order totals show 0.00 after refund → FIXED (Option A)
  - Report: `reports/01-refund-zeroed-order-totals-bug.md`
  - Root cause: `OxidStockRestorationService::restoreStockForOrder()` marks all articles as storno then calls `recalculateOrder()`, which rebuilds totals from empty basket
  - Fix: Removed `$order->recalculateOrder()` call from `OxidStockRestorationService.php`
  - Verified: 714 unit tests pass, manual confirmation by user

- [BUG] "Can only add refunded amount in FULFILLED state" error on admin refund → FIXED
  - Report: `reports/02-refund-contract-state-error.md`
  - Root cause: `StripeRefundRequestHandler::updateContractState()` calls `addRefundedAmount()` without checking contract state; throws DomainException if not FULFILLED, but Stripe refund already succeeded
  - Fix: Added state guard — skip refund recording gracefully when contract not in FULFILLED state
  - Verified: 715 unit tests pass (+1 new test for the guard)
