# Sprint plan: Sprint 137 — make the pay1.oxid.dev log errors go away at their source

**Source report:** `../reports/01-pay1-log-errors-2026-10-20.md`
**Definition of Done (sprint-level):** on pay1, `paymentClientConfig` is in the live schema; a Stripe payment
records its captured amount and exactly one capture audit row; the 87 historical gaps are backfilled; the
storefront no longer produces `Basket "…" not found` ERRORs. Every change lands red → green behind the gate.

**Repos touched:** `stripe` (S1, S2, S3), `oxid-fe-core` (S4). No payment-base interface change.

## Out of scope
- **Error #4 (`Source::__construct()` null body, graphql-base).** The bug is upstream (`RequestReader` passes
  `query => null` through). Action: file an issue/PR to OXID graphql-base with a reproduction
  (`curl -X POST /graphql/` with an empty JSON body → 500). No local workaround: an nginx 400 rule only half-covers it,
  and 2 hits/day don't justify a shim.
- Apache access logging on pay1 (ops hygiene, not a code fix).
- Lowering the level at which graphql-base logs `NotFound` errors. S4 removes the cause instead.
- Moving the "captured amount" write into payment-base's `ContractFulfillmentService` for PayPal/Mollie. That would
  change a payment-base interface. Revisit when a second provider shows the same gap ("third caller" rule).
- The 202 contracts stuck in COMMITTED on pay1. Seen while sizing #3; it's a separate investigation.

## Risks & unknowns
- **Duplicate capture audit rows on manual capture.** `CaptureService::recordCaptureTransaction()` already writes a
  `cap_…` row when an admin captures, and Stripe then sends `payment_intent.succeeded` for the same intent.
  S1 tests this case explicitly. The webhook writes the row only if the contract has no `capture` transaction yet.
- **`Customer.baskets` field shape.** S4 assumes `baskets { id title public items {…} }` exists on `Customer` in the
  installed graphql-storefront (`Customer/Service/RelationService::getBaskets`). Spike (10 min): introspect
  `__type(name:"Basket")` on pay1 before writing the S4 tests.
- **pay1 deploy.** Daniil deploys pay1 personally (decided 2026-10-20). The workflow fix in S3 is delivered as
  code; the one-off cache clear and the run against pay1 are Daniil's manual steps, not something the implementer runs.
- **Backfill against live Stripe.** S2 reads PaymentIntents with the test-mode key on pay1. It runs dry by default
  and needs an explicit `--apply`.

---

## Story 1 — Record the captured amount when `payment_intent.succeeded` fulfils a contract

