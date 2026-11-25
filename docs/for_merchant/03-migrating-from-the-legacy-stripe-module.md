# Moving from the old Stripe module

## Keep managing your old Stripe orders after the switch

**For:** shops that took Stripe payments with the old module `oxid-esales/stripe-module` (shown in the admin as
"Stripe Payment", module id `stripe`) and switch to this module (`oxid-esales/stripe-wallet`), for example as part of
an upgrade from OXID eShop 7.0 to 7.4 or later.

**Result:** the orders paid with the old module appear in the order's **Payment** tab like new orders. You see the
payment, its history and the amounts at Stripe, and you can refund from there.

---

## Table of Contents

1. [What changes](#what-changes)
2. [Before you start](#before-you-start)
3. [Step by step](#step-by-step)
4. [Reading the result](#reading-the-result)
5. [Multishop (Enterprise Edition)](#multishop-enterprise-edition)
6. [Known limitation: orders refunded in part before the move](#known-limitation-orders-refunded-in-part-before-the-move)
7. [Questions](#questions)

---

## What changes

| | Before (old module) | After (this module) |
|---|---|---|
| Where you manage a Stripe order | the old module's Stripe refund tab | the **Payment** tab of the order |
| Payment methods at checkout | one per method (`stripecreditcard`, `stripeideal`, …) | one entry, **Stripe**; Stripe shows the methods |
| Payment method shown on an old order | e.g. `stripecreditcard` | unchanged, still `stripecreditcard` |
| Order numbers, totals, invoices | unchanged | unchanged |
| The money at Stripe | unchanged; nothing is charged or refunded by the move | |

The move only adds a record per old order that tells the new module "this payment is yours now". It does not change the
order and does not touch the money.

---

## Before you start

You need:

- **The same Stripe account** the old module used. The old payments belong to that account. If this module is
  connected to another account, it cannot see them and skips every old order.
- **The same mode.** Orders paid in live mode need this module in **live** mode. Orders the old module took in test
  mode are not real payments and are skipped in live mode; that is expected.
- **A database backup.**
- Someone who can run a console command on the server (your agency or hosting partner), for one command.

Write down, from the old module's settings (Extensions → Modules → Stripe Payment → Settings):

- the mode (`test` / `live`),
- which Stripe account is connected (Stripe Dashboard → account name, top left).

---

## Step by step

### 1. Switch the modules

Your integrator removes the old module and installs this one (see [Installation](./01-installation-configuration.md#installation)).
The two modules cannot be installed together.

**Do not delete the old module's data.** Its order columns and its request log are the history of those payments.

### 2. Connect the same Stripe account

Extensions → Modules → Stripe → Settings: connect the **same account** in the **same mode** as before (see
[Configuration](./01-installation-configuration.md#configuration)).

### 3. Webhooks

- In the Stripe Dashboard (Developers → Webhooks) delete the old module's endpoint. Its URL contains `cl=stripeWebhook`.
- In this module's settings press **Create webhooks** (once in each shop).

From now on Stripe reports refunds and disputes on old orders to this module too.

### 4. Payment methods

Administer Shop Settings → Payment Methods: set the old Stripe methods (`Stripe Credit Card`, `Stripe iDEAL`, …) to
**inactive**. **Do not delete them**: the old orders refer to them. Activate **Stripe** from this module.

### 5. Check what would be moved (dry run)

The integrator runs on the server, in the shop's main directory:

```bash
vendor/bin/oe-console stripe:migrate-legacy-orders
```

Nothing is written. The command asks Stripe about every old Stripe order and lists what it would move and what it would
skip, with the reason. Read the list (see [Reading the result](#reading-the-result)).

### 6. Move the orders

```bash
vendor/bin/oe-console stripe:migrate-legacy-orders --apply
```

### 7. Check a few orders

Open some old orders → **Payment** tab. You should see the Stripe payment, the amount, the history (authorization,
capture, refunds), and a **Refund** button while money can still be refunded.

The command can be run again at any time. An order that was already moved is never touched again, so a second run only
picks up what was skipped the first time and can be moved now.

---

## Reading the result

```
Candidates: 120
Migrated: 112
Skipped: 8
Failed: 0
```

| Line | Meaning |
|---|---|
| Candidates | old Stripe orders that are not managed by this module yet |
| Migrated (dry run: Would migrate) | orders that now appear in the Payment tab |
| Skipped | orders left as they are, each with a reason (table below) |
| Failed | an error while moving this order, e.g. Stripe not reachable; run the command again later |

| Skip reason | What it means | What to do |
|---|---|---|
| no transaction id on the order | the order has no Stripe payment (the customer never paid) | nothing |
| transaction id '…' is not a PaymentIntent | a very old kind of payment this module cannot manage | manage it in the Stripe Dashboard |
| order is cancelled (storno) | cancelled in the shop | nothing |
| order was never paid | not marked as paid in the shop; this also covers payments that were authorized and never captured | check the order in the Stripe Dashboard |
| order total is zero | nothing to refund | nothing |
| the configured Stripe account has no PaymentIntent … | the payment belongs to another Stripe account, or to the other mode (test/live) | if it is a live order: check that the module is connected to the right account and mode, then run again |
| Stripe reports the PaymentIntent as '…', not paid | the payment is not finished at Stripe yet (e.g. a bank transfer method still processing) | run the command again in a few days |

---

## Multishop (Enterprise Edition)

The command moves the orders of one shop. Run it once per subshop:

```bash
vendor/bin/oe-console stripe:migrate-legacy-orders --apply --shop-id=1
vendor/bin/oe-console stripe:migrate-legacy-orders --apply --shop-id=2
```

Each subshop needs this module connected to the Stripe account that subshop used before.

---

## Known limitation: orders refunded in part before the move

If an old order was **partly refunded before the move** (with the old module or in the Stripe Dashboard), a **further
refund after the move** can make the shop show a wrong refunded total: the earlier refund may be counted twice. If the
doubled total would exceed the paid amount, the shop refuses Stripe's refund report instead.

The **money at Stripe is always correct**; only the shop's display is affected. Orders without an earlier refund, and
orders that were refunded in full before the move, are not affected.

Until this is fixed: for such orders, check the refund in the Stripe Dashboard (the Payment tab has a link to it).

---

## Questions

**Does the move charge or refund anything?**
No. It only reads from Stripe and writes a record in the shop.

**Can I undo it?**
The records can be removed from the database by your integrator (table `oe_payments_contract`, the rows whose metadata
contains `legacyMigration`). The orders themselves were not changed.

**Old orders still show `stripecreditcard` (or another old method) as payment method. Is that wrong?**
No. The order keeps the method it was paid with. The Payment tab manages it anyway.

**Can I capture an old order?**
There is nothing to capture: the old module captured every payment right away.

**Where is the old module's Stripe tab?**
It went away with the old module. Use the **Payment** tab.

---

## Related

- [Installation, configuration and usage](./01-installation-configuration.md)
- Developer view of the move: [Legacy order migration](../for_developer/07-legacy-order-migration.md)
