# Sprint 137 — implemented 2026-10-20 (uncommitted, in working trees)

| Story | Repo / files | Proof |
|---|---|---|
| S1 webhook records captured amount | stripe: `WebhookContractFulfillmentHandler(+Interface)`, `PaymentIntentSucceededWebhookHandler` (`amountReceivedOf()`), `CheckoutSessionCompletedWebhookHandler` (`amount_total`) | red 7 → green; audit/handler/checkout tests |
| S2 backfill | stripe: `ContractCaptureRecorder` (extracted, 2 callers), `CapturedAmountBackfillService(+Interface)`, `Result/BackfillReport`, `Command/BackfillCapturedAmountCommand`, DTO `amountReceived` (mapped in `StripeObjectMapper`), `services.yaml` | unit + integration (candidate query on test DB); local dry run: 6 candidates |
| S3 deploy + schema spec | stripe: `.github/workflows/playwright-ci.yml`; submodule `tests/e2e/playwright`: `tests/e2e/GraphQL/graphql-schema.spec.ts` | spec red on pay1 (2/3), green locally after cache clear (3/3); actionlint OK |
| S4 saved lists | oxid-fe-core `src/account/savedLists.ts` + spec; hydroxid `tests/e2e/helpers/mockShop.ts` | fe-core 147/147, lint, type-check; hydroxid 57/57, tsc; live query on local shop OK |
| extra: `issuedAt` field name | payment-base `src/GraphQL/DataType/SealedClientConfig.php` + `tests/Unit/GraphQL/DataType/SealedClientConfigTest.php` | GraphQLite published `issuedAt()` as `suedAt`; red 2 → green; payment-base Unit 1603 OK, phpcs/phpstan/phpmd OK |

## Gates (stripe)
phpcs ✓ · phpstan ✓ · phpmd ✓ · standalone unit 1687 ✓ · Docker Integration 103 ✓.
Pre-existing, not caused by this sprint: Docker `--testsuite Unit` aborts at discovery
(`Mollie\Controller\PaymentController_parent` not found, shop module chain), and `pre-commit-check.sh --full`
reports PHPMD TooManyMethods on the untouched `StripeOrderController.php`.

## Deviations
- The DTO mapping lives in `StripeObjectMapper` (the adapter only delegates).
- The backfill uses `StripeAdapterFactoryInterface` lazily (same as `OxpaidReconciliationService`).
- Added `CapturedAmountBackfillServiceInterface` so the command can be tested with a mock.
- The recorder is a required constructor dependency of the handler.
- Local and pay1 consoles differ: `bin/oe-console` locally, `vendor/bin/oe-console` on pay1 (the workflow targets pay1).

## For Daniil's pay1 deploy
1. Deploy (workflow or by hand). It includes the payment-base `issuedAt` fix.
2. `docker compose exec -T -w /var/www php vendor/bin/oe-console oe:cache:clear && … oe:graphql:cache-clear`
3. `SHOP_URL=https://pay1.oxid.dev/ npx playwright test --project=chromium tests/e2e/GraphQL/graphql-schema.spec.ts` → 3/3
4. `vendor/bin/oe-console stripe:backfill-captured-amount` (dry run, expect ~87), then `--apply`.
