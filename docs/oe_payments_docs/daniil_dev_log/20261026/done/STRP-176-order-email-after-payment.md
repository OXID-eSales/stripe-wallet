# Sprint plan: STRP-176 — send the order confirmation email only after the payment is confirmed

**Branch:** `b-7.4.x-order-email-STRP-176` (payment-base and stripe, both cut from `b-7.4.x`)
**Engineering rules:** `../../_engineering_requirements.md`
**Definition of Done (sprint-level):** a Stripe checkout that the customer cancels or abandons on the Stripe page
sends **no** mail (not to the customer, not to the shop owner). A checkout that succeeds sends exactly one customer
mail and one owner mail, on the return leg or the webhook leg (whichever commits first), in the order's language.
Shop payments that don't use payment-base (invoice, COD, prepayment) still mail from `finalizeOrder()`. Every
change lands red → green behind the gate.

**Repos touched:** `payment-base` (S1, S2, S3). `stripe` only gets the e2e spec (S4) and loses nothing,
because it no longer creates orders itself.

## Why this is a payment-base fix (it can't be done in stripe alone)

- Core `Order::finalizeOrder()` ends with `sendOrderByEmail()` (`source/Application/Model/Order.php:557`). It runs
  unless `$blRecalculatingOrder` is true.
- Early order creation goes through **payment-base** `Adapter/OxidShopOrderService::finalizeAndValidateOrder()`
  (`$order->finalizeOrder($basket, $user, false)`, line 182). Stripe stopped binding its own
  `ShopOrderServiceInterface` on 2026-09-01 (`stripe/services.yaml:426`), so Stripe, PayPal and Mollie all share this
  path. The order (and the mail) are created before the redirect to the PSP.
- The moment "payment confirmed" exists once, in payment-base: `ContractCommitmentHandler::commitContractAndDispatch()`
  → `ContractCommittedEvent`. Both legs (return via `CheckoutReturnResponder`, webhook via
  `ContractCommitService::commit()`) go through it, and it fires once per contract (only from `READY_TO_COMMIT`).
- So we suppress the mail where payment-base creates the order and send it where payment-base commits the contract.
  The Stripe `Model/Order` extension stays as it is (it only skips `executePayment()`).

## Out of scope
- **Changing the mail templates or their content.** We send the same core mails (`sendOrderEMailToUser` /
  `sendOrderEmailToOwner`). The only change is when they're sent.
- **A "payment failed / cancelled" mail.** Nobody asked for one.
- **Re-sending mails for orders that are already committed.** No backfill: those customers have their mail.
- **Deleting the dead `Stripe\Adapter\OxidShopOrderService`** (still a service at `stripe/services.yaml:431`, but
  unbound). Leaving it alone keeps this sprint small. Ticket it separately.
- **A per-provider "send mail on capture instead of authorization" option.** We mail on COMMITTED, which is also
  where manual-capture (authorize-only) orders land. Core ties the order mail to the order, not to the capture.
  We'll add an option only when a merchant asks for one.

## Risks & unknowns
- **Resolved by the spike (`../reports/01-STRP-176-spike-order-mail-basket-2026-10-26.md`): we don't rebuild the
  basket.** Rebuilding it from the saved order loses voucher discounts in every case (`calcVoucherDiscount()` resets
  them outside admin, and orders keep no voucher links), re-prices delivery with today's rules, drops discounts, and
  crashes when the language changes mid-process. So the mail is **rendered at finalize** (the customer's own request,
  with the real basket and language, exactly as today), **stored**, and **sent on commit**. That also takes care of
  the earlier voucher-list and webhook-language risks.
- **The stored mail holds PII** (name, address, articles), the same data that's already in `oxorder`. Delete the row
  after a successful send, when the order is deleted, and when not-finished-order cleanup removes the order.
- **Other modules that extend `Email`.** We capture by extending `Email` through `metadata.php` (a chain), not by
  subclassing it. Other modules' `Email` extensions (BCC, attachments) then still shape the captured mail.
  Attachments aren't stored: core order mails have none. If a module adds some, they're lost (logged as a WARNING,
  no code until someone needs it).
- **Two VAT rates and owner-mail language** weren't covered by existing data (no order has `oxartvat2 > 0`). S2's
  integration tests create such an order.
- **Behaviour change for PayPal and Mollie.** They share the creation path, so their mails move to commit time too.
  We want that (the same bug exists there), but it has to be in the release notes and their e2e suites have to run
  (S4).
- **Mail failures.** Today a failed send gives `ORDER_STATE_MAILINGERROR` and the order is still "completed". After
  the commit the payment is already taken, so a failed mail must never undo or block the commit. Log it at ERROR and
  don't throw.

---

## Testing with Mailpit (shared by S1, S2, S4)

