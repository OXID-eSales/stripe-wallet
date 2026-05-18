# Report — Partial-capture produces "Available for refund: -197 EUR" and blocks the refund form

**Date:** 2026-05-18
**Reporter:** Daniil Tkachev
**Order observed:** order 815 on `osc1.oxid.shop` (referred to in the ticket as
"order 65" on `daniil.oxiddev.de`; the symptom is reproducible on any partially-
captured Stripe order regardless of shop)
**Stripe PaymentIntent:** `pi_3T0ZVURKy8lrhVfC1hzvnhKr`
**Affected screen:** Administer Orders → Orders → *Stripe* tab → *Refund* section

---

## 1. Symptom (what the admin sees)

The screenshot from the ticket shows:

| Field in the Stripe panel | Value displayed |
|---|---|
| Factual Captured Amount | **100,00 EUR** |
| Refunded Amount | **297,00 EUR** (in red) |
| Available for refund | **−197,00 EUR** |
| Transaction History – Authorization | 397,00 EUR (completed) |
| Transaction History – Capture | 100,00 EUR (completed) |
| Transaction History – Refund | 297,00 EUR (succeeded, `re_3T0ZVURKy8lrhVfC192kW1yz`) |

Entering any positive value in the *Amount (EUR)* input fires the browser's
HTML5 form-validation tooltip:

> Minimum value (0.01) must be less than the maximum value (-197).

