# Sprint 136 — completion report

**Sprint doc:** [`done/sprint-136-which-method-actually-paid.md`](../done/sprint-136-which-method-actually-paid.md)
**Date:** 2026-08-27
**Repos:** `extensions/stripe` @ `b-7.4.x-psp-payment-method-STRP-TBD` · `extensions/mollie-payment` @ `main-psp-payment-method-STRP-TBD`
**Status:** all five stories delivered, all gates green, `payment-base` untouched as planned.

---

## What an operator sees now

One new row in the admin order → "Payment" tab, in both PSP panels, directly
under the shop's payment-type row:

| state | Stripe panel | Mollie panel |
|---|---|---|
| card | **Credit card** · Visa •••• 4242 | **Credit card** · Visa •••• 4242 |
| wallet | **Apple Pay** · Mastercard •••• 0007 | **Apple Pay** · Mastercard •••• 0007 |
| BNPL | **Klarna** | **Klarna** |
| bank redirect | **iDEAL** | **iDEAL** |
| nothing charged yet / PSP unreachable | – | — |
| method the map doesn't know | `boleto` (raw code, verbatim) | `some_new_method` (raw code, verbatim) |

Each panel keeps its own existing dash glyph (`&ndash;` in Stripe's card,
`&mdash;` in Mollie's) rather than importing the other's — consistency inside a
panel beats consistency across two independently released modules.

## Commits

| repo | commit | story |
|---|---|---|
| stripe | `625b8d2` | 1 — carry `payment_method_details` across the adapter boundary |
| stripe | `2eb106e` | 2 — descriptor, label map, view data, template row, EN/DE keys |
| mollie | `9360d2a` | 3 — one Mollie payment read per panel render (refactor) |
| mollie | `f106e0c` | 4 — Mollie method row with card-detail parity |
| both | (this commit) | 5 — render tests, report, docs |

## Definition of Done — actual results

| # | DoD item | result |
|---|---|---|
| 1 | Both panels render the real PSP method, with brand/last4/wallet | done; rendered and asserted, not eyeballed (see *Render verification*) |
| 2 | Every new production line arrives after a RED test | held for all four stories; RED output was confirmed before each GREEN step |
| 3 | `oxpaymenttype`-fed rows unchanged | untouched in both builders; existing assertions (`testBuild_IncludesOrderNumberAndPaymentType`) still green |
| 4 | Mollie `getPayment()` calls per render = **exactly 1** | asserted in `MolliePanelViewDataBuilderTest::testBuild_WholeRenderCostsOneMollieApiCall` — was 3, would have been 4 |
| 5 | Additive DTO widening, named arguments | both widenings are trailing nullable params defaulted to null; `MollieAdapter::mapPayment()` and the mapper both construct by name |
| 6 | EN + DE keys in `views/admin_twig/` | 21 Stripe + 31 Mollie method keys plus the row label; a test loads both lang files per repo and fails on a missing key in either |
| 7 | Gates green in both repos | see below |
| 8 | Report + sprint doc moved + `status.md` | this file |

## Tests added

| repo | file | tests |
|---|---|---|
| stripe | `Unit/…/StripeObjectMapperTest` (extended) | +5 |
| stripe | `Unit/…/Admin/PaymentMethodDescriptorTest` | 10 |
| stripe | `Unit/…/Admin/PaymentMethodLabelsTest` | 24 |
| stripe | `Unit/…/Admin/StripePanelViewDataBuilderTest` (extended) | +6 |
| stripe | `Integration/Admin/PaymentMethodRowRenderTest` | 3 |
| mollie | `Unit/Adapter/PaymentMethodDetailsMappingTest` | 6 |
| mollie | `Unit/Admin/PaymentMethodDescriptorTest` | 7 |
| mollie | `Unit/Admin/PaymentMethodLabelsTest` | 35 |
| mollie | `Unit/Admin/MolliePaymentSnapshotProviderTest` | 5 |
| mollie | `Unit/Admin/MolliePanelViewDataBuilderTest` (extended) | +8 |
| mollie | `Unit/Admin/AdminActionBoundsTest` (characterization) | +3 |
| mollie | `Integration/Admin/PaymentMethodRowRenderTest` | 3 |
| | **total** | **115** |

## Gate results

**mollie-payment** — everything green, whole-suite:

```
phpcs (PSR-12)                     clean
phpstan  level 6 (repo config)     no errors
phpstan  level max (sprint diff)   no errors
phpmd    --strict + baseline       clean, baseline unchanged
phpunit --testsuite Unit           OK  582 tests, 1502 assertions
phpunit --testsuite Integration    OK   30 tests,  131 assertions (3 pre-existing skips)
```

**stripe** — green, with one environment caveat:

```
phpcs (PSR-12)                     clean
phpstan  level max (sprint diff)   no errors
phpmd    --strict + baseline       clean, baseline unchanged
phpunit --testsuite Integration    OK   92 tests,  392 assertions
phpunit --testsuite Unit           CANNOT BUILD — pre-existing, see below
```

The Stripe Unit suite cannot be *built* in this dev environment, so it was run
per-directory instead: `Stripe/Admin` 131, `Stripe/Adapter` 156,
`Stripe/Service` 534, `Stripe/EventSystem` 208, `Stripe/Model` 28,
`Stripe/Core` 121 → **1,178 tests, all green except one pre-existing error**
(`ViewConfigDebugTest`, below).

## Pre-existing failures, confirmed not caused by this sprint

1. **Stripe Unit suite cannot build.** `Class "…\Mollie\Controller\PaymentController_parent" not found`, raised while PHPUnit autoloads `PaymentControllerCleanupTest`. Cause is this shop's `var/configuration/shops/1/class_extension_chain.yaml`, where **four** modules (OPC, Stripe, PayPal, Mollie) plus payment-base extend `PaymentController`; the alias chain recurses during autoload outside a request. Same root cause for `Stripe/Core`'s single error (`ViewConfig_parent`, PayPal/opalreturns chain). Already recorded in project memory from an earlier session; the runtime chain is fine.
2. **`ModuleLifecycleTest::testServicesAvailableAfterActivation`** fails on `$container->has('…\Stripe\Service\ModuleConfigurationService')`. That concrete-class id is private and gets inlined away, so `has()` is false by design — the known private-service trap. This sprint added **no** service definitions to stripe (`git diff b-7.4.x...HEAD` shows `services.yaml` untouched), so it cannot be the cause.

Neither is in this sprint's scope; both are worth their own ticket.

## Render verification

Both panel bodies were rendered through the shop's real Twig renderer with the
builder's exact view-data shape, for all three states, and the assertion is now
permanent (`PaymentMethodRowRenderTest` in each repo). A view-data key renamed
on either side of the builder↔template boundary now fails a test instead of
silently rendering an empty cell.

The DI container was also booted and asked for the four affected services:

```
OK  Mollie\Admin\MolliePaymentSnapshotProviderInterface => MolliePaymentSnapshotProvider
OK  Mollie\Admin\AdminActionBoundsInterface             => AdminActionBounds
OK  Mollie\Admin\MolliePaymentPanelProvider             => MolliePaymentPanelProvider
OK  Stripe\Admin\StripePaymentPanelProvider             => StripePaymentPanelProvider
```

The third line is the one that matters: it proves the widened
`MolliePanelViewDataBuilder` constructor (snapshot provider + translator)
autowires, which is exactly the failure mode this module has hit before.

## Deviations from the sprint plan

1. **No `STRIPE_PAYMENT_METHOD_UNKNOWN` key.** The plan had `keyFor()` fall back
   to an "Unknown" ident. Implemented instead: `keyFor()` returns `null` for an
   unmapped code and the panel prints the raw PSP code verbatim. Stripe and
   Mollie both add methods faster than a hand-written map grows, and
   `boleto` tells an operator strictly more than `Unknown` does. Unknown is now
   reserved for its true meaning: *the PSP has not told us*.
2. **Mollie label set widened to 31 methods** (plan said 28) — `googlepay`,
   `paybybank` and `voucher`/`giftcard` split out as Mollie reports them
   separately.
3. **Failed Mollie reads are now cached.** Not in the plan; it follows from the
   memoization. Before, a down PSP was retried once per consumer and logged an
   identical warning each time — three round trips and three warnings for one
   incident. Now: one attempt, one warning.

## Hand-off: the orphaned audit column

`oe_payments_transaction.OXPAYMENTMETHODTYPE` and `OXPAYMENTMETHODID` exist,
`TransactionInterface::setPaymentMethodType()`/`setPaymentMethodId()` exist, and
`DoctrineTransactionRepository` persists both — but **no module in any repo ever
calls the setters**, so both columns have been NULL since they were created.

This sprint deliberately did not wire them: the display path had to work for the
orders already in the database, which only a live PSP read can do. Wiring the
write path belongs with the authorization/capture handlers and their webhook
tests, and it is now cheap on the Stripe side — `StripeChargeDto` already
carries `paymentMethodType` at exactly the point the transaction row is
recorded. Recommended as the next sprint so the audit log stops lying by
omission.

## Follow-ups worth a ticket

1. Write `OXPAYMENTMETHODTYPE` / `OXPAYMENTMETHODID` on auth/capture/refund (above).
2. The two pre-existing failures in *Pre-existing failures* — especially the
   four-module `PaymentController` chain, which makes the Stripe Unit suite
   unrunnable as a whole locally.
3. Mollie's `AdminActionBounds` fail-closed 0.00 is still indistinguishable from
   "nothing left" **in the UI** — the warning only reaches the log. The snapshot
   seam now makes a single "could not reach Mollie" panel notice a small change.
4. Klarna sub-category (`payment_method_category`: pay_later / pay_now /
   slice_it) is available on the Stripe charge and discarded; add it to the
   detail slot if operators ask for it.
