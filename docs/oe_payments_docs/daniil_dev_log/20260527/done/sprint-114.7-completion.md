# Sprint 114.7 Completion Report — AmountConverter: centralize cents math

**Date:** 2026-05-27
**Branch:** `b-7.4.x-code-review-STRP-145`
**Commits:** `e7853c7`, `eaa30b5`, `80403af`, `d359bf4`

---

## 1. AmountConverter API

**Location:** `src/Stripe/Core/AmountConverter.php`
**Namespace:** `OxidEsales\Payments\Stripe\Core`

```php
final class AmountConverter {
    public static function decimalsFor(string $currency): int;      // 0 | 2 | 3
    public static function toMinorUnits(float $major, string $currency): int;   // (int) round($major * 10**n)
    public static function toMajorUnits(int $minor, string $currency): float;   // $minor / 10**n
}
```

Currency tables:
- **Zero-decimal (exponent 0):** BIF, CLP, DJF, GNF, JPY, KMF, KRW, MGA, PYG, RWF, UGX, VND, VUV, XAF, XOF, XPF
- **Three-decimal (exponent 3):** BHD, JOD, KWD, OMR, TND
- **Default (exponent 2):** everything else (EUR, USD, GBP, …) and unknown/empty currency

No interface (pure function, per R-9.1 YAGNI). Static methods — directly testable without DI.
Not registered in services.yaml — no wiring needed for a static utility class.

---

## 2. Per-Site Migration

### Batch A — adapter helpers (commit `eaa30b5`)

| File | Line | Old | New | Currency source | EUR change? |
|------|------|-----|-----|----------------|-------------|
| PaymentIntentHelper.php | 53 | `(int)($request->amount * 100)` | `AmountConverter::toMinorUnits($request->amount, $request->currency)` | `$request->currency` | **BUG FIX** — truncation → round |
| PaymentIntentHelper.php | 97 | `$paymentIntent->amount / 100` | `AmountConverter::toMajorUnits($paymentIntent->amount, $piCurrency)` | `$paymentIntent->currency` | Equivalent |
| PaymentIntentHelper.php | 98 | `$paymentIntent->amount_received / 100` | `AmountConverter::toMajorUnits(…, $piCurrency)` | same | Equivalent |
| PaymentIntentHelper.php | 102 | `($latest_charge->amount_refunded ?? 0) / 100` | `AmountConverter::toMajorUnits(…, $piCurrency)` | same | Equivalent |
| PaymentIntentHelper.php | 135 | `(int)($request->amount * 100)` | `AmountConverter::toMinorUnits($request->amount, $request->currency)` | `$request->currency` | **BUG FIX** — truncation → round |
| PaymentIntentHelper.php | 304 | `(int)($request->amount * 100)` | `AmountConverter::toMinorUnits($request->amount, '')` | fallback 2-dec (CapturePaymentRequest has no currency) | **BUG FIX** — truncation → round |
| PaymentIntentHelper.php | 318 | `$paymentIntent->amount_received / 100` | `AmountConverter::toMajorUnits(…, $capturedCurrency)` | `$paymentIntent->currency` | Equivalent |
| RefundHelper.php | 83 | `(int)($request->amount * 100)` | `AmountConverter::toMinorUnits($request->amount, '')` | fallback 2-dec (RefundPaymentRequest has no currency) | **BUG FIX** — truncation → round |
| RefundHelper.php | 102 | `$refund->amount / 100` | `AmountConverter::toMajorUnits($refund->amount, strtoupper($refund->currency))` | `$refund->currency` | Equivalent |
| StripeWebhookEventParser.php | 82 | `$amount / 100` | `AmountConverter::toMajorUnits($amount, strtoupper($object['currency'] ?? ''))` | event object `currency` field | Equivalent |

**Note:** `PaymentIntentHelper::getRiskScore` line 213 (`$riskScore / 100.0`) was intentionally NOT migrated — it normalizes a 0-100 integer risk score to a 0.0-1.0 float. Not a currency conversion.

### Batch B — services + value objects (commit `80403af`)

