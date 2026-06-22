# Code Review — Floating-Point & Monetary Arithmetic (payment-base + stripe)

**Date:** 2026-06-22
**Scope:** `extensions/payment-base/src` and `extensions/stripe/src` (production code only; `vendor/` excluded)
**Reviewer:** Claude (Opus 4.8)
**Subject:** All monetary/floating-point math operations, their test coverage, extraction opportunities, and the case for BCMath.

---

## 1. Executive Summary

Money in this codebase is represented two different ways, and the quality of the math tracks that split:

| Domain | Representation | Math hygiene |
|--------|----------------|--------------|
| **Stripe wire amounts** | **integer minor units** (cents) | **Good** — centralised in `AmountConverter`, integer arithmetic, well tested |
| **OXID shop amounts** | **PHP `float`** (major units, e.g. `19.99`) | **Mixed** — partly extracted & tested (`PerLineVatCalculator`, `CaptureRefundTracker`), partly inline & untested (`ContractService`, MCP formatters) |

**Headline findings:**

1. **No BCMath anywhere.** Every non-Stripe amount uses native IEEE-754 `float`. This is mitigated — but not eliminated — by `round()` and `0.005` epsilon tolerances. See §6 for whether that is acceptable.
2. **Two excellent reference implementations exist** and should be the template for everything else:
   - `AmountConverter` (stripe) — pure static utility, 13 direct tests + 3 batch characterization suites.
   - `PerLineVatCalculator` (payment-base) — pure, injectable, 8 unit + 9 big-number + 5 integration tests including a deliberate over-collection characterization matrix.
3. **Three inline math sites are untested and should be extracted** (§5): `ContractService::extractProductItems()` line-item totals, and the duplicated `toMinorUnits()` + `unitPrice × quantity` in the two MCP formatters.
4. **Epsilon constants are duplicated** (`0.005` appears in `CaptureRefundTracker`, `RefundIntentHandler`, and `CaptureService`) with no shared definition — a refactor/extraction target.

---

## 2. Inventory — payment-base

### 2.1 `src/Math/Vat/` — the gold-standard extracted math ✅

| File | Line | Operation | Type |
|------|------|-----------|------|
| `PerLineVatCalculator.php` | 31 | `amount * rate / 100` (net) | float ×, ÷ |
| | 32 | `amount * rate / (100 + rate)` (gross) | float ×, ÷ |
| | 33 | `round($vat, $precision)` | round |
| | 35 | `($vatByRate[$key] ?? 0.0) + $vat` | float + (accumulate per rate) |
| `VatBreakdown.php` | 31 | `array_sum($vatByRate)` | float sum |
| | 37 | `array_map('floatval', …)` | cast |
| `TaxableLine.php` | 18-19 | holds `float $amount`, `float $vatRatePercent` | VO, no math |

This is the only math in the codebase that is **pure, injectable behind an interface, and exhaustively characterized** — including the precision/over-collection edge cases (`PerLineVatCalculatorBigNumberTest` pins `0.0001%` rate over-collecting to a full cent per line, and the per-line-vs-grouped divergence that justifies STRP-157). It is the model the rest of the codebase should follow.

### 2.2 Capture / refund tracking — extracted & well tested ✅

| File | Line | Operation | Type |
|------|------|-----------|------|
| `Contract/CaptureRefundTracker.php` | 30 | `const FULL_REFUND_EPSILON = 0.005` | epsilon |
| | 90 | `($this->refundedAmount ?? 0.0) + $amount` | float + |
| | 121 | `$capturedAmount - $refunded` | float − |
| | 122 | `$remaining < FULL_REFUND_EPSILON` | epsilon compare |
| | 141 | `$refunded >= ($capturedAmount - FULL_REFUND_EPSILON)` | epsilon compare |
| `EventSystem/Handler/RefundIntentHandler.php` | 61 | `const FULL_SUM_EPSILON = 0.005` | epsilon |
| | 164 | `roundCurrency($authorized - $refund)` | float −, round |
| | 189 | `abs($requested - $authorized) < FULL_SUM_EPSILON` | epsilon compare |
| | 194 | `$requested <= ($authorized + FULL_SUM_EPSILON)` | epsilon compare |
| | 199 | `round($amount, 2)` | round |
| `Service/AbstractPaymentRefundService.php` | 160 | `$totalCaptured - $alreadyRefunded` | float − |
| | 69-70 | `$alreadyRefunded + $refundAmount`, then `− totalCaptured` | float +, − |

