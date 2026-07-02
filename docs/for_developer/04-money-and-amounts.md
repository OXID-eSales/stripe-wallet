# Money & amounts in the Stripe module

**Audience:** anyone touching a price, amount, capture, or refund value in the Stripe module.

This is the Stripe-specific companion to the canonical guide
[`payment-base/docs/engineering/money-arithmetic.md`](../../../payment-base/docs/engineering/money-arithmetic.md).
Read that first for the four rules, the IEEE-754 background, and the `Math\Money` toolkit. This page
covers only what is Stripe-specific.

---

## Stripe speaks integer minor units

The Stripe API expresses every amount as an **integer in the smallest currency unit** (cents for
EUR/USD, whole yen for JPY, 1/1000s for BHD). The OXID shop expresses amounts as **major-unit
floats** (`19.99`). The boundary between the two is the single most error-prone spot in the module,
so all conversion goes through one utility.

### `AmountConverter` — the conversion boundary

`OxidEsales\Payments\Stripe\Core\AmountConverter` is the Stripe-facing facade. It is a thin
delegate over payment-base's `MinorUnitConverter` (so the currency lists live in one place), kept as
the public API the module's ~22 conversion sites already depend on.

```php
use OxidEsales\Payments\Stripe\Core\AmountConverter;

$cents  = AmountConverter::toMinorUnits($amount, $currency);   // 19.99 EUR → 1999
$amount = AmountConverter::toMajorUnits($charge->amount, $currency); // 1999 → 19.99
$dp     = AmountConverter::decimalsFor($currency);             // EUR→2, JPY→0, BHD→3
```

Rules:

- **Never hand-code `* 100` / `/ 100`.** It is currency-blind (breaks JPY/BHD) and truncation-prone.
- **Always pass the real currency**, not a hardcoded `'EUR'`. Unknown/empty falls back to 2 decimals.
- **Once in cents, stay in cents** for arithmetic, then convert back once at the edge. Integer-cent
  math is exact; float math is not.

```php
// ✅ multiply in cents (exact integer × integer)
$sumCents += AmountConverter::toMinorUnits($unitPrice, $currency) * $quantity;   // CheckoutSessionService
// ❌ float product before conversion — drift before rounding
$sumCents += AmountConverter::toMinorUnits($unitPrice * $quantity, $currency);
```

> **Do not migrate the wire path to BCMath.** Integer minor units are already exact and idiomatic;
> see the canonical guide's "Why not BCMath?" section.

---

## Comparing amounts — use `Money`, not `==`

Where the module compares two *float* major-unit amounts (capture/refund validation), use
payment-base's `Money` helpers — one shared half-cent tolerance, no inline epsilons.

```php
use OxidEsales\PaymentBase\Math\Money\Money;

if (Money::greaterThan($requested, $remaining)) { /* a real over-capture, not float drift */ }
```

This replaced the module's private `CaptureService::AMOUNT_EPSILON = 0.005`.

---

## Capture math — `CapturableAmount`

`OxidEsales\Payments\Stripe\Service\CapturableAmount` owns the "how much is still capturable" math
and the over-capture guard, extracted from `CaptureService` so the boundaries are unit-tested without
booting the adapter/repository collaborators.

```php
use OxidEsales\Payments\Stripe\Service\CapturableAmount;

$remaining = CapturableAmount::remaining($authorized, $captured);          // authorized − captured
$reject    = CapturableAmount::isExceededBy($amount, $authorized, $captured); // > remaining + ε
```

`CaptureService::processCapture()` rejects a partial capture only when `isExceededBy()` is true (a
capture for *exactly* the remaining amount must never be rejected — that is what the tolerance buys).

---

## Refund math — `StripeChargeAmountResolver`

Partial captures complicate refund accounting: when you capture less than authorized, Stripe
auto-refunds the uncaptured remainder, which inflates `charge.amount_refunded`. The resolver
separates that auth-release from real customer refunds. **All inputs are Stripe minor units** from
`StripeChargeDto`; the math is integer arithmetic on cents, converted to major units only at the end:

```
R_release  = max(0, amount − amountCaptured)     // auth-released uncaptured remainder
R_customer = max(0, amountRefunded − R_release)  // real customer refunds only
available  = max(0, toMajorUnits(amountCaptured − toMinorUnits(toMajorUnits(R_customer))))
```

The two `max(0, …)` clamps are not redundant — float round-trips can yield `−0.000…1`, which would
leak as `max="-0.00"` into an HTML5 input attribute. See the class docblock for the full rationale.

---

## Where amounts cross the boundary (map)

| Direction | Site |
|-----------|------|
| OXID float → Stripe cents | `CheckoutSessionService` (line items, totals), `PaymentIntentHelper`, `RefundHelper`, `RefundService`, `CaptureService` |
| Stripe cents → OXID float | `StripeChargeAmountResolver`, `StripeTransactionHistoryBuilder`, `StripePanelViewDataBuilder`, `Model\Order`, `CheckoutReturnResult`, `StripeWebhookEventParser` |
| Float comparison | `CaptureService` / `CapturableAmount` (via `Money`) |
| Admin input parsing | `AdminAmountValidator` (compares in minor units to avoid boundary drift) |

Every one of these uses `AmountConverter`. If you add a new amount-bearing path, route it through the
converter and (for comparisons) through `Money` — do not reintroduce inline arithmetic.

---

## Further reading

- Canonical rules & `Math\Money` toolkit: [`payment-base/docs/engineering/money-arithmetic.md`](../../../payment-base/docs/engineering/money-arithmetic.md)
- Full code-review inventory + BCMath trade-off: [`02-floating-point-math-code-review.md`](../oe_payments_docs/daniil_dev_log/20260622/reports/02-floating-point-math-code-review.md)
