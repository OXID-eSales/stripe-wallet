# Closing report: STRP-176, order confirmation mail only after the payment is confirmed

**Date:** 2026-10-28 · **Sprint:** [`../done/STRP-176-order-email-after-payment.md`](../done/STRP-176-order-email-after-payment.md)
· **Rules:** [`../../_engineering_requirements.md`](../../_engineering_requirements.md)
**Status:** shipped in payment-base `b-7.4.x` (merge `0da54dc`, pushed). Closed with the open items below.

## The bug and the fix
Core `Order::finalizeOrder()` sends the order mail. payment-base creates the order early, before the redirect to
the PSP, so a customer who cancelled or abandoned the Stripe page (also PayPal and Mollie, which share the path) had
already got "thank you for your order", and so had the shop owner.

Now the mail is **rendered at finalize** (the real basket and language, the same content as before), **stored** in
`oe_payments_order_mail`, and **sent once on `ContractCommittedEvent`** (return leg or webhook leg, whichever commits
first; a guarded `claim()` stops a second send). Orders that payment-base didn't create (invoice, COD, prepayment)
mail from `finalizeOrder()` as before. The spike ([01](01-STRP-176-spike-order-mail-basket-2026-10-26.md)) ruled out
rebuilding the basket at commit time: vouchers were lost and delivery was re-priced.

## Stories and their proof

| Story | Title | Commits (payment-base unless noted) | Proof |
|-------|-------|-------------------------------------|-------|
| Spike | Can the mail be rebuilt from the order? (no, option C) | (scripts) | [01](01-STRP-176-spike-order-mail-basket-2026-10-26.md) |
| S1 | Capture and store the mails at early order creation | `7edcd21`, `860249b`, `04da4c3` | [S1 done](../done/STRP-176-S1-implemented.md) |
| S2 | Send the stored mails once on commit | `1afa4df` | [S2–S4 done](../done/STRP-176-S2-S4-implemented.md) |
| S3 | Order-state mapping comment (no logic change) | `2d1ccc4` | [S2–S4 done](../done/STRP-176-S2-S4-implemented.md) |
| S4 | E2E spec + Mailpit helper | e2e repo `96a7187`, `4a63d25` (branch `STRP-176-order-email-e2e`) | [S2–S4 done](../done/STRP-176-S2-S4-implemented.md) (partly, see open items) |
| Merge | `b-7.4.x-order-email-STRP-176` → `b-7.4.x` | `0da54dc` (pushed; the tree is identical to the tested branch) | `git branch -r --contains 0da54dc` → `origin/b-7.4.x` |

## Test counts

| Suite | Before → after |
|-------|----------------|
| payment-base Unit | 1690 → 1736 (6 skipped as before) |
| payment-base Integration | 173 → 182, no assertion failures (3 environmental errors: `oe_graphql_base-schema` cache-unlink race) |
| New integration classes (real shop, real Mailpit) | migration 4/4, repository 6/6, early creation 4/4, commit 5/5 |
| Stripe Integration | 118 OK (unchanged; the webhook leg runs through it) |
| Static | phpcs ✓ · PHPStan level max ✓ (no new baseline entries) · phpmd ✓ |
| Storefront e2e (`OrderEmailAfterPayment.spec.ts`) | 1 passed (left on the payment step → no mail), 2 skipped (paid, declined) |

Verified in Mailpit via the integration tests: one customer and one owner mail per commit; a replayed commit sends
nothing; a stornoed order sends nothing; an English order committed in a German request gets the English subject;
two VAT rates (7 % / 19 %) show the charged total; an invoice-style order still mails immediately (positive control).

## Deviations from the plan
- Two small interfaces (`DefersConfirmationMailInterface`, `OrderMailCaptureInterface`) for testability and the
  PHPStan LSP rule.
- The not-finished-order cleanup runs SQL storno, not `Order::delete()`, so stored mails are dropped in
  `DoctrineNotFinishedOrderRepository::cancelOrder()` too.
- A deferred order always answers `ORDER_STATE_OK`: `validateOrderState()` refuses everything else, and a mail
  problem must not abort a valid checkout. It is logged at ERROR instead.
- S3's planned test "MailingError still completed" was dropped: it would have asserted a path that can't be reached.
- `OXCLAIMEDAT` was folded into S1's still-unreleased migration instead of a second migration.

## Open items (carried over, not blocking the merge)
1. **E2E branch not merged.** `STRP-176-order-email-e2e` (`96a7187`) is pushed. `projects/Stripe` is still at
   `8a35a77` without it.
2. **The paid and declined e2e specs were never run in a browser.** The shop runs payment-base's iframe mode, and
   the suite can't fill in Stripe's nested card iframe. Run them in redirect mode. Until then the paid path is
   proven by the commit integration tests only.
3. **The PayPal and Mollie e2e suites haven't been run against the change.** Their order mail also moves from "place
   order" to commit, and any mail assertion there has to follow.
4. **No manual Mailpit check with screenshots**, which the sprint asked for (invoice vs. cancelled vs. paid Stripe
   order, before and after).
5. **Release notes:** the behaviour change for PayPal and Mollie has to go in. The payment-base `CHANGELOG.md` has
   an Unreleased entry.
6. **Test-harness leak:** other integration classes call `createOrder()` on a container connection that the test
   transaction doesn't roll back, which leaves orphan `oe_payments_order_mail` rows (16 after the last full run,
   deleted). The fix belongs in the shared harness.
7. **Out of scope by plan, ticket separately:** delete the dead `Stripe\Adapter\OxidShopOrderService`. Add a resend
   path for mails whose send failed (the row is kept and released for that).

## Lessons
- `shop-run.sh` dropped and re-created `oe_payments_order_mail` with 80 rows in it, without checking. Those rows all
  belonged to orders that no longer existed, so no data was lost. A script that drops a table must stop on a
  non-zero row count.