These are mostly **pure static/private methods** (`isFullAmount`, `isPositiveAndWithinAuthorized`, `roundCurrency`, `getRemainingRefundableAmount`, `isFullyRefunded`) and are covered: `CaptureRefundTrackerTest` (34 tests), `RefundIntentHandlerTest` (17 tests).

### 2.3 Inline, **untested** math ⚠️

| File | Line | Operation | Issue |
|------|------|-----------|-------|
| `Service/ContractService.php` | 153 | `'totalPrice' => $unitPrice * $amount` | float × inside array literal, **no test** |
| | 154 | `'netPrice' => $netPrice * $amount` | float × inside array literal, **no test** |
| | 155 | `'vatValue' => $vatValue * $amount` | float × inside array literal, **no test** |
| `Mcp/Acp/AcpResponseFormatter.php` | 103 | `$grossPrice = (float) $item['unitPrice'] * $quantity` | float ×, no rounding, **no test** |
| | 141 | `toMinorUnits()` = `(int) round($amount * 100)` | **hardcoded ×100** (ignores currency), duplicated |
| `Mcp/Ucp/UcpResponseFormatter.php` | 66 | `grossPrice * quantity` then `toMinorUnits()` | float ×, **no test** |
| | 75 | `toMinorUnits()` = `(int) round($amount * 100)` | **hardcoded ×100**, duplicated |

> **Note:** the two MCP `toMinorUnits()` helpers re-implement (with a hardcoded `* 100`) what stripe's `AmountConverter::toMinorUnits()` already does currency-aware. They will produce wrong amounts for JPY (0-decimal) and BHD (3-decimal). They are also un-deduplicated copies of each other.

### 2.4 Casts / non-monetary (low risk)

- `Eshop/Core/PriceToTaxableLineMapper.php:22` — `(float)` cast of OXID `Price` into a `TaxableLine` (pure mapper).
- `Repository/DoctrineTransactionRepository.php:132,228` — `(float)` cast of DB `SUM(OXAMOUNT)` / row.
- `Contract/BasketSnapshot.php:140` — `(float)` cast + `is_finite()` / `>= 0` validation.
- `Validation/Guard/RateLimitGuard.php:58` — `(int) floor(time() / TTL)` (time bucketing, **not money**).

---

## 3. Inventory — stripe

### 3.1 `src/Stripe/Core/AmountConverter.php` — centralised converter ✅

The single source of truth for major↔minor conversion. Currency-aware (`decimalsFor()` handles 0/2/3-decimal currencies), and crucially uses `(int) round($major * $multiplier)` — not truncation — to defeat the classic `19.99 * 100 = 1998.9999…` drift.

| Line | Operation |
|------|-----------|
| 100 | `$multiplier = 10 ** decimalsFor($currency)` |
| 102 | `(int) round($major * $multiplier)` — major → minor |
| 113-115 | `$minor / $divisor` — minor → major |

**Tests:** `AmountConverterTest` (13), plus three "batch characterization" suites (A/B/C) that pin the ~22 original call sites this utility replaced. This is the second gold-standard.

### 3.2 `StripeChargeAmountResolver` — partial-capture formula ✅

Pure, documented, integer-on-minor-units arithmetic:

| Line | Operation |
|------|-----------|
| 40 | `$releaseCents = max(0, $charge->amount - $charge->amountCaptured)` |
| 41 | `$customerCents = max(0, $charge->amountRefunded - $releaseCents)` |
| 49 | `toMinorUnits(customerRefundedAmount(...))` round-trip |
| 51 | `max(0.0, toMajorUnits($amountCaptured - $customerCents))` (double `max(0,…)` clamps `−0.0` drift) |

**Tests:** `StripeChargeAmountResolverTest` (6) + `…DtoCharacterizationTest`.

### 3.3 Other Stripe math — uses the converter, mostly thin

