# Sprint 63c — C5: Amount Validation Guards

**Date:** 2026-02-23
**Branch:** `b-7.4.x-security-STRP-99`
**Finding:** C5 — No Amount Validation for NaN/Infinity/Negative Values
**Severity:** CRITICAL | **CVSS:** 6.0
**Standard:** PCI DSS 6.5.1 (Input Validation), BSI Web Application Security
**Package:** payment-component (provider-agnostic)

---

## Problem

`BasketSnapshot::extractFloat()` (lines 102-126) casts values to float without guarding IEEE 754 special values:

```php
private static function extractFloat(array $data, string $key): float
{
    return (float) $value;  // Accepts NAN, INF, -INF, negative
}
```

**Why this is critical:**
- `NAN != NAN` is **always true** in PHP — all amount comparisons silently pass
- `NAN > 0` is **false**, `NAN < 0` is **false**, `NAN == 0` is **false** — validation bypassed
- Negative amounts could create credits or reverse charges
- Zero amounts could bypass payment entirely

Also affects `AbstractPaymentCaptureService` — capture amounts not validated.

---

## Core Requirements

- **TDD-first** — failing test, then implementation, then refactor
- **Clean code** — no else, early returns, explicit imports
- **PSR-12**, **PHPStan level max**, **PHPMD** clean
- **Never suppress static analysis warnings**
- Validation: `./bin/pre-commit-check.sh --full` must pass

---

## File Plan

All changes in **payment-component** (`source/extensions/payment-component/`):

| Action | File |
|--------|------|
| MODIFY | `src/Contract/BasketSnapshot.php` — validate in `extractFloat()` |
| MODIFY | `src/Service/AbstractPaymentCaptureService.php` — validate capture amount |
| CREATE | `tests/Unit/Contract/BasketSnapshotAmountValidationTest.php` |
| CREATE | `tests/Unit/Service/CaptureServiceAmountValidationTest.php` |

---

## TDD Steps

### Part A: BasketSnapshot Validation

#### Step 1 — Tests (RED)

Write `BasketSnapshotAmountValidationTest`:

```
testFromArrayRejectsNaNTotalGross()
  — BasketSnapshot::fromArray(['totalGross' => NAN, 'totalNet' => 10.0, 'totalVat' => 1.9, 'currency' => 'EUR', 'items' => []])
  — expectException(InvalidArgumentException::class)
  — expectExceptionMessage('totalGross')

testFromArrayRejectsPositiveInfinityTotalGross()
  — BasketSnapshot::fromArray(['totalGross' => INF, ...])
  — expectException(InvalidArgumentException::class)

testFromArrayRejectsNegativeInfinityTotalNet()
  — BasketSnapshot::fromArray(['totalGross' => 10.0, 'totalNet' => -INF, ...])
  — expectException(InvalidArgumentException::class)
  — expectExceptionMessage('totalNet')

testFromArrayRejectsNegativeTotalGross()
  — BasketSnapshot::fromArray(['totalGross' => -10.0, ...])
  — expectException(InvalidArgumentException::class)

testFromArrayRejectsNegativeStringAmount()
  — BasketSnapshot::fromArray(['totalGross' => '-5.50', ...])
  — expectException(InvalidArgumentException::class)

testFromArrayRejectsNegativeTotalVat()
  — BasketSnapshot::fromArray(['totalGross' => 10.0, 'totalNet' => 8.1, 'totalVat' => -1.9, ...])
  — expectException(InvalidArgumentException::class)
  — expectExceptionMessage('totalVat')

testFromArrayAcceptsZeroTotalGross()
  — BasketSnapshot::fromArray(['totalGross' => 0.0, 'totalNet' => 0.0, 'totalVat' => 0.0, 'currency' => 'EUR', 'items' => []])
  — No exception — zero is valid (free items)
  — assertEquals(0.0, $snapshot->getTotalGross())

testFromArrayAcceptsValidPositiveAmounts()
  — BasketSnapshot::fromArray(['totalGross' => 99.99, 'totalNet' => 84.03, 'totalVat' => 15.96, 'currency' => 'EUR', 'items' => []])
  — No exception
  — assertEquals(99.99, $snapshot->getTotalGross())

testFromArrayAcceptsStringAmounts()
  — BasketSnapshot::fromArray(['totalGross' => '42.50', ...])
  — No exception
  — assertEquals(42.50, $snapshot->getTotalGross())
```

#### Step 2 — Implement (GREEN)

In `BasketSnapshot::extractFloat()`:

```php
private static function extractFloat(array $data, string $key): float
{
    if (!isset($data[$key])) {
        throw new \InvalidArgumentException("Missing required field: $key");
    }

    $value = (float) $data[$key];

    if (!is_finite($value)) {
        throw new \InvalidArgumentException(
            "Invalid amount for $key: must be a finite number"
        );
    }

    if ($value < 0) {
        throw new \InvalidArgumentException(
            "Invalid amount for $key: must be non-negative"
        );
    }

    return $value;
}
```

Tests GREEN.

### Part B: Capture Service Validation

#### Step 3 — Tests (RED)

Write `CaptureServiceAmountValidationTest`:

```
testCaptureRejectsNaNAmount()
  — Create testable capture service subclass (or use reflection)
  — Call capture/validateAmount with NAN
  — expectException(InvalidArgumentException::class)

testCaptureRejectsNegativeAmount()
  — Call with -100.0
  — expectException(InvalidArgumentException::class)

testCaptureRejectsZeroAmount()
  — Call with 0.0
  — expectException(InvalidArgumentException::class)
  — (capture of zero makes no sense, unlike basket snapshot)

testCaptureRejectsPositiveInfinity()
  — Call with INF
  — expectException(InvalidArgumentException::class)

testCaptureAcceptsValidPositiveAmount()
  — Call with 50.00
  — No exception
```

#### Step 4 — Implement (GREEN)

Add validation at the entry point of `AbstractPaymentCaptureService::capture()`:

```php
private function validateCaptureAmount(float $amount): void
{
    if (!is_finite($amount) || $amount <= 0) {
        throw new \InvalidArgumentException(
            'Capture amount must be a positive finite number'
        );
    }
}
```

Call `$this->validateCaptureAmount($amount)` at the start of `capture()`.

### Step 5 — Refactor

- Check if `AbstractPaymentRefundService` also needs the same validation (it will be addressed properly in Sprint 64/C4, but basic `is_finite` guard can be added now)
- Verify no other `extractFloat` or `(float)` casts accept unchecked values

---

## Acceptance Criteria

| Criterion | Check |
|-----------|-------|
| NAN amount throws InvalidArgumentException in BasketSnapshot | unit test |
| INF/-INF amount throws InvalidArgumentException in BasketSnapshot | unit test |
| Negative amount throws InvalidArgumentException in BasketSnapshot | unit test |
| Zero amount accepted for BasketSnapshot | unit test |
| Valid positive amounts work unchanged | unit test |
| NAN/INF/negative/zero rejected in capture service | unit test |
| Valid positive capture amount works | unit test |
| No IEEE 754 special values can reach Stripe API | code review |
| All existing tests pass (no regression) | pre-commit |
| 0 PHPCS / PHPStan / PHPMD errors | pre-commit |

---

## Completion Checklist

- [ ] BasketSnapshot tests written and RED
- [ ] BasketSnapshot validation implemented, tests GREEN
- [ ] Capture service tests written and RED
- [ ] Capture service validation implemented, tests GREEN
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] Sprint moved to `done/`
- [ ] Report created in `reports/`
- [ ] `status.md` updated