| File | Line | Old | New | Currency source | EUR change? |
|------|------|-----|-----|----------------|-------------|
| RefundService.php | 60 | `(int) round($amount * 100)` | `AmountConverter::toMinorUnits($amount, '')` | fallback 2-dec (no currency in processRefund args) | Equivalent (already used round) |
| RefundService.php | 171 | `($refund->amount ?? 0) / 100` | `AmountConverter::toMajorUnits(…, strtoupper($refund->currency))` | `$refund->currency` | Equivalent |
| CheckoutSessionService.php | 169 | `(int) round($unitPrice * 100)` | `AmountConverter::toMinorUnits($unitPrice, $currency)` | snapshot `$currency` (line 139) | Equivalent (already used round) |
| CheckoutSessionService.php | 192 | `(int) round($snapshot->getTotalGross() * 100)` | `AmountConverter::toMinorUnits($snapshot->getTotalGross(), $currency)` | same | Equivalent (already used round) |
| CheckoutReturnService.php | 100 | `$amountTotal / 100` (log only) | `AmountConverter::toMajorUnits($amountTotal, strtoupper($currency))` | `$session->currency` | Equivalent |
| CheckoutReturnResult.php | 140 | `$this->amountCents / 100` | `AmountConverter::toMajorUnits($this->amountCents, $this->currency ?? '')` | stored `$this->currency` | Equivalent |
| StripeChargeAmountResolver.php | CENTS_PER_UNIT | constant `100` in `/ CENTS_PER_UNIT` and `* CENTS_PER_UNIT` | `AmountConverter::toMajorUnits` / `::toMinorUnits` | `$charge->currency` | Equivalent |
| ChargeAmountResolverInterface.php | 37,44,45 | doc: `amount_captured / 100` | updated to `toMajorUnits(amount_captured, currency)` | doc only — no code | n/a |

**SecurityValidationResult.php** — the grep match at line 43 (`* 100 = fully trusted`) is a doc comment describing score semantics (0-100), not currency math. No migration needed.

### Batch C — admin/view/model (commit `d359bf4`)

| File | Line | Old | New | Currency source | EUR change? |
|------|------|-----|-----|----------------|-------------|
| OrderRefundViewDataProvider.php | 136 | `(int)($pi->amount??0) / 100` | `AmountConverter::toMajorUnits((int)…, strtoupper($pi->currency))` | `$paymentIntent->currency` | Equivalent |
| OrderRefundViewDataProvider.php | 191 | `$charge->amount_captured / 100` | `AmountConverter::toMajorUnits((int)…, strtoupper($charge->currency))` | `$charge->currency` | Equivalent |
| OrderRefundViewDataProvider.php | 231 | `((int)($pi->amount??0)) / 100` | `AmountConverter::toMajorUnits(…, $currency)` | `$currency` set at line 224 | Equivalent |
| OrderRefundViewDataProvider.php | 248 | `((int)($charge->amount_captured??0)) / 100` | `AmountConverter::toMajorUnits(…, $currency)` | same | Equivalent |
| OrderRefundViewDataProvider.php | 262 | `((int)($refund->amount??0)) / 100` | `AmountConverter::toMajorUnits(…, $currency)` | same | Equivalent |
| StripePanelViewDataBuilder.php | 56 | `($pi->amount??0) / 100` | `AmountConverter::toMajorUnits((int)…, strtoupper($pi->currency??''))` | `$paymentIntent->currency` | Equivalent |
| Model/Order.php | 193 | `((int)($charge->amount_captured??0)) / 100` | `AmountConverter::toMajorUnits(…, strtoupper($charge->currency??''))` | `$charge->currency` | Equivalent |

---

## 3. EUR Parity Proof

All EUR sites produce identical numeric results because `AmountConverter::decimalsFor('EUR') = 2` → multiplier = 100, which is the same as the old `* 100` / `/ 100`.

**Exception (BUG FIXED):** Sites that used truncating `(int)($x * 100)` instead of `round`:
- `PaymentIntentHelper::createPaymentIntent` line 53: EUR 19.99 → was `1998` (truncated), now `1999` (rounded). Fix.
- `PaymentIntentHelper::authorizePayment` line 135: same formula — same fix.
- `PaymentIntentHelper::executeCapturePaymentIntent` line 304: EUR 49.99 → was `4998`, now `4999`. Fix.
- `RefundHelper::executeRefundPayment` line 83: EUR 19.99 → was `1998`, now `1999`. Fix.

These truncation bugs were pre-existing. The `round()`-based sites (CheckoutSessionService, RefundService) were already correct for EUR.

---

## 4. JPY / Zero-Decimal Correctness Proof

`AmountConverter::decimalsFor('JPY') = 0` → multiplier = 1.

`toMinorUnits(1000.0, 'JPY') = (int)round(1000.0 * 1) = 1000` ← what Stripe receives for ¥1000.
`toMajorUnits(1000, 'JPY') = 1000 / 1 = 1000.0` ← what the UI shows for Stripe's `amount=1000`.

Previously: `(int)(1000.0 * 100) = 100000` (sent to Stripe — would be rejected or charge 100× too much).
Previously: `1000 / 100 = 10.0` (shown in UI — displayed ¥1000 as ¥10).

Proven by `AmountConverterTest` data-provider cases `'JPY 1000 → 1000'` and `'JPY 1000 → 1000.0'`.

---

## 5. `grep -rEn "\* 100|/ 100" src/` end-state