The `mailpit` container (`axllent/mailpit`, SMTP `1025`, web UI and API `8025`) is running locally. It was
checked on 2026-10-26: v1.26.1, and it already holds shop mails. PHP sends through
`sendmail_path=/usr/bin/msmtp -t` (`php-sendmail.ini`), and msmtp delivers to Mailpit, so a real `oxNew(Email::class)`
send ends up there. No mailer mock is needed in Integration or e2e tests.

| From | API base |
|------|----------|
| host / Playwright | `http://localhost:8025/api/v1` (UI: http://localhost.local:8025) |
| `php` container (Integration tests) | `http://mailpit:8025/api/v1` |

**What to assert** (values from `oxshops` in `example`):
- Customer mail: `to:<customer email>`, subject `Ihre Bestellung bei OXID eSales` (lang 0) or `Your order at OXID eShop`
  (lang 1). The body contains the order number.
- Owner mail: `to:order@myoxideshop.com`, the same subject, and the body contains the order number.
- No mail: `GET /search?query=to:<customer email>` returns `messages_count: 0`.

**Rules (the inbox is shared):**
- Other suites write to the same Mailpit (g07/g11 fixtures, password mails). **Never** `DELETE /api/v1/messages`
  without IDs. Give every test its own customer address (`strp176-<scenario>-<timestamp>@example.invalid`) and
  query only that address.
- The owner address is the same for every test. Filter owner mails by the order number in the body
  (`query=to:order@myoxideshop.com "<ordernr>"`), not by count.
- URL-encode the query (`curl -G --data-urlencode 'query=to:…'`; in Playwright use `URLSearchParams`).
- Mail is sent asynchronously from the test's point of view, after the webhook. Poll for up to 10 s (every 500 ms)
  before asserting "exactly one". Before asserting "none", wait until the contract state is final (CANCELLED/COMMITTED
  in `oe_payments_contract`), then check once more.
- Webhooks locally: `stripe listen --forward-to http://localhost.local/…/webhook`, as in the existing e2e setup.
  The webhook-only test (S2) disables the return leg by not following the success redirect.

**Helper (one copy each, DRY):**
- `stripe/tests/e2e/helpers/mailpit.ts`: `mailsTo(address)`, `waitForMails(address, n, timeoutMs)`,
  `expectNoMailTo(address)`, `ownerMailFor(orderNr)`.
- `payment-base/tests/Integration/Support/MailpitClient.php`: the same four operations over `http://mailpit:8025`.
  Skip the test with `markTestSkipped` when Mailpit isn't reachable, because CI may not run it.

**Manual check (5 min, before S4 is written):** place an invoice order and a Stripe order on the current
`b-7.4.x`, cancel the Stripe one on the Stripe page, and record what Mailpit shows. You should see the bug: two order
mails for the cancelled Stripe checkout. Do the same on the branch after S2: no mail for the cancelled checkout, two
for the successful one, two for invoice. Put the screenshots in `../done/STRP-176-implemented.md`.

---

## Story 1 — Capture the order mails instead of sending them when payment-base creates the order early

**Why:** a customer who cancels on the Stripe page has already been mailed "thank you for your order".
**Estimate:** M (it was S before the spike: it now includes the capture and the storage)

**Tests first (TDD):**
- `payment-base/tests/Unit/Eshop/Core/EmailCaptureTest.php`
  - `testSend_WhenCapturing_RecordsSubjectBodiesRecipientAndReplyToAndSendsNothing`
  - `testSend_WhenNotCapturing_DelegatesToParent`
  - `testTakeCapturedMails_ReturnsEachMailOnceAndClearsTheBuffer`
- `payment-base/tests/Unit/Eshop/Application/Model/OrderMailDeferralTest.php`
  - `testSendOrderByEmail_WhenDeferred_StoresCustomerAndOwnerMailAndReturnsStateOk`
  - `testSendOrderByEmail_WhenDeferred_SetsVoucherListBeforeRendering` (order mail template "used vouchers" row)
  - `testSendOrderByEmail_WhenNotDeferred_DelegatesToParent` (invoice/COD path unchanged)
  - `testDelete_RemovesStoredMailsOfTheOrder`
- `payment-base/tests/Unit/Repository/DoctrineDeferredOrderMailRepositoryTest.php` (Integration, needs MySQL)
  - `testSave_ThenFindUnsentByOrderId_ReturnsTheMails`, `testClaim_SecondClaimOfSameMail_ReturnsFalse`,
    `testDeleteByOrderId_RemovesAll`
- `payment-base/tests/Unit/Adapter/OxidShopOrderServiceTest.php`
  - `testCreateOrder_DefersConfirmationMailOnTheNewOrder`
  - `testCreateOrder_StillMapsStateOkToCompleted`
- `payment-base/tests/Integration/.../EarlyOrderCreationMailTest.php`
  - `testCreateOrder_ForPaymentBaseCheckout_SendsNoMailAndStoresTwo`: Mailpit has no message `to:` the test
    customer; `oe_payments_order_mail` has a customer row and an owner row
  - `testFinalizeOrder_ForInvoicePayment_StillSendsCustomerAndOwnerMail`: Mailpit has one of each, no stored rows
    (regression guard)
  - `testNotFinishedOrderCleanup_DeletesStoredMails` (an abandoned Stripe checkout leaves no PII behind)

**Implementation steps:**
1. Migration `payment-base/migration/data/Version20261026100000.php`: create `oe_payments_order_mail` with columns
   `OXID`, `OXORDERID` (indexed), `OXSHOPID`, `OXKIND` (`customer` | `owner`), `OXRECIPIENT`, `OXRECIPIENTNAME`,
   `OXREPLYTO`, `OXREPLYTONAME`, `OXFROM`, `OXFROMNAME`, `OXSUBJECT`, `OXBODY` (MEDIUMTEXT), `OXALTBODY`
   (MEDIUMTEXT), `OXCLAIMEDAT` (nullable), `OXTIMESTAMP`.
2. `src/Mail/DeferredOrderMail` (readonly value object) and `src/Repository/DeferredOrderMailRepositoryInterface` +
   `DoctrineDeferredOrderMailRepository`: `save(string $orderId, list<DeferredOrderMail>)`,
   `findUnsentByOrderId()`, `claim(string $mailId): bool` (`UPDATE … SET OXCLAIMEDAT = NOW() WHERE OXID = ? AND
   OXCLAIMEDAT IS NULL`, true only when exactly one row was updated), `delete(string $mailId)`,
   `deleteByOrderId()`.
3. `src/Eshop/Core/Email.php` (`extends Email_parent`, add `oxEmail::class => Email::class` to `metadata.php`):
   `captureInsteadOfSend()`, `takeCapturedMails(): list<DeferredOrderMail>`, and override `send()`. When capturing,
   record the mail, call `clearAllRecipients()`, and return `true`.
4. The payment-base `Order` extension: `deferConfirmationMail()` plus an override of `sendOrderByEmail()`. When the
   mail isn't deferred, call `parent::`. When it is, do what core does (assign `_oUser`/`_oBasket`/`_oPayment`, then
   call `sendOrderEMailToUser` + `sendOrderEMailToOwner`), but on an `oxNew(Email::class)` that is capturing. Save
   the captured mails through the repository, which comes from a protected getter (same seam as
   `getVoucherRelease()`), and return `ORDER_STATE_OK`. A comment explains why core's ten lines are repeated: core
   creates its `Email` inside the method and gives us no way to pass in a capturing one.