All `* 100` / `/ 100` sites route through `AmountConverter`:
`RefundService:73,184`, `CheckoutSessionService:175,195-196,207,247`, `PaymentIntentHelper:55,100-107,141`, `RefundHelper:89,108`, `CheckoutReturnService:103`, `CheckoutReturnResult:145`, `StripeTransactionHistoryBuilder:45,60,71`, `Model/Order.php:197,225`, `StripeWebhookEventParser:95`, `OrderRefundViewDataProvider:149,206`.

Notable non-converter arithmetic:

| File | Line | Operation | Note |
|------|------|-----------|------|
| `Service/CaptureService.php` | 43 | `const AMOUNT_EPSILON = 0.005` | **3rd copy** of the epsilon |
| | 73 | `getAmount() - (getCapturedAmount() ?? 0.0)` | float − |
| | 74 | `$amount > $remaining + AMOUNT_EPSILON` | epsilon compare |
| `CheckoutSessionService.php` | 195-196 | `toMinorUnits(unitPrice) * quantity` | **int × int on cents** — correct (multiply after converting) |
| `PaymentIntentHelper.php` | 219 | `$riskScore / 100.0` | risk score 0–100 → 0–1 (not money) |
| `StripeRadarFraudCheckService.php` | 63 | `$riskScore >= $threshold` | float compare (not money) |
| `Admin/AdminAmountValidator.php` | 45-54 | parse `,`→`.`, compare in **minor units** | good — avoids float-boundary drift |
| `Admin/StripePanelViewDataBuilder.php` | 76-84 | `number_format(toMajorUnits(...), 2, '.', '')` | display only |

`CheckoutSessionService:195` is worth highlighting as a *correct* pattern: it converts the unit price to integer cents **first**, then multiplies by the integer quantity — so the product is exact integer arithmetic, never `float × int`.

---

## 4. Test-coverage map of the math

| Math unit | Extracted? | Tests | Verdict |
|-----------|-----------|-------|---------|
| `PerLineVatCalculator` (pb) | ✅ interface | 8 + 9 (big-number) + 5 (integration) | **Excellent** |
| `VatBreakdown` / `TaxableLine` (pb) | ✅ VO | 4 + 3 | Good |
| `CaptureRefundTracker` (pb) | ✅ methods | 34 | **Excellent** |
| `RefundIntentHandler` (pb) | ✅ static | 17 | **Excellent** |
| `AbstractPaymentRefundService` calc (pb) | partial | via `RefundServiceTest` (22) | Adequate |
| `AmountConverter` (stripe) | ✅ static | 13 + 3 batch suites | **Excellent** |
| `StripeChargeAmountResolver` (stripe) | ✅ | 6 + DTO charac. | **Excellent** |
| `CaptureService` epsilon/remaining (stripe) | inline in service | 25 (service-level) | Adequate (not unit-isolated) |
| **`ContractService` line-item totals (pb)** | ❌ inline | **0** | **Gap** |
| **MCP `toMinorUnits` + `× quantity` (pb)** | ❌ inline, duplicated | **0** | **Gap** |

---

## 5. Extraction opportunities (where math should become a tested function)

### 5.1 `ContractService::extractProductItems()` — extract a `LineItemAmounts` calculator (HIGH)

Lines 153-155 compute `unitPrice × qty`, `netPrice × qty`, `vatValue × qty` inline inside an array literal, mixed with object introspection (`getId()`, title extraction). This is the data that becomes the contract's `BasketSnapshot` and ultimately the Stripe line items — wrong math here is a PSP amount-mismatch reject. Extract:

```php
final readonly class LineItemAmount
{
    public function __construct(
        public float $totalPrice,
        public float $netPrice,
        public float $vatValue,
    ) {}

    public static function forQuantity(float $unit, float $net, float $vat, int $qty): self
    {
        return new self($unit * $qty, $net * $qty, $vat * $qty);
    }
}
```

Then unit-test quantity edge cases (0, large quantities, fractional units) without booting OXID. Mirrors the `PerLineVatCalculator` pattern already proven in the same module.

### 5.2 Replace both MCP `toMinorUnits()` with a shared converter (HIGH)

