# Sprint plan: STRP-176 follow-up — keep the deferred order mail on the contract, no own table

**Branch:** `b-7.4.x-order-mail-on-contract-STRP-176` (payment-base, cut from `b-7.4.x`; stripe only for docs/e2e)
**Engineering rules:** `../../_engineering_requirements.md`
**Previous sprint:** `../../20261026/done/STRP-176-order-email-after-payment.md` · closing report
`../../20261026/reports/02-STRP-176-closing-report-2026-10-28.md`
**Definition of Done (sprint-level):** `oe_payments_order_mail` is gone. The order mail is still rendered at
finalize, kept on the order's `oe_payments_contract` row, and sent once on commit. The contract row also records
whether and when the mail was sent. The behaviour customers see is unchanged from v1.5.0: no mail for a cancelled or
abandoned checkout, exactly one customer and one owner mail for a paid one, invoice/COD/prepayment mail at once.
Mails that were still waiting in the old table at upgrade time are not lost.

## Decision (from Daniil, 2026-10-28)
No separate table for the order mails. The contract row stores the mail's sent status.

**What that means here.** The status alone isn't enough: the spike
(`../../20261026/reports/01-STRP-176-spike-order-mail-basket-2026-10-26.md`) showed the mail can't be rendered
correctly at commit time (vouchers lost, delivery re-priced, language switch crash). So we keep "render at finalize,
send on commit" and move the **rendered mails** onto the contract row next to the status. They're cleared after the
send, so the row keeps only the status and the timestamp. *If the intent was "status only, render at commit", stop
here. That reopens the spike's findings and needs a different plan.*

## What changes on `oe_payments_contract`

| Column | Type | Meaning |
|--------|------|---------|
| `OXORDERMAILSTATE` | `VARCHAR(16) NULL` | `NULL` = nothing deferred (contracts before 1.5.0, or an order mailed at once) · `pending` · `sending` (claimed by one leg) · `sent` · `failed` · `dropped` (order cancelled/deleted before payment) |
| `OXORDERMAILS` | `LONGTEXT NULL` | JSON list of the captured mails (kind, from, recipients, reply-to, cc, bcc, subject, body, altBody). `NULL` once sent or dropped |
| `OXORDERMAILSENTAT` | `DATETIME NULL` | when the last mail went out |

- **Encoding:** the table is latin1. `json_encode()` with default flags (no `JSON_UNESCAPED_UNICODE`) writes `€`, umlauts
  and CJK as `\uXXXX`, so the stored text is plain ASCII and round-trips byte for byte. A test pins this; one
  `JSON_UNESCAPED_UNICODE` in the future would silently corrupt the mails.
- **Not part of the `PaymentContract` entity.** `DoctrineContractRepository::prepareContractData()` writes an explicit
  column list, so a contract `save()` never touches these three columns and the optimistic lock (`OXVERSION`) isn't
  bumped by mail bookkeeping. They're read and written only through a small store (S2). That keeps PII out of the
  entity, its `toArray()`, the admin GraphQL `AdminPaymentContract`, and the logs.
- **Index:** none new. Every access is by `OXID` (contract) or `OXORDERID` (already `IDX_ORDER`).

## Out of scope
- Changing the mail content or the moment it's sent (still `ContractCommittedEvent`).
- A resend command or admin UI for `failed` mails. We record the status so one can be built later.
- Showing the mail status in the admin Payment tab or Help popup (a one-line follow-up once the column exists).
- Deleting the dead `Stripe\Adapter\OxidShopOrderService` (still ticketed separately).

## Risks & unknowns
- **Does the contract row exist when `finalizeOrder()` runs?** The mails are written by an `UPDATE … WHERE OXID =
  :contractId`. The headless path saves the contract before it dispatches `ContractDraftCompletedEvent`
  (`ContractOpeningService:69`). The session path (`ContractConditionResolverHandler`), Stripe's
  `StripePaymentHandler::createOrder()` and PayPal's `PayPalOrderCreationHandler` aren't verified yet. **S2 starts with
  an integration test per path.** If a path has no row yet, the store reports it and the order mails go out
  immediately with a WARNING (the v1.4 behaviour, never a lost mail). Then we fix that path to save first, or stash
  the mails after the save in `EarlyOrderCreationHandler`.
- **v1.5.0 is released with `oe_payments_order_mail`.** The new migration has to move the waiting rows before it
  drops the table (S1). The local `example` DB has 156 rows today, mostly test residue for orders that no longer
  exist; only rows whose order and contract still exist are moved.
- **Public API removed.** `DeferredOrderMailRepositoryInterface`, `StoredOrderMail` and the Doctrine repository are
  `@since 1.5.0`. Removing them is a minor-version change for an internal seam (no provider module uses them, checked
  with grep in S4). The release is **1.6.0**: bump the composer `branch-alias` in the tag commit (see the release
  rule from 2026-10-09).