**Why:** 87 of 141 FULFILLED contracts on pay1 have `OXCAPTUREDAMOUNT IS NULL`. Nothing on the webhook path calls
`setCapturedAmount()`, so the capture audit row is skipped (53 log ERRORs) and refund bounds see "nothing captured".
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerAuditTest.php`
  - `testHandlePaymentSucceeded_WithAmountReceived_SetsCapturedAmountAndWritesOneCaptureRow`
  - `testHandlePaymentSucceeded_WhenCaptureRowAlreadyExists_SetsCapturedAmountWithoutSecondRow` (manual capture)
  - `testHandlePaymentSucceeded_WhenContractAlreadyHasCapturedAmount_KeepsItAndWritesNoRow`
  - `testHandlePaymentSucceeded_WithZeroOrMissingAmount_LogsErrorAndWritesNoRow` (keeps the Sprint 133 F9 guarantee:
    no fictitious 0.00 row)
  - `testHandlePaymentSucceeded_WhenFulfillmentReturnsFalse_ChangesNothing` (webhook replay is a no-op)
- `tests/Unit/Stripe/Webhook/Handler/PaymentIntentSucceededWebhookHandlerTest.php`
  - `testHandle_PassesAmountReceivedInCurrencyUnitsToFulfillmentHandler` (1999 EUR cents → 19.99)
  - `testHandle_FallsBackToAmountWhenAmountReceivedIsAbsent`
- `tests/Unit/Stripe/Webhook/Handler/CheckoutSessionCompletedWebhookHandlerTest.php`
  - `testHandle_PassesAmountTotalToFulfillmentHandler`
- Adjust `testHandlePaymentSucceededDoesNotWriteAZeroAuditRowWhenTheAmountIsUnknown` to the new signature.
  Its expectation must not change.

**Implementation steps:**
1. Widen `WebhookContractFulfillmentHandlerInterface::handlePaymentSucceeded(string $providerOrderId, ?float $amountReceived = null): ?bool`.
   The default keeps the signature additive; update the three call sites to pass the amount.
2. In `PaymentIntentSucceededWebhookHandler`, extract the existing inline
   `extractAmountInCurrencyUnits($event, 'amount_received') ?? …('amount')` into one private `amountReceivedOf(WebhookEvent)`.
   Use it for both `commitOpenContract()` and `handlePaymentSucceeded()` (DRY, one present duplicate).
3. In `WebhookContractFulfillmentHandler`, replace the capture branch of `handlePaymentSucceeded()` with a private
   `recordCapture(PaymentContractInterface $contract, ?float $amountReceived): void`. Early returns only: amount known?
   → contract already has an amount? → set + save → capture row already exists? → `recordAudit()`.
4. Check "capture row exists" with `TransactionRepositoryInterface::findByContractId()` filtered on
   `StripeDefinitions::TRANSACTION_TYPE_CAPTURE`. Reuse the existing repository method; no new query.
5. Keep the `Capture audit row skipped …` error log only for the "no amount from Stripe" branch.

**SOLID/Clean check:**
- SRP: the handler still maps webhook outcomes onto the contract. `recordCapture()` is one private step, not a new
  class: there's one caller (S2 is the second caller and extracts it then — see S2).
- DIP: depends on `ContractRepositoryInterface` and `TransactionRepositoryInterface` (payment-base). No new dependencies.
- LSP: interface widened with an optional trailing parameter. The only implementation and the test doubles keep
  `?bool` semantics (true/false/null unchanged).
- ISP: interface stays at its current method count; no new method.
- DRY: reuses `extractAmountInCurrencyUnits`, `recordAudit`, `findByContractId`, `StripeDefinitions` constants.
- Clean: `handlePaymentSucceeded()` ≤ 20 lines after the extraction. No `else` (CLAUDE.md §Code Style "No else
  expressions"); explicit `use` imports.

**DevOps gate:**
- `composer phpcs` ✓
- `composer phpstan` ✓ (level max, no new suppressions; CLAUDE.md "Never suppress static analysis")
- `composer phpmd` ✓
- `vendor/bin/phpunit -c tests/phpunit-unit.xml` ✓ (standalone, as CI runs it)
- `docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration` ✓ (webhook path)
- `./bin/pre-commit-check.sh --full` ✓ before commit

**Definition of Done:**
- A test-mode payment on pay1 ends FULFILLED with `OXCAPTUREDAMOUNT` = the paid total, one `capture` row in
  `oe_payments_transaction`, and no `Capture audit row skipped` line. An admin manual capture still yields exactly one row.

---

## Story 2 — `stripe:backfill-captured-amount` for contracts fulfilled before Story 1

**Why:** Story 1 fixes new payments only. 87 existing contracts stay without a captured amount and audit row, and that
blocks correct refund bounds on those orders.
**Estimate:** M

**Tests first (TDD):**
- `tests/Unit/Stripe/Adapter/StripeAdapterTest.php`
  - `testRetrievePaymentIntent_MapsAmountReceived`
- `tests/Unit/Stripe/Service/ContractCaptureRecorderTest.php` (moves the S1 behaviour tests here when extracted — see step 2)
  - `testRecord_SetsAmountAndWritesRow`, `testRecord_WhenRowExists_WritesNoSecondRow`, `testRecord_WhenAmountKnown_IsNoOp`
- `tests/Unit/Stripe/Service/CapturedAmountBackfillServiceTest.php`
  - `testBackfill_InDryRun_ReportsCandidatesAndWritesNothing`
  - `testBackfill_WithApply_RecordsAmountReceivedFromPaymentIntent`
  - `testBackfill_WhenPaymentIntentNotSucceeded_SkipsAndReports`
  - `testBackfill_WhenStripeCallFails_ContinuesWithNextAndReportsFailure`
- `tests/Unit/Stripe/Command/BackfillCapturedAmountCommandTest.php`
  - `testExecute_DefaultsToDryRun`, `testExecute_WithApplyFlag_Applies`, `testExecute_PrintsSummaryCounts`

**Implementation steps:**
1. Add `public int $amountReceived = 0` as the trailing parameter of `StripePaymentIntentDto` (additive widening,
   same pattern as `amountCapturable`) and map it in `StripeAdapter::retrievePaymentIntent()`.
2. **Extract** S1's `recordCapture()` into `Service/ContractCaptureRecorder` (now two callers: webhook handler and
   backfill). Inject it into `WebhookContractFulfillmentHandler`; S1's tests stay green unchanged.
3. Candidate lookup: follow the `OxpaidReconciliationService::findUnpaidOrders()` pattern — a DBAL query in the
   Stripe service for `OXSTATE='fulfilled' AND OXCAPTUREDAMOUNT IS NULL AND OXPROVIDER='stripe'`, scoped to the shop id.
   No payment-base repository method (no second consumer).
4. `CapturedAmountBackfillService::backfill(bool $apply): BackfillReport` — per candidate:
   `retrievePaymentIntent()` → status `succeeded` → `AmountConverter::toMajorUnits()` → `ContractCaptureRecorder::record()`.
5. `Command/BackfillCapturedAmountCommand` (`stripe:backfill-captured-amount [--apply]`), registered in `services.yaml`
   next to `stripe:reconcile-oxpaid`.

**SOLID/Clean check:**
- SRP: `ContractCaptureRecorder` sets the captured amount and writes at most one capture row.
  `CapturedAmountBackfillService` selects candidates and asks Stripe. The command handles I/O and flags only.
- DIP: the service depends on `StripePaymentIntentAdapterInterface`, `ContractRepositoryInterface` and the recorder,
  not on `StripeAdapter`.
- LSP: n/a (no OXID virtual parent).
- ISP: no new interface. The recorder is a `final` concrete class with one public method; add an interface only if a
  test can't use the real class (CLAUDE.md: final classes are tested with real instances and mocked dependencies).
- DRY: reuses `AmountConverter`, `retrievePaymentIntent`, the reconcile command's structure and the S1 logic (extracted,
  not copied).
- No overengineering: no batching/paging flags. 87 rows fit one run; add `--limit` only if a real shop needs it.

**DevOps gate:**
- `composer phpcs` ✓ · `composer phpstan` ✓ · `composer phpmd` ✓
- Unit testsuite ✓ · Integration testsuite ✓ (DBAL candidate query against the test DB)
- `./bin/pre-commit-check.sh --full` ✓

**Definition of Done:**
- On pay1, `stripe:backfill-captured-amount` (dry run) lists 87 candidates. `--apply` leaves
  `SELECT COUNT(*) … WHERE OXSTATE='fulfilled' AND OXCAPTUREDAMOUNT IS NULL` at 0 (or only reported failures), with one
  capture row per backfilled contract.

---

## Story 3 — Fix the pay1 deploy so code updates also rebuild the container and schema

**Why:** root cause of error #2. pay1's `Makefile` `php:` target ignores `CMD` (it runs `docker compose exec php bash`),
and the workflow calls `bin/oe-console`, which doesn't exist in the container (`vendor/bin/oe-console` does). The
`playwright-ci.yml` deploy job therefore never reinstalled modules or cleared caches. The 19 Oct manual update left
a container compiled at 12:32 with no `PaymentClientConfig`.
**Estimate:** S

**Tests first (TDD):**
- `tests/e2e/playwright/playwright/tests/e2e/graphql-schema.spec.ts` (API-only spec, runs in the existing `playwright`
  job right after `deploy`):
  - `Query exposes paymentClientConfig` — introspects `__type(name:"Query")`. **Red against pay1 today.**
  - `Mutation exposes stripeCheckoutStart, stripeCheckoutReturn, stripeCheckoutCancel`
  - `paymentClientConfig answers a sealed envelope for provider stripe` (anonymous token, fresh P-256 key)

**Implementation steps:**
1. In `.github/workflows/playwright-ci.yml` `deploy`, replace `make php CMD="…"` with
   `docker compose exec -T php <cmd>` and `bin/oe-console` with `vendor/bin/oe-console`.
2. Add `oe:module:install vendor/oxid-esales/payment-base` before the stripe install (`--with-all-dependencies` updates it too).
3. Finish the deploy with `oe:cache:clear` and `oe:graphql:cache-clear`, in that order (container first, then the
   schema built from it).
4. Hand-over for Daniil's deploy (manual, by Daniil): on pay1 run
   `docker compose exec -T php vendor/bin/oe-console oe:cache:clear` and
   `docker compose exec -T php vendor/bin/oe-console oe:graphql:cache-clear`, then run the new spec with
   `BASE_URL=https://pay1.oxid.dev` → green.