`AcpResponseFormatter:141` and `UcpResponseFormatter:75` are byte-identical `(int) round($amount * 100)` helpers — duplicated, hardcoded to 2 decimals, and untested. payment-base needs a currency-aware converter equivalent to stripe's `AmountConverter`. Options:

- **Promote `AmountConverter` to payment-base** (`OxidEsales\PaymentBase\Math\Money\MinorUnitConverter`) as a pure static utility, and have stripe's `AmountConverter` extend/delegate to it. This removes the cross-module duplication *and* the MCP-formatter bug for 0/3-decimal currencies in one move.
- At minimum, deduplicate the two MCP copies into one shared formatter helper with tests.

This is the cleanest single win: it deletes duplicated code, fixes a latent JPY/BHD bug, and creates a tested seam — all consistent with the "static for pure utilities" principle already used for `AmountConverter`.

### 5.3 Unify the `0.005` epsilon (MEDIUM)

`FULL_REFUND_EPSILON` (CaptureRefundTracker), `FULL_SUM_EPSILON` (RefundIntentHandler), and `AMOUNT_EPSILON` (stripe CaptureService) are three private copies of the same half-cent constant with the same intent. Define it once (e.g. `Money::HALF_CENT_EPSILON` in payment-base) and reference it. A single definition is also the natural home for a `Money::equals()/lessThan()` comparison helper, which is currently re-derived at each compare site.

### 5.4 Extract `CaptureService` remaining-capturable math (LOW)

`CaptureService:73-74` computes `remaining = amount − capturedAmount` and the epsilon over-capture check inline. It is covered at service level (25 tests) but not as an isolated pure function; extracting a `CapturableAmount` helper would let the boundary conditions be tested without the surrounding service mocks — and would share the epsilon from §5.3.

---

## 6. BCMath in PHP — should this code use it?

### 6.1 The problem with `float`

PHP `float` is IEEE-754 double-precision binary. Decimal fractions that look exact in base-10 are **not representable** in base-2:

```php
0.1 + 0.2 === 0.3;           // false  → 0.30000000000000004
19.99 * 100;                 // 1998.9999999999998, not 1999
floor((0.1 + 0.7) * 10);     // 7, not 8
var_dump(0.58 - 0.57);       // float(0.009999999999999964)
```

For money this manifests as: cent-off totals, `==` comparisons that fail, `(int)` truncation losing a cent, and accumulation drift over many line items. The codebase currently fights this with two tools — `round(…, 2)` and `± 0.005` epsilon comparisons — which **work but are defensive patches**, not a fix. They require every author to remember to apply them, and they encode an implicit "2-decimal" assumption that breaks for 3-decimal currencies.

### 6.2 What BCMath provides

BCMath (Binary Calculator) is a PHP extension for **arbitrary-precision decimal arithmetic**. It operates on **numbers-as-strings**, so there is no binary-rounding error — the math is exact in base-10 to a configurable scale.

```php
bcadd('0.1', '0.2', 2);          // '0.30'   (exact)
bcmul('19.99', '100', 2);        // '1999.00'
bcsub('100.00', '99.99', 2);     // '0.01'
bccomp('0.57', '0.58', 2);       // -1   (exact comparison, no epsilon needed)
bcdiv('10', '3', 4);             // '3.3333'
```

Core functions: `bcadd`, `bcsub`, `bcmul`, `bcdiv`, `bcmod`, `bcpow`, `bccomp` (returns -1/0/1), `bcscale()` (set default scale). Each takes an explicit `$scale` (number of fractional digits to keep) — and **truncates, does not round**, beyond it, which the caller must account for. PHP 8.4 adds `BcMath\Number`, an object wrapper with operator overloading and proper rounding modes.

**Availability:** `ext-bcmath` is **already loaded in this project's PHP container** (verified `extension_loaded('bcmath') === true`). No infra change needed to adopt it. (Note: it is *not* bundled in core PHP by default, so any hard dependency must be declared as `ext-bcmath` in `composer.json` and documented.)

### 6.3 Trade-offs

