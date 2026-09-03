# A voucher does not survive the external-payment roundtrip

> ## ✅ RESOLVED — verified fixed on daniil.oxiddev.de, 2026-09-02
>
> **OPC-168 fixes this. There is nothing left to implement — S5 is unnecessary.**
> This file went through two wrong conclusions before this one; both are recorded
> below so the reasoning can be audited.
>
> ### The verification
>
> Playwright, against the live shop, with a real Mollie session
> (`e2e/opc-168-coupon-proof.spec.ts`, `--grep "used again"`, exit code 0):
>
> ```
> processCheckout → success:true, redirectUrl https://www.mollie.com/checkout/…
> order booked    → NOT_FINISHED, OXPAID=0000-00-00 (unpaid)
> return (page load) → "unwound stranded voucher reservations"
> oxvouchers row  → OXORDERID='', OXDATEUSED=NULL        ← released
> re-enter code   → applies again                        ← redeemable
> ```
>
> Instrumenting `dispatchIfExternalReturn()` showed the release firing on exactly
> the right request:
> `{"cl":"","snapshotCount":1,"snapshot":[{"nr":"OPC168PROOF","id":"opc168_v1"}],"willPersist":true}`
>
> ### Wrong conclusion 1 — analysed a stale checkout
>
> The body of this report was written against a `one-page-checkout` tree 257
> commits behind `origin/b-7.4.x`. OPC-168 (2026-08-31, v1.6.31/v1.6.32, tag
> v1.6.34) had already fixed both defects it names. §4 and §5 are obsolete as
> *defects*; they remain an accurate description of the mechanism.
>
> ### Wrong conclusion 2 — a harness artefact reported as a product bug
>
> I then "reproduced" the bug and said the release never fires. It was my spec.
> It reopened the modal with the shared `openOpcModal()` helper, which shows the
> modal on the page **already loaded** and does not navigate. The release runs in
> `FrontendController::init`, so with no page load it never had a request to run
> in. The canonical spec calls `page.goto()` first and says why; my adaptation
> dropped that line. Adding it turned the failure into a pass.
>
> **The lesson worth keeping:** a spec that drives OPC through AJAX only will
> never see the release, and will look exactly like this bug.
>
> ### The real constraint, and it is by design
>
> The release only fires on a request that persists the basket — any controller
> whose `cl` does not start with `Oe`. OPC's own endpoints answer through
> `jsonResponse()`, which `exit`s before `Session::freeze()`, so releasing there
> frees the row and then throws the basket away. A customer who returns and only
> touches OPC AJAX endpoints therefore keeps a stranded coupon until their next
> real page view.
>
> ### So why was the bug reported?
>
> Most likely the reporting shop runs OPC < v1.6.31. That is the thing to check
> first — not the code.


**Date:** 2026-09-02
**Reported as:** OPC is enabled; a valid voucher is applied; the customer goes to an
external payment page and returns without completing. Actual: *"Invalid voucher code"*
is displayed and the voucher is gone from the checkout. Expected: the voucher stays
applied while the same checkout session is live.
**Scope:** `one-page-checkout`, with `payment-base` and every PSP module implicated
**Status:** root cause identified and proven from source; one runtime question left open (§6)

---

## 1. Summary

The voucher is consumed by an order that has not been paid for.

Placing the order creates the **early `NOT_FINISHED` order** before the customer is
redirected. `Order::finalizeOrder()` marks the voucher as *used* at that moment. From
then on OXID considers the code spent, so the moment the basket is recalculated the
voucher is dropped and `ERROR_MESSAGE_VOUCHER_NOVOUCHER` — *"Invalid voucher code"* —
is queued for display.

OPC already has a recovery pipeline for exactly this (OPC-96 v4/v5): unwind the
voucher row, re-add it to the basket, scrub the stale error. **The unwind releases
three of the four fields the re-add requires.** It deliberately leaves `oxdateused`
set, and `Basket::addVoucher()` filters on `oxdateused`. So the re-add can never find
the row the unwind just freed, and the recovery fails on its own second step.

## 2. How the voucher gets consumed

`Order::finalizeOrder()` (`source/Application/Model/Order.php:550`):

```php
if (!$blRecalculatingOrder) {
    $this->markVouchers($oBasket, $oUser);
}
```

`markVouchers()` → `Voucher::markAsUsed()` (`Voucher.php`) writes **`oxorderid`,
`oxuserid`, `oxdiscount`, `oxdateused`**.

Every adapter that creates the early order calls `finalizeOrder($basket, $user, false)`
— the third argument is `$blRecalculatingOrder` — so the marking always happens:

| Adapter | call |
|---|---|
| `payment-base` (canonical, since 2026-09-01) | `finalizeOrder($basket, $user, false)` |
| stripe / mollie / paypal / opc (retired copies) | identical |

This is correct for a normal checkout, where finalisation is the last step. Under the
early-order pattern it happens **before** the customer has paid, and the order may
never complete.

## 3. How the customer sees it

`Basket::_calcVoucherDiscount()` (`Basket.php:1080-1128`) revalidates every applied
voucher on **every basket calculation**:

```php
$oVoucher->checkBasketVoucherAvailability($this->_aVouchers, $dPrice);
...
} catch (VoucherException $oEx) {
    $oVoucher->unMarkAsReserved();
    unset($this->_aVouchers[$sVoucherId]);          // ← voucher leaves the basket
    Registry::getUtilsView()->addErrorToDisplay($oEx, false, true);  // ← the toast
}
```

