# STRP-176 S2–S4: implemented and verified against the shared shop

**Sprint:** `../done/STRP-176-order-email-after-payment.md` · S1: `STRP-176-S1-implemented.md`
**payment-base** `b-7.4.x-order-email-STRP-176` (pushed): `2d1ccc4` (S3), `1afa4df` (S2)
**e2e-tests-playwright** `STRP-176-order-email-e2e` (pushed, from `projects/Stripe`): `96a7187`, `4a63d25` (S4)
**Worktrees:** `payment-base/.claude/worktrees/STRP-176`, `stripe/.claude/worktrees/STRP-176-e2e` (its
`playwright/node_modules` is a symlink to the submodule checkout's, untracked)

## S2: send the stored mails once the contract commits
- `EventSystem/Handler/OrderConfirmationMailHandler` on `ContractCommittedEvent`, priority 5 (after the basket
  removal). For each unsent mail of the order: `claim()` → send → `delete()`. If the claim fails, the other leg holds
  it, so skip. If the send fails or throws, `release()` + ERROR log; the handler never throws into the commit.
- `Adapter/ShopMailSenderInterface` + `OxidShopMailSender`: core `Email` (so other modules' Email extensions, inline
  images and MTA settings apply), with the **SMTP settings of the order's shop**. Core's `Email::getShop($lang,
  $shopId)` always loads the active shop, so the sender loads the shop row itself.
- Repository: `findUnsentByOrderId()` joins `oxorder` and only returns mails of an existing, **not stornoed** order
  (a late payment after the cleanup cancelled the order sends nothing). Also `claim()` (guarded UPDATE on
  `OXCLAIMEDAT`), `release()`, `delete()`. `Mail/StoredOrderMail` (id, shopId, mail).
- `OXCLAIMEDAT` went into S1's migration (`Version20261026100000`, still unreleased), not a second migration. The
  local `example` table (empty) has to be re-created during the shop run.

## Fix to S1, found while writing S3
`OxidShopOrderService::validateOrderState()` refuses **every** state except OK. So S1's "storing the mail failed →
`ORDER_STATE_MAILINGERROR`" would have aborted a valid checkout over a mail the customer only gets after paying. The
deferred path now always answers OK and logs the problem at ERROR (`[STRP-176] … it will not be sent`).

## S3
The `MAILINGERROR → completed` row in `mapOrderStateToStatus()` can't be reached from `createOrder()`; its comment now
says so and why. No logic change. The planned test "MailingError still completed" would have asserted something
false, so it was dropped.

## S4
`playwright/tests/e2e/StripeStandard/OrderEmailAfterPayment.spec.ts` + `playwright/Helpers/Mailpit.ts`:
- paid order: exactly one customer mail and one owner mail for its order number, still one after a 15 s late-webhook
  window;
- checkout left on the payment step (the early order exists): no order mail since the test began;
- declined card: no order mail.

The suite buys as one shared `TEST_USER`, so mails are matched by order number or by creation time, not by a unique
address. `MAILPIT_URL` defaults to `http://localhost:8025`.

## Verified (in the worktrees, without the shop)
- payment-base Unit **1736 green** (6 skipped as before); phpcs ✓; PHPStan level max ✓ (no new baseline entries; run
  directly, since composer's 300 s timeout was hit on a cold cache); phpmd ✓.
- New integration tests lint; spec type-checks (`tsc --strict`) and Playwright lists all 3 tests.

## Shop run (14:16–14:25 local, after the other session's checkout had been idle 20 min)
Done with `shop-run.sh` / `shop-restore.sh`: record the checkout's branch, back up `var/configuration`, detach
payment-base at `1afa4df`, `oe:module:install` (Email extension in the chain), re-create `oe_payments_order_mail` via
the migration wrapper (now with `OXCLAIMEDAT`), run, restore.

**Results**
- New integration classes against the real shop: migration 4/4, repository 6/6, early creation 4/4,
  **commit 5/5**. Through the real dispatcher into Mailpit, verified: one customer and one owner mail per commit;
  a second commit sends nothing more; a stornoed order sends nothing; an English order committed in a German request
  gets the English subject; two VAT rates (7 % / 19 %) and the charged total in the mail.
- Full payment-base integration suite: 182 tests, **no assertion failures**. The 3 errors are again the
  `oe_graphql_base-schema` cache-unlink race in `CachingTrait` (environmental, different tests each run).
- Stripe module integration: **118 OK** (6 deprecations, 1 skipped).
- **Real storefront (Playwright, through the Cloudflare tunnel `daniil.oxiddev.de` → this shop):**
  - `stripe-checkout.spec.ts` (existing, iframe-aware): 2 passed. The early orders it left behind: #999933035 was
    cancelled by the next attempt's cleaner and its stored mails were dropped (0 rows); #999933036 is NOT_FINISHED,
    contract pending, 2 stored mails, **not sent**.
  - New `OrderEmailAfterPayment.spec.ts`: "left on the payment step" **passed** (order #999933037 created with
    2 stored, unsent mails; nothing in Mailpit). "Paid" and "declined" are **skipped**: the shop runs payment-base's
    iframe mode (mount on consent), the card form sits in Stripe's nested iframe, and this suite can't drive it (the
    existing spec doesn't get through it either). They run in redirect mode. **The paid path is therefore proven by
    the commit integration tests, not by a browser payment.**
- Restored: payment-base back on `feature/admin-payment-tab` (`77525de`), config identical to the backup, cache
  cleared, storefront 200. Left in `example`: the re-created table with the 4 stored mails of the two abandoned e2e
  orders above (pending contracts, test user).

**My mistake in the run: 80 rows dropped without looking.** `shop-run.sh` re-creates the table for the new column. It
printed "80 rows" and dropped it anyway: I had assumed the table was empty and didn't stop on a non-zero count.
Afterwards I checked: no `oxorder` row exists from the window in which the shop ran S1 (09:15–09:50 UTC; table
timestamps are UTC, the shell is CET), so all 80 belonged to orders that no longer exist. They were residue of my
earlier full-suite runs, and no live data was lost. A script that drops a table must check before it drops, not just
print.

**Test-suite leak (for whoever runs the full suite):** other test classes call `createOrder()` while the harness's
container connection is not the one the test transaction rolls back (see S1 notes). Their mail rows commit even
though the orders roll back: 16 orphan rows after this run, all for nonexistent orders, deleted. My own classes clean
up after themselves (`DeferredOrderMailFixture`), the rest of the suite doesn't. Fixing that belongs in the shared
harness (or a suite-wide teardown), not in this sprint.
