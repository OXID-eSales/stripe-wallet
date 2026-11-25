# Legacy order migration

**Command:** `stripe:migrate-legacy-orders` (STRP-181, since v3.5.0-rc7)
**Source module:** `oxid-esales/stripe-module`, module id `stripe`, namespace `OxidSolutionCatalysts\Stripe`
**Merchant guide:** [Moving from the old Stripe module](../for_merchant/03-migrating-from-the-legacy-stripe-module.md)

---

## Table of Contents

1. [The problem](#the-problem)
2. [What the old module leaves behind](#what-the-old-module-leaves-behind)
3. [What the Payment tab needs](#what-the-payment-tab-needs)
4. [How the command works](#how-the-command-works)
5. [Data mapping](#data-mapping)
6. [What is not migrated](#what-is-not-migrated)
7. [Known gap: refunds made before the migration](#known-gap-refunds-made-before-the-migration)
8. [Running it locally](#running-it-locally)
9. [Tests](#tests)

---

## The problem

The old module kept everything on the order: one PaymentIntent id in `oxorder.OXTRANSID`, a handful of `STRIPE*`
columns, and an API request log. This module works contract-first: payment-base's `oe_payments_contract` row is the
source of truth, and the admin **Payment** tab (payment-base's `PaymentAdminController`) only shows a provider panel
for an order that has a contract. An order placed by the old module has none, so after an upgrade the tab shows
"no provider" for every historic Stripe order.

The command gives each such order a contract, built from the order and from what Stripe reports for its PaymentIntent.

---

## What the old module leaves behind

Paths are relative to the old module's root.

| Data | Where it is written | Content |
|---|---|---|
| `oxorder.OXTRANSID` | `extend/Application/Model/Order.php` `stripeSetTransactionId()` | PaymentIntent id `pi_…` |
| `oxorder.OXPAYMENTTYPE` | `Application/Helper/Payment.php` | `stripecreditcard`, `stripeideal`, `stripesofort`, `stripeeps`, `stripegiropay`, `stripep24`, `stripebancontact` |
| `oxorder.OXPAID` | `Order.php` | set when the PI is `succeeded` and `amount_received` equals the total |
| `oxorder.STRIPEMODE` | `Order.php` `_setPayment()` | `test` / `live` at payment time |
| `oxorder.STRIPE*REFUNDED`, `oxorderarticles.STRIPEAMOUNTREFUNDED` | `Application/Controller/Admin/OrderRefund.php` | "fully refunded" markers, no partial history |
| `oxorder.STRIPEEXTERNALTRANSID` | `TransactionHandler/Base.php` | PayPal-via-Stripe reference; effectively unused |
| `oxuser.STRIPECUSTOMERID` | `Application/Helper/User.php` | `cus_…` |
| `striperequestlog` | `Application/Model/RequestLog.php` | JSON of API requests and responses; `ORDERID` is often empty |
| PaymentIntent metadata | `Application/Model/Request/Base.php` | `order_id = oxorder.OXID`, `store_id` |

Facts that shape the migration:

- **PaymentIntents API only, `capture_method=automatic`.** There is no authorize-only flow, so a paid legacy order is
  always fully captured. The PI status of a paid order is `succeeded`.
- **API access through a Stripe Connect OAuth token** (`sStripeTestToken` / `sStripeLiveToken`). The PaymentIntents
  belong to the connected account. This module's settings use the same names, so the same account means the same
  values.
- **Refunds by charge id** (`latest_charge`). The `re_…` ids are stored only inside `striperequestlog`.

---

## What the Payment tab needs

| Need | Read by |
|---|---|
| `oe_payments_contract.OXORDERID = oxorder.OXID` | `payment-base` `PaymentAdminController::buildPanelContext()` → `ContractRepository::findByOrderId()` |
| `contract.OXPROVIDER = 'stripe'` | `StripePaymentPanelProvider::supports()`: payment id in `StripeDefinitions` **or** provider `stripe`. So `OXPAYMENTTYPE` can stay legacy. |
| `contract.OXPROVIDERORDERID = pi_…` | `StripeMoneyGateway::paymentIntentOf()` (requires the `pi_` prefix); webhooks find the contract by it |
| `oxorder.OXTRANSID = pi_…` | `StripeOrderApiService` (the panel reads PI, charge and refunds live from Stripe) |
| state `fulfilled`, `OXCAPTUREDAMOUNT`, `OXREFUNDEDAMOUNT` | payment-base `RefundPolicy` (refundable while captured − refunded > 0), `CapturePolicy` |

The contract stores **neither the Stripe account nor the mode**. Every call uses the current module settings, so the
module must be connected to the account and mode that took the payment.

---

## How the command works

```
MigrateLegacyOrdersCommand            src/Stripe/Command/
  └─ LegacyOrderMigrationService      src/Stripe/Service/   candidates, contract building
       ├─ LegacyOrderAssessment       src/Stripe/Service/   per-order verdict (shop data + Stripe)
       │    └─ StripeAdapter::retrievePaymentIntent(pi, ['latest_charge'])
       │    └─ ChargeAmountResolver::customerRefundedAmount()
       └─ ContractRepositoryInterface::save()               payment-base
```

### 1. Candidates

```sql
SELECT … FROM oxorder o
LEFT JOIN oe_payments_contract c ON c.OXORDERID = o.OXID
WHERE o.OXPAYMENTTYPE LIKE 'stripe%' AND o.OXSHOPID = :shopId AND c.OXID IS NULL
ORDER BY o.OXORDERDATE
```

- **Idempotent:** an order with a contract is never a candidate again. Re-running only picks up what was skipped.
- **Per shop:** the shop the console is bound to (`--shop-id`).
- This module's ids start with `oe_payments_stripe_` and never match. Test fixtures with `OXPAYMENTTYPE='stripe_card'`
  do match; they have no `OXTRANSID` and are skipped.

### 2. Assessment (`LegacyOrderAssessment::assess()`)

Shop checks first, in this order; the first that applies is the skip reason:

| Check | Reason |
|---|---|
| `OXTRANSID` empty | `no transaction id on the order` |
| `OXTRANSID` not `pi_…` | `transaction id '…' is not a PaymentIntent - the new module cannot manage it` |
| `OXSTORNO = 1` | `order is cancelled (storno)` |
| `OXPAID` empty / zero date | `order was never paid - nothing for the new module to manage` |
| `OXTOTALORDERSUM <= 0` | `order total is zero - nothing to capture or refund` |

Then Stripe:

| Stripe answer | Result |
|---|---|
| `resource_missing` | skip: `the configured Stripe account has no PaymentIntent '…' - another Stripe account or mode took this payment` |
| any other API error | the exception propagates; the order is listed under **Failed**, the command exits 1 |
| status ≠ `succeeded` | skip: `Stripe reports the PaymentIntent as '…', not paid` |
| `succeeded` | migrate with `captured = amount_received`, `refunded = customerRefundedAmount(latest_charge)` |

The **dry run calls Stripe too**: its list is exactly what `--apply` will do.

### 3. Building the contract (`LegacyOrderMigrationService::buildContract()`)

The contract goes through the regular domain lifecycle, so all invariants of `PaymentContract` hold:

```php
$contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
$contract->transitionToNotFinished($orderId);
$contract->transitionToPending();
$contract->setProvider(StripeDefinitions::PROVIDER, $paymentIntentId);
$contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['source' => 'legacy-order-migration']);
$contract->commitToOrder($orderId);
$contract->fulfill();

$contract->setCapturedAmount($capturedAmount);   // from Stripe, major units
$contract->setCapturedAt($paidAt);               // oxorder.OXPAID
$contract->addRefundedAmount($refundedAmount);   // only when > 0
$contract->setRefundedAt($paidAt);               // Stripe's charge has no single refund date
```

No event is dispatched for the walk. Nothing in the shop reacts as if the order were new: no mail, no stock change.

---

## Data mapping

| `oe_payments_contract` | Value |
|---|---|
| `OXSHOPID` | current shop |
| `OXUSERID` | `oxorder.OXUSERID` |
| `OXORDERID` | `oxorder.OXID` |
| `OXSTATE` | `fulfilled` |
| `OXPROVIDER` | `stripe` |
| `OXPROVIDERORDERID` | `oxorder.OXTRANSID` (`pi_…`) |
| `OXBASKETDATA` | `totalGross = OXTOTALORDERSUM`, `totalNet = OXTOTALNETSUM`, `totalVat = gross − net`, `currency = OXCURRENCY`, items from `oxorderarticles` (artnum, title, amount, brutto price, VAT), no discounts |
| `OXCAPTUREDAMOUNT` / `OXCAPTUREDAT` | PI `amount_received` (major units) / `OXPAID` |
| `OXREFUNDEDAMOUNT` / `OXREFUNDEDAT` | charge refunded amount, if > 0 / `OXPAID` |
| `OXMETADATA` | `{"order_number": "<OXORDERNR>", "legacyMigration": {"migratedFrom": "oxid-esales/stripe-module", "legacyPaymentType": "<OXPAYMENTTYPE>"}}` |

`oxorder` is **not changed**.

To find migrated contracts:

```sql
SELECT * FROM oe_payments_contract WHERE OXMETADATA LIKE '%"legacyMigration"%';
```

---

## What is not migrated

| Legacy data | Why not |
|---|---|
| `oe_payments_transaction` rows | the panel builds history live from Stripe; see the gap below for refunds |
| `oxorder.STRIPE*` columns, `oxorderarticles.STRIPE*` | left in place as history. The panel still reads `oxorder.STRIPEEXTERNALTRANSID` when the column exists. |
| `striperequestlog`, `stripepaymentconfig`, `stripecronjob` | old module internals; keep them as an archive or drop them after the upgrade |
| `oxuser.STRIPECUSTOMERID` → `oe_payments_customer` | not needed to manage orders; saved cards of the old module are not offered at checkout |
| Old module settings | `sStripeMode`, `sStripeTestToken`, … have the same names here but live in the other module's configuration; connect the account again in this module |
| Legacy `oxpayments` rows | deactivate, do not delete (orders reference them) |

---

## Known gap: refunds made before the migration

The migration sets `OXREFUNDEDAMOUNT` but writes **no `refund` rows** to `oe_payments_transaction`.

A later refund on the same charge, from the panel or the Dashboard, triggers `charge.refunded`.
`ChargeRefundedWebhookHandler` hands **every** refund of the charge to
`WebhookContractFulfillmentHandler::handleChargeRefunded()`, which books each one through payment-base's
`ProviderRefundLedger::book()`. The ledger finds duplicates only by an existing `refund` row with the same `re_…` id
(`alreadyBooked()`), so the refunds from before the migration are booked again:

- refunded total doubled, if it stays ≤ captured;
- otherwise `CaptureRefundTracker` refuses the booking (refunded > captured), and the webhook fails and is redelivered.

Not affected: orders without a refund before the migration, and orders refunded in full before it (nothing is left to
refund, so no further refund event comes).

**Fix (open):** in `bookMoney()`, book each refund already on the charge as a `refund` transaction row keyed by its
`re_…` id (through `ProviderRefundLedgerInterface`, or by writing the rows directly in the same unit of work as the
contract), instead of only `addRefundedAmount()`. Optionally add the `capture` row keyed by the PI id, as checkout does
through `ProviderCaptureLedger`, so the audit log of a migrated order looks like a new one.

Test to write first: a charge with one partial refund `re_A` → migrate → replay `charge.refunded` with `re_A` + `re_B` →
expected: one new booking (`re_B`), refunded total = A + B.

---

## Running it locally

From an OXID 7.4+ dev shop that has orders of the old module:

1. `oe:module:deactivate stripe`, `oe:module:uninstall stripe`, `composer remove oxid-esales/stripe-module`. The two
   modules conflict: `stripe/stripe-php ^13` vs `^19.3`.
2. Install this module and payment-base. With payment-base from a local path repository, alias it to the version this
   module requires (see `composer.json`), e.g. `"oxid-esales/payment-base": "dev-b-7.4.x as v1.8.0-rc3"`.
3. Copy the old module's `sStripeMode`, `sStripeTestToken`, `sStripeTestPk`, `sStripeTestKey` into this module's
   settings (`var/configuration/shops/<id>/modules/oe_payments_stripe_wallet.yaml`, `moduleSettings`), or connect the
   same account in the admin.
4. `oe:module:activate oe_payments_stripe_wallet`, `oe-eshop-db_migrate migrations:migrate`, `oe:cache:clear`.
5. `vendor/bin/oe-console stripe:migrate-legacy-orders`, then `--apply`.

Verified on 2026-11-25 (OXID 7.1 EE, old module `b-7.0.x` v2.1.1-rc.1). An order paid by card (116.50 EUR,
`stripecreditcard`) was migrated to a `fulfilled` contract and picked up by `StripePaymentPanelProvider`, with live
history (authorization + capture) and Refund available. A full refund from the Payment tab afterwards booked exactly
one `refund` row (`re_…`, 116.50), and the contract showed refunded = captured = 116.50.

To receive webhooks on a local shop: `stripe listen --forward-to '<shop>/index.php?cl=StripeWebhookController&shp=<id>'`,
with the printed `whsec_…` in the module's webhook secret.

---

## Tests

| Test | Covers |
|---|---|
| `tests/Unit/Stripe/Command/MigrateLegacyOrdersCommandTest.php` | dry run vs `--apply`, summary output, exit code on failures |
| `tests/Unit/Stripe/Service/LegacyOrderMigrationServiceTest.php` | candidates, contract walk, amounts, metadata, skip/fail collection |
| `tests/Unit/Stripe/Service/LegacyOrderAssessmentTest.php` | every skip reason, Stripe not-found vs other errors, captured/refunded amounts |

```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter 'LegacyOrder|MigrateLegacyOrders' --testsuite Unit
```