and the guard it trips is `Voucher::isAvailable()`:

```php
if (empty($this->oxvouchers__oxorderid->value)) {
    return true;
}
throw new VoucherException('ERROR_MESSAGE_VOUCHER_NOVOUCHER');
```

**Any** non-empty `oxorderid` is fatal. Both reported symptoms — the message and the
disappearance — come from this one `catch`.

Note the error is written to the session's `Errors[]` and therefore **survives the
redirect**. It is queued before the customer leaves and displayed when they come back.

## 4. Why OPC's recovery does not save it

`OpcModalReopenDispatcher` runs three steps in order (`OpcModalReopenDispatcher.php:120-141`):

1. `OpcVoucherReservationUnwindService::unwindByIds()` — free the row
2. `retryVoucherRestore()` — `Basket::addVoucher($nr)`
3. `scrubStaleVoucherErrors()` — drop the leftover toast

**Step 1 frees three fields:**

```sql
UPDATE oxvouchers SET oxorderid = "", oxuserid = "", oxreserved = 0
WHERE oxid IN (...) AND oxorderid != ""
```

**Step 2 needs four.** `Basket::addVoucher()` calls
`getVoucherByNr($nr, $this->_aVouchers, true)`, whose lookup is:

```sql
( oxorderid is NULL || oxorderid = '' )
and ( oxdateused is NULL || oxdateused = 0 )
and oxreserved < <now - iVoucherTimeout>      -- because $blCheckavalability = true
```

`oxdateused` is still today's date, so **no row matches**, `getVoucherByNr()` throws
`ERROR_MESSAGE_VOUCHER_NOVOUCHER`, `addVoucher()` rethrows it, and
`retryVoucherRestore()` swallows it into a log line. The voucher never returns.

### The reasoning error, in the code's own words

The omission is deliberate and documented (`OpcVoucherReservationUnwindService.php`, v5):

> `Voucher::isAvailable` gates on `oxorderid == ''` and `oxreserved == 0`;
> `oxdateused` is not on the availability path, so leaving it untouched is safe.

That is true of `isAvailable()` and false of the path the restore actually takes.
`isAvailable()` is reached from `_calcVoucherDiscount()`; the restore goes through
`getVoucherByNr()`, a different function with a stricter filter. The v5 change was
made for a real reason — writing `oxdateused = "0000-00-00 00:00:00"` is rejected
under MySQL 8 `NO_ZERO_DATE`, which aborted the whole `UPDATE` and left rows
stranded — but the fix removed the field instead of correcting the value.

### The correct value is NULL

`oxvouchers.OXDATEUSED` is a **nullable `date`** with default `NULL` (verified against
the dev database). `NULL` satisfies `oxdateused is NULL` and is accepted under
`NO_ZERO_DATE`; the zero-date *literal* was the problem, not the reset.

This is not theoretical: payment-base's own `DoctrineNotFinishedOrderRepository::releaseVouchers()`
(STRP-168) already resets the same rows with `OXDATEUSED = NULL` and was verified
against MySQL 8 on 2026-08-28.

## 5. Secondary defect — the diagnostic that would have found this logs `null`

`OpcModalReopenDispatcher::retryVoucherRestore()`:

```php
foreach ($snapshot as $entry) {
    $nr = ...;
    try { $basket->addVoucher($nr); }
    catch (\Throwable $e) {
        $this->logger->info('OPC-96: post-dispatch voucher retry skipped', [
            'voucherNr' => $voucherNr,   // ← undefined; the variable in scope is $nr
            'error'     => $e->getMessage(),
        ]);
    }
}
```

`$voucherNr` does not exist in that scope. Under PHP 8 this is a warning and the value
is `null`, so the single log line that would have named the failing voucher — on the
exact path that is failing — records nothing. The `error` field still carries
*"Invalid voucher code"*, which is why the symptom is visible in logs but the cause is not.

## 6. Open question, to settle at runtime

Everything above is proven from source. What is **not** established is whether the
recovery pipeline runs at all on the reported path.

`OpcModalReopenDispatcher` is gated on a URL modal id and a session latch
(`SESSION_LATCH_KEY`). The report says the customer *"returns to the shop"* — if that
return does not carry the modal id, or the latch has already been consumed, then
**none** of unwind / restore / scrub executes and the voucher is stranded for a second,
independent reason. Grep `OPC-96` in `oxideshop.log` for the reproduction: the presence
of `unwound stranded voucher reservations` distinguishes the two.

Both causes are worth fixing regardless of which fires; §4 is a defect on any path that
reaches it.

## 7. Blast radius

- **Every PSP**, not just Stripe. The consumption is in core `finalizeOrder()`, and the
  unwind is provider-agnostic. Stripe additionally has `OpcExternalReturnCleanupHandler`,
  which releases the row through `deleteNotFinishedOrder()`; that path resets
  `oxdateused` correctly and may mask the bug on Stripe while leaving it live elsewhere.
- **Customer-visible and money-adjacent**: the shopper is shown a false error about a
  valid voucher, and completes the purchase without the discount they were promised.
- A voucher stranded this way is unusable **for the whole `iVoucherTimeout` window
  (default 3 h)** even in a fresh session, until the nightly
  `oe:payments:not_finished:cleanup` (STRP-168) releases it.