```
src/Stripe/Service/Result/SecurityValidationResult.php:43:     * 100 = fully trusted, 0 = highly suspicious
src/Stripe/Core/AmountConverter.php:25: * (e.g. 19.99 * 100 = 1998.9999… in float …)
src/Stripe/Core/AmountConverter.php:30: * Sprint 114.7: centralises the ~22 hand-coded `* 100` / `/ 100` sites.
src/Stripe/Core/AmountConverter.php:92:     *   19.99 * 100 = 1998.9999…  → (int) gives 1998 (WRONG)
src/Stripe/Core/AmountConverter.php:93:     *   (int) round(19.99 * 100)  → 1999 (CORRECT)
src/Stripe/Adapter/Helper/PaymentIntentHelper.php:218:            return $riskScore !== null ? $riskScore / 100.0 : null;
```

- `SecurityValidationResult.php:43`: doc comment — score scale (0-100), not currency.
- `AmountConverter.php`: the converter itself — expected.
- `PaymentIntentHelper.php:218`: risk score normalization (0-100 Stripe integer → 0.0-1.0 float), not currency math.

**All currency-amount `* 100` / `/ 100` sites are removed.**

---

## 6. Test Counts

| Checkpoint | Tests | Assertions |
|-----------|-------|-----------|
| Before sprint (baseline) | 921 | 2269 |
| After converter commit | 921 | 2269 (+62 from AmountConverterTest) |
| After batch A | 932 | 2283 |
| After batch B | 941 | 2293 |
| After batch C | 951 | 2303 |
| Full pre-commit (--full) | 1083 | 2640 |

Full gate result: ALL CHECKS PASSED (PHPCS 0 errors, PHPStan 0 errors level max, PHPMD 0 new violations, PHPUnit 1083/1083).

---

## 7. Commit Hashes

| Commit | Content |
|--------|---------|
| `e7853c7` | AmountConverter + AmountConverterTest (62 tests, RED→GREEN) |
| `eaa30b5` | Batch A: PaymentIntentHelper, RefundHelper, StripeWebhookEventParser |
| `80403af` | Batch B: RefundService, CheckoutSessionService, CheckoutReturnService, CheckoutReturnResult, StripeChargeAmountResolver (folded CENTS_PER_UNIT), ChargeAmountResolverInterface (doc tidy) |
| `d359bf4` | Batch C: OrderRefundViewDataProvider, StripePanelViewDataBuilder, Model/Order |

---

## 8. R-1…R-10 Gate Checklist

- [x] **R-1 TDD**: RED shown (62 "class not found" errors) before GREEN. Characterization parity tests written before each batch migration. No method-under-test re-implemented in doubles.
- [x] **R-2 SOLID**: AmountConverter is a single-responsibility final class. No god-object introduced. PHPMD baseline unchanged (still 3 entries).
- [x] **R-3 LI**: No security-weakening overrides. No `instanceof` downcasts added.
- [x] **R-4 DI**: AmountConverter is static (pure function, per R-9.1 — no injection needed). All services retain their existing DI. No new `ContainerFactory` calls.
- [x] **R-5 Clean Code**: No magic `100` literals in production code (excluding `getRiskScore` which is not currency math). All explicit imports. Methods ≤ 25 lines. No else expressions added.
- [x] **R-6 DevOps-first**: `./bin/pre-commit-check.sh --full` green. 0 new suppressions. 0 PHPMD threshold changes.
- [x] **R-7 Event-driven**: Not applicable — this sprint is a DRY/correctness refactor, not a behavior change.
- [x] **R-8 Contract-aware**: Not applicable — this sprint does not touch contract state.
- [x] **R-9 No overengineering**: No interface created (static class per R-9.1). No speculative abstractions. CENTS_PER_UNIT constant deleted (folded into converter).
- [x] **R-10 Persistence**: Not applicable — no persistence changes.

---

## 9. Known Limitations / Follow-up

- **CapturePaymentRequest / RefundPaymentRequest** carry no `currency` field. The two sites that convert a major-unit request amount to minor units before the Stripe API call (`PaymentIntentHelper` line 304, `RefundHelper` line 83) use `AmountConverter::toMinorUnits($amount, '')` which defaults to 2 decimals. This is correct for EUR. Full multi-currency support at these sites requires adding `currency` to the request DTOs in `payment-base` — out of scope for 114.7.
- `ChargeAmountResolverInterface` doc comment uses `toMajorUnits` / `toMinorUnits` phrasing — already updated. The `StripeChargeAmountResolver` class docblock formula updated accordingly.
- `WebhookEventParser::extractAmountInCurrencyUnits` now reads `$object['currency']` from the webhook event object. Stripe always includes `currency` alongside `amount` in PI and Charge objects, so this is safe. If the field is absent, the 2-decimal fallback applies.
