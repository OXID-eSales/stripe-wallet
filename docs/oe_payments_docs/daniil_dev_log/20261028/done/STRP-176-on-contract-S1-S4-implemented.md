# STRP-176 follow-up S1–S4: the deferred order mail waits on the contract row

**Sprint:** `../sprints/STRP-176-order-mail-on-contract.md` · **Repo:** payment-base
**Branch:** `b-7.4.x-order-mail-on-contract-STRP-176` (pushed, cut from `b-7.4.x` `7d4be67`): `cd13e62` (S1),
`cf0cfe6` (S2–S4). Not merged.
Worked in the main payment-base checkout (no worktree), which the shop runs.

## What changed

| Story | Files | Change |
|-------|-------|--------|
| S1 | `migration/data/Version20261028100000.php` | `OXORDERMAILSTATE`, `OXORDERMAILS` (ASCII JSON), `OXORDERMAILSENTAT` on `oe_payments_contract`. Moves waiting 1.5.0 rows to the contract of their order (claimed-but-unsent → `failed`), drops `oe_payments_order_mail`. Raw SQL in `up()` because Doctrine runs `addSql` before the schema diff. `down()` recreates the empty 1.5.0 table. |
| S2 | `Repository/ContractOrderMailStoreInterface` + `DoctrineContractOrderMailStore`, `Mail/ClaimedOrderMails` | `stash`, `claim` (guarded `pending → sending`, joined to a not-stornoed `oxorder`), `markSent`, `markFailed`, `dropByOrderId`. Kept outside the `PaymentContract` entity (no PII in `toArray()`/admin API, no `OXVERSION` bump). |
| S2 | `Eshop/Application/Model/Order.php`, `Mail/DefersConfirmationMailInterface` | The order only captures into memory; `takeDeferredMails()` hands the mails over once. `delete()`/`cancelOrder()` → `dropByOrderId()`. |
| S2 | `Adapter/OxidShopOrderService.php` | After `finalizeOrder()`, it stashes the mails on `metadata.contract_id`. No id, no row, no store, or an exception → sends at once through `ShopMailSenderInterface` and logs it. New optional constructor arguments `$orderMails` and `$mailSender`. |
| S2 | `Repository/DoctrineNotFinishedOrderRepository.php` | The cleanup storno drops the waiting mails through the store. |
| S3 | `EventSystem/Handler/OrderConfirmationMailHandler.php` | Claims by contract id, sends, records `sent` (+ time, content cleared) or `failed` (only the unsent mails kept). Never throws. |
| S4 | removed `DeferredOrderMailRepositoryInterface`, `DoctrineDeferredOrderMailRepository`, `StoredOrderMail` + their tests; `services.yaml`; `CHANGELOG.md` `[Unreleased]`; PHPStan allow-list | No provider module (stripe, paypal, mollie) used them (grep). |

## Facts checked before building
- **The contract row exists before `createOrder()` on every path:** `ContractService::createContract()` saves it (session,
  Stripe, PayPal, Mollie); the headless `ContractOpeningService` saves it before dispatching. All three shop callers of
  `createOrder()` (payment-base `EarlyOrderCreationHandler`, Stripe `StripePaymentHandler`, PayPal
  `PayPalOrderCreationHandler`) pass `metadata.contract_id`.
- `DoctrineContractRepository::prepareContractData()` writes an explicit column list, so a contract `save()` never
  overwrites the mail columns.
- `oe_payments_order_mail` was released in **v1.5.0** (tag contains `0da54dc`), so a moving migration was required.

## Verified
- **Unit:** 1868 tests green (6 skipped as before), was 1862. New: migration conversion (5), store (9), service
  stash/fallback (5), handler (rewritten, 7), order deferral (adapted).
- phpcs ✓ · PHPStan (level max, no new baseline entries) ✓ · phpmd ✓
- **Migration on the shared `example` DB** (backup first, in the job's tmp dir: `strp176-backup-*/before-migration.sql`,
  8 MB): "8 of 156 mails moved to their contract". The 148 others were test residue for orders or contracts that
  no longer exist. Result: 4 contracts `pending`, JSON ASCII only, old table gone.
- **up → down → up** on a scratch copy (`strp176_copy`, dropped afterwards): up moved 4; down recreated the empty table
  and dropped the columns; up ran clean again.
- **Integration, real shop + Mailpit:** the order-mail classes 28/28. Early creation keeps both mails `pending` with `€`
  intact, nothing in Mailpit. No contract → mail at once. Cancel/delete → `dropped`, content cleared. Commit → one
  customer and one owner mail, `sent` + timestamp, content cleared. Second commit → nothing more. Stornoed → nothing,
  still `pending`. Language and two VAT rates as before.
- **Full payment-base integration suite: 213 tests, 0 failures, 0 errors** (1 skipped). The cache-unlink errors of
  10-26 didn't show up.
- **Stripe integration: 118 OK** (6 deprecations, 1 skipped, as before).
- Test-harness leak from 10-26 is gone: no `strp176-` contracts and no stray mail content left after the full run.
  Other tests that call `createOrderFor()` without a contract now mail at once into Mailpit instead of leaving rows.
- Found while running it: a fixture user id over 32 characters (`e2e_su_strp176_nocontract_…`) breaks EE's
  `Order::setUser()` (`__clone` on non-object). Fixed by a shorter suffix.

## Not done (S4 carry-overs, need the storefront and a decision on the shop mode)
- Paid / declined `OrderEmailAfterPayment.spec.ts` in **redirect mode**: the shop runs iframe mode; switching it changes
  the shared shop for everyone.
- PayPal and Mollie e2e suites against this branch.
- Merging `STRP-176-order-email-e2e` into `projects/Stripe`: needs your go-ahead.
- Manual Mailpit screenshots.
- CI: green on OXID 7.4, 7.5, 7.6 (7.6 needed two re-runs: corrupt zip, then "Connection refused" from GitHub downloads; no test failed) and the secret scan.
- Release: tag payment-base **1.6.0** from `b-7.4.x` after the merge, with the branch-alias bump in the same commit.

## Shop state left behind
payment-base main checkout is on `b-7.4.x-order-mail-on-contract-STRP-176` (it was on `b-7.4.x`). `example` is migrated
to `Version20261028100000`, so 1.5.0 code would no longer find its table. Restore if needed: check out `b-7.4.x`, run
the migration down (`oe-eshop-db_migrate migrations:migrate oe_payment_base <prev>`), or load the backup.
