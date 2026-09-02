# Sprint 136 — Keep the voucher through the payment roundtrip

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


**Ticket:** OPC-TBD (assign before branching)
**Branch:** `b-7.4.x-voucher-roundtrip-OPC-TBD` — `one-page-checkout` primarily; one test in `payment-base`
**Source:** [`reports/01-opc-voucher-lost-on-external-return.md`](../reports/01-opc-voucher-lost-on-external-return.md)
**Base:** `one-page-checkout` `b-7.4.x`
**Prerequisite:** none. Independent of STRP-168 (nightly cleanup), which shares the
voucher-release SQL and is the reference for the correct reset value.

---

## Why this sprint exists

A shopper applies a valid voucher, is sent to the payment provider, comes back, and is
told the voucher is invalid. It is not: it was spent by an order **they have not paid
for**, created early so an order number would exist before the redirect.

OPC already recognised this and built a three-step recovery in OPC-96 v4/v5 — unwind
the voucher row, re-add it, scrub the stale error. The recovery does not work, and it
fails for a small, exact reason:

> **The unwind frees three fields. The re-add requires four.**

`unwindByIds()` resets `oxorderid`, `oxuserid`, `oxreserved`. `Basket::addVoucher()`
looks the voucher up with `getVoucherByNr(..., $blCheckavalability = true)`, which also
demands `oxdateused is NULL || oxdateused = 0`. The unwind leaves `oxdateused` at
today's date, so the lookup matches nothing and the restore throws the very error it
exists to prevent.

The omission was deliberate and is documented in the v5 comment:

> `Voucher::isAvailable` gates on `oxorderid == ''` and `oxreserved == 0`;
> `oxdateused` is not on the availability path, so leaving it untouched is safe.

True of `isAvailable()`, false of `getVoucherByNr()` — a different function, on the path
the restore actually takes. The v5 change had a real cause (`oxdateused = "0000-00-00 00:00:00"`
is rejected under MySQL 8 `NO_ZERO_DATE` and aborted the whole `UPDATE`), but it dropped
the field rather than correcting the value. **The column is a nullable `date`; the
correct value is `NULL`**, which payment-base's own `releaseVouchers()` has been using
against MySQL 8 since 2026-08-28.

## The rule this sprint installs

> **A voucher must not be consumed by an order that has not been paid for — and if it
> is, releasing it must free every field the re-add checks.**
> The release and the lookup are two halves of one contract. A test must fail if they
> drift apart again.

---

## Stories

### S1 — Reset `oxdateused` to NULL in the unwind  *(the fix)*

`OpcVoucherReservationUnwindService::unwindByIds()`:

```sql
UPDATE oxvouchers
   SET oxorderid = "", oxuserid = "", oxreserved = 0, oxdateused = NULL
 WHERE oxid IN (...) AND oxorderid != ""
```

Replace the v5 comment with what was actually learned: the zero-date *literal* was
rejected, `NULL` is not, and `oxdateused` **is** on the availability path — via
`getVoucherByNr()`, not `isAvailable()`.

**Done when:** an integration test marks a voucher used, unwinds it, and
`Basket::addVoucher($nr)` succeeds. Must run against MySQL 8 with `NO_ZERO_DATE` in
`sql_mode` — the whole point of v5 — and assert the `UPDATE` reported affected rows
rather than being swallowed by the `catch`.

### S2 — Pin the release/lookup contract so it cannot drift again

The defect is that two pieces of code disagree about which fields matter. Test that
directly, in `payment-base` beside `VoucherReleaseInterface`:

- a released voucher satisfies **every** clause of `getVoucherByNr()`'s filter —
  `oxorderid` empty, `oxdateused` null, `oxreserved` below the timeout;
- assert per field, so a future "safe to leave this one" change names the field it breaks.

payment-base's `releaseVouchers()` and OPC's `unwindByIds()` are the same operation
written twice. S2 pins both; consolidating them is **out of scope** (see below) but this
test is what would make that safe later.

### S3 — Fix the log line that hid this

`OpcModalReopenDispatcher::retryVoucherRestore()` logs `'voucherNr' => $voucherNr`; the
variable in scope is `$nr`, so the one diagnostic on the failing path records `null`.

Rename it, and add the voucher **id** alongside the number — the id is what the unwind
targets, so a mismatch between "unwound id" and "failed nr" is the signature of S1
having regressed.

**Done when:** a unit test asserts the failure log carries the voucher number.

### S4 — Establish whether the recovery runs at all on the reported path

Open question from the report §6, and the one thing not provable from source.
`OpcModalReopenDispatcher` is gated on a URL modal id and a session latch. If the
customer's return does not carry the modal id, or the latch is already consumed, then
none of unwind / restore / scrub executes and the voucher is stranded for a second,
independent reason.

Reproduce with `OPC-96` grep in `oxideshop.log`:

| log line present | meaning |
|---|---|
| `unwound stranded voucher reservations` | pipeline ran → S1 is the whole bug |
| nothing | pipeline never ran → S5 is needed as well |

**Done when:** the answer is recorded in the completion report, with the log excerpt.

### S5 — *(conditional on S4)* Make the release independent of the reopen path

Only if S4 shows the dispatcher does not run. The voucher release must not depend on the
customer returning through one specific URL: a shopper who closes the tab and navigates
back to the shop is in the reported state too.

Preferred shape, cheapest first:
1. release on the **contract** leaving its live state (payment-base already owns
   `VoucherReleaseInterface`), rather than on an OPC UI event;
2. failing that, an additional trigger on basket load when a snapshot exists.

Do **not** fix this by shortening `iVoucherTimeout`, and do not fix it by removing the
availability check.

---

## Out of scope

- **Not marking vouchers at early-order time at all.** The honest fix — a voucher is
  spent when the payment succeeds, not when the order row appears — means not calling
  `markVouchers()` for `NOT_FINISHED` orders and marking on commit instead. That changes
  the early-order contract for every PSP and needs its own sprint and a decision from
  whoever owns that pattern. This sprint restores the *recovery*; it does not remove the
  need for one.
- **Merging `unwindByIds()` and `releaseVouchers()`.** Same operation in two modules,
  worth consolidating once S2 pins the behaviour. Not while a customer-facing bug is open.
- Stripe's `OpcExternalReturnCleanupHandler`. It releases the row through
  `deleteNotFinishedOrder()`, which resets `oxdateused` correctly — so it likely masks
  this bug on Stripe. Leave it; S1 makes it redundant rather than wrong.

## Definition of done

- S1–S4 complete; S5 complete or explicitly closed by S4's evidence.
- The reported reproduction, run end to end: voucher applied → external payment page →
  return without paying → **voucher still applied, no error toast**.
- Verified on MySQL 8 with `NO_ZERO_DATE` in `sql_mode`.
- Verified on at least one non-Stripe provider, since Stripe's own handler may mask it.
- `./bin/pre-commit-check.sh --full` green in every repo touched.
- Completion report in `reports/`, this sprint moved to `done/`, `status.md` updated.