| Aspect | `float` + round/epsilon (current) | BCMath |
|--------|-----------------------------------|--------|
| Correctness | approximate; relies on discipline | exact to scale |
| Comparison | needs `abs(a-b) < ε` | `bccomp($a,$b,$scale) === 0` |
| Performance | fast (CPU native) | ~slower (string ops) — negligible at checkout volumes |
| Ergonomics | natural operators `+ - * /` | verbose function calls; PHP <8.4 has no operators |
| Type plumbing | `float` everywhere | strings everywhere; cast discipline at boundaries |
| Rounding | `round()` half-away-from-zero | manual (`bcadd($n,'0', $scale)` truncates) |

### 6.4 Recommendation

This module already has a **better answer than BCMath for the Stripe path**: integer minor units. `AmountConverter` + integer cents arithmetic (`StripeChargeAmountResolver`, `CheckoutSessionService:195`) is exact, fast, and idiomatic — **do not convert that to BCMath**. Integer-cent math is the industry-standard representation for payment amounts and should remain the canonical model for anything that crosses the Stripe boundary.

BCMath is worth considering specifically for the **OXID-float VAT/price domain** where:
- amounts arrive as floats from OXID `Price` objects, and
- per-line accumulation + division by `(100 + rate)` is exactly the irrational-division case (`PerLineVatCalculator:32`) where float drift is largest.

However, `PerLineVatCalculator` is already correct *by contract* (it characterizes its own `round(half-away-from-zero)` behaviour and the over-collection is a documented, intentional consequence of per-line 2dp rounding, not a float bug). Rewriting it in BCMath would change its rounding semantics and break the pinned expected values for no correctness gain.

**Net recommendation, in priority order:**
1. **Keep integer minor units** for everything Stripe-facing (already done — protect it).
2. **Do the extractions in §5** (line-item calculator, shared currency-aware minor-unit converter, unified epsilon). These deliver most of the safety benefit — tested, single-responsibility math seams — without a representation change.
3. **Defer a BCMath migration** unless a concrete decimal-precision defect is observed in the OXID-float VAT path. If adopted, scope it to a single `Money` value object (string-backed, fixed scale, BCMath internally) behind the seams created in §5 — never sprinkle raw `bc*` calls across services. Declare `ext-bcmath` in `composer.json` at that point.

In short: the codebase's instinct (integer cents for the wire, `round`+epsilon for the shop floats) is sound. The actionable work is **extraction and de-duplication of the float math into tested units**, not a wholesale BCMath rewrite.

---

## 7. Action items

| # | Action | Priority | Effort | Status |
|---|--------|----------|--------|--------|
| 1 | Extract `ContractService` line-item totals into a tested `LineItemAmount` calculator | High | S | ✅ Done — `payment-base/src/Math/Money/LineItemAmount.php` (9 tests) |
| 2 | Promote a currency-aware minor-unit converter to payment-base; delete both MCP `toMinorUnits()` copies (fixes JPY/BHD bug) | High | M | ✅ Done — `MinorUnitConverter`; MCP formatters + stripe `AmountConverter` now delegate (9 tests) |
| 3 | Unify the three `0.005` epsilon constants + add a `Money::equals/lessThan` helper | Medium | S | ✅ Done — `Money` (`HALF_CENT_EPSILON` + `equals/greaterThan/atLeast/atMost`), 3 call sites migrated (20 tests) |
| 4 | Extract `CaptureService` remaining-capturable math into a pure, unit-tested helper | Low | S | ✅ Done — `stripe/src/Stripe/Service/CapturableAmount.php` (13 tests) |
| 5 | Add unit tests for the extracted units in #1–#4 (TDD: characterize current behaviour first) | High | M | ✅ Done — see per-item counts above |
| 6 | (Conditional) Introduce a string/BCMath-backed `Money` VO **only** if a decimal defect surfaces in the OXID-float path | Deferred | L | ⏸ Deferred (no defect observed) |

**Implementation note (2026-06-22):** §5.1–§5.4 implemented TDD, behaviour-preserving. Gates after
the change: payment-base Unit 1097 pass · stripe Unit 1296 pass · PHPCS 0 · PHPStan 0 (max) · PHPMD 0
new. stripe `AmountConverter` delegates to payment-base `MinorUnitConverter`, so the currency lists
now live in exactly one place. The original report (§1–§6) reflects the pre-change state.

---

*Generated by code review on 2026-06-22; §5 action items implemented the same day.*