**SOLID/Clean check:**
- SRP: the deploy job deploys; the new spec asserts the deployed schema. No logic in YAML beyond commands.
- DRY: the commands live only in `playwright-ci.yml`; `security-pentest.yml` only seeds users and isn't touched.
  If a second workflow ever deploys, extract a `bin/deploy-shop.sh` then, not now.
- No overengineering: no rollback, no health-check loop. The Playwright job is the gate.

**DevOps gate:**
- `actionlint .github/workflows/playwright-ci.yml` ✓
- New spec green locally against the SDK shop (`npx playwright test tests/e2e/graphql-schema.spec.ts`) ✓
- After Daniil's deploy of pay1: `graphql-schema.spec.ts` ✓ against `https://pay1.oxid.dev`

**Definition of Done:**
- The workflow fix is merged; after Daniil deploys pay1 the schema spec passes, and there's no new
  `Cannot query field "paymentClientConfig"` in `oxideshop.log`.

---

## Story 4 — oxid-fe-core: read saved lists through `customer.baskets` instead of a throwing lookup

**Why:** `savedLists.get()` asks for `customer.basket(title)`, which throws `BasketForUserNotFound` for every customer
without that list. The frontend handles it, but graphql-base logs each one as ERROR (102 today), which buries real errors.
**Estimate:** S