That is the **direct symptom** described as point 1 ("negative refund amount in
the refund field"). The user can never submit the refund.

---

## 2. Steps to reproduce (matches the ticket)

1. Place a Stripe order with **manual capture** (`sStripeCaptureMode = manual`)
   so the PaymentIntent is created with `capture_method=manual` and lands in
   `requires_capture`.
2. In the admin Stripe tab, capture **less than** the authorized amount
   (e.g. authorize 397 EUR → capture 100 EUR).
3. Reload the Stripe tab. The *Refund* section appears.
4. Type **any** positive amount and try to submit.

**Actual:** form-validation tooltip "Minimum value (0.01) must be less than the
maximum value (-197)" — submission blocked.
**Expected:** the admin should be able to refund up to the actually-captured
100 EUR (i.e. `max = 100,00 EUR`, "Refunded Amount" = 0,00 EUR).

---

## 3. Root cause

### 3.1 What Stripe does on partial capture

When `PaymentIntent::capture` is called with
`amount_to_capture < paymentIntent.amount`, Stripe automatically releases the
uncaptured remainder back to the customer's bank. Per Stripe's documented
behaviour, that release is **recorded on the charge in two places**:

- A **Refund object** is created on the charge
  (`re_3T0ZVURKy8lrhVfC192kW1yz` in this case, amount = 297 EUR).
- The charge field **`amount_refunded` is incremented by the released amount**.

So on a 397 → 100 partial capture the Stripe charge ends up with:

| Stripe field | Value | What it actually represents |
|---|---|---|
| `amount` | 39700 | Originally authorized (cents) |
| `amount_captured` | 10000 | Money the customer was actually charged |
| `amount_refunded` | 29700 | **Sum of auto-release (29700) + real customer refunds (0)** |
| `refunds.data[]` | 1 refund of 29700 | The auto-release; **not** a customer refund |

The key trap: Stripe collapses two semantically different things — *"auth
released at partial capture"* and *"money refunded back to the customer
post-capture"* — into the same `amount_refunded` counter and the same
`refunds` list.

### 3.2 What our module does

Two helpers read `amount_refunded` as if it were the *customer-refunded* total:

**`src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php:153–160`**

```php
public function getRemainingRefundableRaw(Order $order): float
{
    $charge = $this->getLastCharge($order, true);
    if ($charge && !empty($charge->amount_captured)) {
        return ($charge->amount_captured - ($charge->amount_refunded ?? 0)) / 100;
    }
    return 0.0;
}
```

With the values above: `(10000 - 29700) / 100 = -197.00`.

**`src/Stripe/Model/Order.php:162–175`** does the same for the displayed
"Refunded Amount":

```php
public function getStripeRefundedAmount(): string
{
    ...
    $refunded = (int) ($charge->amount_refunded ?? 0);
    if ($refunded <= 0) { return ''; }
    return $this->formatStripeAmount($refunded / 100);   // shows 297,00 EUR
}
```

That value then lands in
`views/twig/admin/panel/stripe_panel.html.twig:245` ("**297,00 EUR**" in red)
and the float at line :397 is wired straight into the HTML5 input bounds:

```twig
<input ... value="{{ remainingRefundableRaw }}"
       step="0.01" min="0.01" max="{{ remainingRefundableRaw }}">
```

With `remainingRefundableRaw = -197`, the input emits
`min="0.01" max="-197"` and the browser rejects every keystroke.

### 3.3 Why both questions in the ticket have the same answer

The reporter asked two questions:

1. *Why is the "Available for refund" value negative (−197)?*
2. *Why does this happen at all?*

Both reduce to the same cause: **the module treats Stripe's
`charge.amount_refunded` as the customer-refund total, but on a partial
capture that field also includes the released-but-never-captured remainder.**

- Question 1 — the negative number: arithmetic
  `amount_captured (100) − amount_refunded (297) = −197`.
- Question 2 — why it happens: because Stripe encodes the auth-release as a
  Refund object on the charge, and the module conflates auth-release with
  customer-refund.

---

## 4. Correct math

Define:

- `A` = authorized amount = `charge.amount`
- `C` = captured amount = `charge.amount_captured`
- `R_stripe` = `charge.amount_refunded`
- `R_release` = auto-released uncaptured remainder = `A − C` (≥ 0 always)
- `R_customer` = real customer refunds = `R_stripe − R_release`

The two displayed values should be computed as:

```
Refunded Amount        := R_customer                       = R_stripe − (A − C)
Available for refund   := C − R_customer                   = C − (R_stripe − (A − C))
                                                           = A − R_stripe
```

Verifying against the screenshot:

| Quantity | Formula | Value |
|---|---|---|
| `A` | `charge.amount` | 397 |
| `C` | `charge.amount_captured` | 100 |
| `R_stripe` | `charge.amount_refunded` | 297 |
| `R_release` | `A − C` | 297 |
| `R_customer` | `R_stripe − R_release` | **0** ✅ |
| Available for refund | `C − R_customer` | **100** ✅ |

This matches the operator's intuition: nothing has been refunded to the
customer yet, and the full captured 100 EUR is refundable.

Note that for **full captures** (`C == A`) we have `R_release = 0` and the
new formula collapses back to the old one (`C − R_stripe`), so the fix does
not regress the non-partial-capture path.

---

## 5. Two places that need to change

| File | Current behaviour | Fix |
|---|---|---|
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php:153–160` (`getRemainingRefundableRaw`) | `(C − R_stripe) / 100` | `(C − max(0, R_stripe − (A − C))) / 100` — i.e. subtract only the customer-refund portion. |
| `src/Stripe/Model/Order.php:162–175` (`getStripeRefundedAmount`) | Displays `R_stripe / 100` | Display `max(0, R_stripe − (A − C)) / 100`, or render an empty string when `R_customer == 0`. |

Both helpers already have access to the same `\Stripe\Charge` object, so the
extra two integer subtractions can be done in-place; no schema change, no new
Stripe API call.

`isOrderRefundable()` at lines 129–140 has the same dependency on
`amount_refunded` and should be re-checked at the same time — a partial-capture
order with no customer refund currently reports
`empty($amountRefunded) || $amountRefunded != $amount` → `true` only by
coincidence (297 ≠ 397). It happens to give the right answer here, but is
semantically wrong for the same reason and should switch to "any
captured-and-not-yet-refunded money left".

A third, smaller fix to consider: the *Transaction History* table at
`views/twig/admin/panel/stripe_panel.html.twig` shows
"Refund — 297,00 EUR — succeeded" with no hint that it was an auth-release.
This is the truthful Stripe-API record and is not strictly wrong, but it
misleads the operator into believing the customer was already refunded.
Tagging the row "Authorization release" when its `metadata` / amount matches
`A − C` would close the loop on the visual confusion.

---

## 6. Why this slipped past existing tests

- The unit tests for `OrderRefundViewDataProvider` and the `Order` extension
  feed in synthetic `\Stripe\Charge` objects with `amount_captured ==
  amount`, so `amount_refunded` only ever covers real refunds.
- Integration tests against the Stripe-Mock fixtures use full captures.
- No test currently constructs a charge with
  `amount_captured < amount AND amount_refunded == amount − amount_captured`,
  which is the exact shape Stripe returns after a partial capture.

The fix should land with a new fixture
`tests/Unit/Stripe/Controller/Admin/OrderRefundViewDataProviderPartialCaptureTest.php`
that constructs that charge shape and asserts `R_customer = 0`, `available =
C` for the just-partial-captured case, plus a follow-up case where a real
50 EUR customer refund is then issued → `R_customer = 50`, `available = 50`.

---

## 7. Scope / impact

- **Severity:** functional block. Any partially-captured Stripe order cannot
  be refunded through the admin UI, even though the refund is legitimately
  possible at the PSP. Operators have to refund from the Stripe Dashboard
  and reconcile by hand.
- **Affected configurations:** `sStripeCaptureMode = manual` combined with a
  partial capture in the Stripe tab. Auto-capture flow is unaffected.
- **Data integrity:** read-only display bug. No corrupted DB writes; the
  contract / `oe_payments_transaction` audit log are unaffected. OXPAID
  reconciliation is unaffected because it reads `amount_captured`, not
  `amount_refunded`.
- **Customer-visible side effect:** none directly, but the operator's
  inability to refund through the admin can delay legitimate customer
  refunds.

---

## 8. Suggested ticket title

> Stripe admin tab: partial capture leaves "Available for refund" negative
> and blocks the refund form (auth-release counted as refund)