5. `OxidShopOrderService::newOrder()` calls `$order->deferConfirmationMail()`.
6. `Order::delete()` (already extended for the voucher release) also calls `deleteByOrderId()`. Check that
   not-finished-order cleanup goes through `delete()`. If it uses raw SQL (`DoctrineNotFinishedOrderRepository`),
   add the delete there.

**SOLID/Clean check:**
- SRP: `Email` captures, the repository stores, the `Order` extension decides when to defer, and the adapter decides
  which orders to defer.
- OCP/LSP: `send()` and `sendOrderByEmail()` keep core's signatures and return types. When not capturing or
  deferring, behaviour is unchanged.
- DIP: the repository is behind an interface. OXID access stays in the model extensions and the adapter.
- DRY: core renders. We copy no template data, and the only repeated code is the three assignments plus two calls.
- No overengineering: no admin UI and no resend command. Attachments aren't stored (see Risks).

**DevOps gate:** payment-base `composer phpcs` ✓ · `composer phpstan` ✓ (no new baseline entries) · `composer phpmd`
✓ · Unit ✓ · Integration ✓ · migration up/down on a fresh DB ✓ · `./bin/pre-commit-check.sh --full` ✓

**Definition of Done:** start a Stripe checkout and cancel it on the Stripe page. Mailpit shows no new mail. When the
abandoned order is cleaned up, its rows in `oe_payments_order_mail` are gone too.

---

## Story 2 — Send the stored order mails once, when the contract is committed

**Why:** after S1, a successful payment would send no mail at all.
**Estimate:** S (it was M before the spike: no basket rebuild and no language switch)

**Tests first (TDD):**
- `payment-base/tests/Unit/EventSystem/Handler/OrderConfirmationMailHandlerTest.php`
  - `testGetHandledEventClass_IsContractCommittedEvent`
  - `testHandle_OnCommit_SendsEveryUnsentMailOfTheOrderAndDeletesIt`
  - `testHandle_WhenClaimFails_SkipsThatMail` (the other leg is sending it)
  - `testHandle_WhenNoStoredMails_DoesNothing` (orders created before this release, and replays)
  - `testHandle_WhenSenderFails_LogsErrorKeepsRowAndDoesNotThrow`