**Tests first (TDD)** — `oxid-fe-core/tests/unit/savedLists.spec.ts` (vitest):
- `reads a list from customer.baskets by its reserved title` (replaces "reads a list by its reserved title")
- `answers null when no basket carries the title — without a GraphQL error` (replaces both "answers null…" and
  "treats the shop's 'not found' answer as no list yet")
- `rethrows any GraphQL error` (the regex catch is gone)
- `adds to an existing list without creating one` / `creates the list on the first add` — unchanged expectations,
  new response fixture
- `counts the entries of a list (0 without a list)` — unchanged expectation

**Implementation steps:**
1. Spike (see Risks): confirm `Customer.baskets` fields on pay1 via introspection.
2. Replace the `GET` document with `query savedLists { customer { baskets { id title public items { … } } } }`.
   `get(kind)` = `baskets.find(b => b.title === kind) ?? null`.
3. Delete the `try/catch` and the `/not found/i` regex. Nothing expected can throw any more, so string-matching
   error messages goes too.
4. Update `hydroxid-project/tests/e2e/helpers/mockShop.ts` so the `savedList` mock answers `baskets: []` instead of the
   "not found" failure, then rebuild hydroxid (it depends on oxid-fe-core `#main`).

**SOLID/Clean check:**
- SRP: `SavedListsApi` unchanged — same public interface, so callers in oxid-fe-user don't change (LSP towards
  consumers: `get()` still returns `SavedList | null`).
- DRY: one query serves both `noticelist` and `wishlist`. Don't add a cache yet; the existing `GraphqlClient`
  response cache already covers repeat reads.
- Clean: removes an exception-as-control-flow path and a message regex.

**DevOps gate:**
- `npm run lint` ✓ · `npm run typecheck` (or `tsc --noEmit`) ✓ · `npm test` ✓ in oxid-fe-core
- hydroxid `npm test` + its e2e suite ✓ after the mock change

**Definition of Done:**
- Opening the account area on pay1 as a customer without a gift registry produces no `Basket "wishlist" not found`
  line in `oxideshop.log`.

---

## Suggested order
1. **Story 3** — restores headless payment config on pay1 today and is the smallest change. It also gives
   S1/S2 a working deploy path to verify on pay1.
2. **Story 1** — stops new audit gaps. It has to land before the backfill, or the backfill runs behind a moving target.
3. **Story 2** — repairs the 87 historical contracts (second caller, so the recorder gets extracted here).
4. **Story 4** — log-noise removal; independent of the others and can run in parallel in oxid-fe-core.
5. Out-of-scope follow-up: file the graphql-base upstream issue for error #4 (½ h, no code here).