- **One status for two mails.** The customer and owner mail share one claim. If the customer mail goes out and the
  owner mail fails, the row becomes `failed` and keeps only the owner mail. A later resend can never mail the
  customer twice.

---

## Story 1 — Migration: mail columns on the contract, move waiting mails, drop the table

**Why:** the storage moves, and nothing waiting in a 1.5.0 shop may be lost.
**Estimate:** S

**Tests first:**
- `tests/Integration/Migrations/OrderMailOnContractMigrationTest.php`
  - `testUp_AddsTheThreeColumnsAsNullable`
  - `testUp_MovesUnclaimedMailsOfAnExistingContractAndSetsPending`
  - `testUp_MovesClaimedButUnsentMailsAsFailed` (a claim left set means a send died halfway; we don't know whether it went out)
  - `testUp_SkipsMailsOfOrdersWithoutContractOrOrder` (test residue), and counts them in the migration output
  - `testUp_DropsTheOldTable`
  - `testUp_StoresEuroUmlautAndCjkAsAsciiJsonThatRoundTrips`
  - `testDown_RecreatesTheEmptyTableAndDropsTheColumns`
- Adjust `DeferredOrderMailMigrationTest` (1.5.0): it now only checks that the old migration still runs in sequence.

**Implementation:**
1. `migration/data/Version20261028100000.php`: ENUM type mapping first (as `Version20261026100000`). Add the three
   columns, then (in PHP, not `INSERT … SELECT`, because the JSON shape changes) read the old rows grouped by `OXORDERID`, find the contract by
   `OXORDERID`, write `OXORDERMAILS` (ASCII JSON, same shape as the S2 store) and `OXORDERMAILSTATE`, and drop the
   table. Plain SQL and the connection only, no module classes (migrations must run without the module container).
2. `down()`: drop the columns and recreate the empty 1.5.0 table. Waiting mails are lost on a downgrade; say so in
   the description and the CHANGELOG.
3. Leave `Version20261026100000` in place (it's released). Its `up()` stays idempotent.

**DevOps gate:** phpcs ✓ · phpstan ✓ · phpmd ✓ · migration up → down → up on a copy of `example` ✓
**DoD:** on a copy of the local DB, the waiting mails of existing contracts show up on their contract row, and the old
table is gone.

---

## Story 2 — Capture into memory, store on the contract row

**Why:** the `Order` extension writes to its own table today. It should only capture. Storing on the contract is the
order service's job, because only that service knows the contract id.
**Estimate:** M

**Tests first:**
- `tests/Unit/Repository/DoctrineContractOrderMailStoreTest.php` (+ Integration twin against MySQL)
  - `testStash_WritesMailsAndPending_OnlyWhenTheRowExists` (returns false for a missing row)
  - `testClaim_PendingToSending_ReturnsTheMails_SecondClaimReturnsNull`
  - `testClaim_IgnoresStornoedOrder`
  - `testMarkSent_ClearsMailsSetsSentAndTimestamp`
  - `testMarkFailed_KeepsOnlyTheUnsentMails`
  - `testDropByOrderId_ClearsPendingAndFailed_LeavesSentAlone`
- `OrderMailDeferralTest` (existing, adapted): `testSendOrderByEmail_WhenDeferred_KeepsBothMailsInMemoryAndReturnsOk`,
  `testTakeDeferredMails_ReturnsThemOnce`, `testSendOrderByEmail_WhenNotDeferred_DelegatesToParent`.
- `OxidShopOrderServiceTest`:
  - `testCreateOrder_StashesDeferredMailsOnTheContractFromMetadata`
  - `testCreateOrder_WithoutContractIdOrRow_SendsTheMailsNowAndWarns`
  - `testCreateOrder_WhenStashThrows_SendsTheMailsNowAndLogsError` (never block the checkout, never lose the mail)
- **Path check (integration, first):** `EarlyOrderCreationMailOnContractTest` for the session path, the headless path,
  Stripe `StripePaymentHandler` and PayPal `PayPalOrderCreationHandler` (the last two in their module's integration
  suite). Each asserts: the contract row exists at stash time, state is `pending`, Mailpit has no mail.

**Implementation:**
1. `src/Repository/ContractOrderMailStoreInterface` + `DoctrineContractOrderMailStore`: `stash(contractId, mails):
   bool`, `claim(contractId): ?list<DeferredOrderMail>` (`UPDATE … SET OXORDERMAILSTATE='sending' WHERE OXID=? AND
   OXORDERMAILSTATE='pending'` joined to a not-stornoed `oxorder`; read the mails only when exactly one row changed),
   `markSent(contractId)`, `markFailed(contractId, list $unsent)`, `dropByOrderId(orderId)`. JSON codec as a private
   pair of methods: no `unserialize`, only scalars and lists.
2. `Order` extension: `sendOrderByEmail()` captures as today but keeps the mails in a private list (no repository).
   New `takeDeferredMails(): list<DeferredOrderMail>` on `DefersConfirmationMailInterface`.
3. `OxidShopOrderService::finalizeAndValidateOrder()`: after `finalizeOrder()`, take the mails and stash them on
   `metadata['contract_id']`. If there's no id, no row, or an exception: send them now through
   `ShopMailSenderInterface` and log (WARNING / ERROR). The store and the sender are optional constructor arguments
   (same pattern as `attemptGuard`), so older `services.yaml` overrides still build.
4. `Order::delete()`, `Order::cancelOrder()` and `DoctrineNotFinishedOrderRepository::cancelOrder()` call
   `dropByOrderId()` instead of the old repository (best-effort, logged, as today).

**DevOps gate:** phpcs · phpstan (level max, no new baseline entries) · phpmd · Unit · Integration (payment-base +
Stripe + PayPal suites)
**DoD:** a Stripe checkout cancelled on the Stripe page: Mailpit empty, its contract row shows `pending` → `dropped`
after the cleanup, `OXORDERMAILS` is `NULL`.

---

## Story 3 — Send on commit from the contract row and record the result

**Why:** the handler reads the old table.
**Estimate:** S

**Tests first:** `OrderConfirmationMailHandlerTest` (rewritten):
- `testHandle_OnCommit_ClaimsSendsBothAndMarksSent`
- `testHandle_WhenClaimReturnsNull_DoesNothing` (other leg, replay, nothing deferred, stornoed order)
- `testHandle_WhenOwnerMailFails_MarksFailedWithOnlyTheOwnerMail_DoesNotThrow`
- `testHandle_WhenStoreThrows_LogsErrorAndDoesNotThrow`
- `OrderConfirmationMailOnCommitTest` (Integration, Mailpit): the existing five cases (one customer + owner mail,
  second commit sends nothing, stornoed sends nothing, English order in a German request, two VAT rates), plus
  `testAfterSend_ContractShowsSentWithTimestampAndNoMailContent`.

**Implementation:** `OrderConfirmationMailHandler` depends on `ContractOrderMailStoreInterface`, `ShopMailSenderInterface`
and `LoggerInterface`. It uses `$event->getContractId()`, so no order lookup is needed. Priority stays 5.
`OxidShopMailSender` stays unchanged (it takes the shop id; the store returns it with the mails).

**DevOps gate:** as S2.
**DoD:** a test-mode card payment gives one customer mail and one owner mail. The contract row shows `sent`, the
time it was sent, and `OXORDERMAILS = NULL`. A replayed webhook changes nothing.

---

## Story 4 — Remove the table code, docs, release notes, and the e2e carry-overs

**Why:** dead code, plus the items the closing report left open.
**Estimate:** S

**Steps:**
1. Delete `DeferredOrderMailRepositoryInterface`, `DoctrineDeferredOrderMailRepository`, `StoredOrderMail`, their
   service definitions, `DeferredOrderMailFixture` and the tests of the old repository. `grep` the stripe, paypal and
   mollie modules for these names first; they must have no use.
2. CHANGELOG `[Unreleased]` → 1.6.0: "Changed: deferred order mails live on `oe_payments_contract`, with
   `OXORDERMAILSTATE` and `OXORDERMAILSENTAT`. Removed: `oe_payments_order_mail` and its repository (migration moves
   waiting mails). Downgrade loses waiting mails." Add the PayPal/Mollie behaviour note from 1.5.0, which is still
   missing.
3. The test-harness leak goes away with the table: no stash happens without a contract row, and the contract rows
   roll back with the test. The integration suite has to prove that (0 rows outside the test data afterwards).
4. Carry-overs from the closing report: run `OrderEmailAfterPayment.spec.ts` paid + declined **in redirect mode**,
   run the PayPal and Mollie e2e suites, merge `STRP-176-order-email-e2e` into `projects/Stripe`, take the manual
   Mailpit screenshots. Add a `select OXORDERMAILSTATE` check to the e2e helper, which is now possible without the
   mail table.

**DoD:** `grep -r oe_payments_order_mail src tests` is empty (outside the two migrations and their tests). All three
e2e suites are green, and the screenshots are in `../done/`.

---

## Order of work
S1 → S2 → S3 → S4, merged together. S1 drops the table that the 1.5.0 code reads, so the migration can't ship
without S2 and S3. Then tag payment-base 1.6.0 with the branch-alias bump.