- `payment-base/tests/Unit/Adapter/ShopMailSenderTest.php`
  - `testSend_CopiesFromRecipientReplyToSubjectAndBothBodies`
- `payment-base/tests/Integration/.../OrderConfirmationMailOnCommitTest.php` (asserts through `MailpitClient`)
  - `testCommitViaWebhookLeg_WithoutSession_SendsOneCustomerAndOneOwnerMail`
  - `testCommitViaReturnLeg_ThenWebhook_SendsMailOnce`
  - `testMail_ForVoucherDiscountDeliveryAndTwoVatRates_ShowsTheChargedTotal` (the case the spike couldn't cover)
  - `testMail_ForGermanOrderCommittedByWebhook_HasGermanSubject` (`Ihre Bestellung bei OXID eSales`)

**Implementation steps:**
1. `src/Adapter/ShopMailSender` (interface + OXID implementation). It takes a `DeferredOrderMail`, builds an
   `oxNew(Email::class)`, sets from, recipient, reply-to, subject, body and alt body, and calls `send()`. This is the
   only OXID access on this path.
2. `src/EventSystem/Handler/OrderConfirmationMailHandler` (`ContractCommittedEvent`, modelled on
   `UserBasketRemovalHandler`), for each `findUnsentByOrderId()`: `claim()`, then `send()`. On success,
   `delete()` and log at INFO. On failure, log at ERROR with the order id and mail id, keep the row (for a later
   manual resend, out of scope), and never throw.
3. Register it in `payment-base/services.yaml` with `payment.event_handler`, priority `5` (after
   `UserBasketRemovalHandler`, which has 10).

**SOLID/Clean check:**
- SRP: the handler decides when and how often (claim and error policy). The sender talks to OXID mail. Core
  rendered the content in S1.
- OCP: a new handler on an existing event. No existing handler is edited.
- DIP: the handler depends on `DeferredOrderMailRepositoryInterface`, `ShopMailSenderInterface` and
  `LoggerInterface`.
- Clean: each method ≤ 25 lines, early returns, no `else`.

**DevOps gate:** as S1, plus the Stripe module Integration suite (the webhook leg runs through it).

**Definition of Done:** a test-mode card payment produces one customer mail and one owner mail in Mailpit, with
totals equal to the charged amount, byte-for-byte the mail that `finalizeOrder()` used to send. Replaying the
`payment_intent.succeeded` webhook sends nothing more, and `oe_payments_order_mail` is empty for that order.

---

## Story 3 — Treat a deferred mail as normal in the order-state mapping

**Why:** `mapOrderStateToStatus()` still maps `ORDER_STATE_MAILINGERROR` to `completed` with the comment "Order
created but email failed". After S1 that state can't come from the early-creation path any more, so the comment is
wrong and the code has a branch nothing can reach.
**Estimate:** XS

**Tests first:** `OxidShopOrderServiceTest::testMapOrderState_MailingErrorStillCompleted`. We keep the mapping
for safety, so a core change that starts returning it again stays harmless.

**Implementation:** update the comment to explain why the mapping stays (defensive), and link STRP-176. No logic
change.

**DoD:** the gate is green and the comment is accurate.

---

## Story 4 — E2E proof on the Stripe checkout (and the PayPal/Mollie suites still pass)

**Why:** the bug is user-visible, so the proof has to be too.
**Estimate:** S

**Tests (Playwright, `stripe/tests/e2e`):**
- `order-email-after-payment.spec.ts`
  - `cancel on Stripe Checkout → no order mail in Mailpit`
  - `successful card payment → exactly one customer + one owner mail, subject contains the order number`
  - `3DS declined → no mail`
- Run the PayPal and Mollie e2e suites against the payment-base branch. Any mail assertion that expected the mail
  at "place order" must now expect it after payment approval. Update those assertions and nothing else.

All specs use `helpers/mailpit.ts` and a unique customer address per test (see *Testing with Mailpit*), plus:
  - `webhook only (success redirect not followed) → exactly one customer + one owner mail`
  - `success redirect + webhook replay → still exactly one customer mail after 10 s`

**DevOps gate:** the e2e suites are green locally with the `mailpit` container up and the `stripe listen` forwarder
running. Never wipe the shared inbox.

**Definition of Done:** the screenshots and Mailpit API dumps are attached to `../done/STRP-176-implemented.md`.

---

## Order of work
Spike ✓ (2026-10-26, option C: render at finalize, send on commit) → S1 → S2 → S3 → S4. Merge S1 and S2 together:
S1 on its own would leave successful orders without any mail.
