# Sprint 68a — H5: State Machine Guard on fromArray()

**Date:** 2026-02-24
**Status:** DONE
**Finding:** H5 — State Machine Bypass via fromArray() (CVSS 5.0, HIGH)
**Package:** payment-component

## Problem

`PaymentContract::fromArray()` assigns state directly: `$contract->state = self::extractState($data)`. The audit flagged this as bypassing transition guards. After analysis, the actual risk is narrower:

1. `fromArray()` is for **DB hydration** — it MUST accept any valid state (DB is source of truth)
2. `ContractState::fromValue()` already validates against `VALID_STATES` — garbage strings rejected
3. The constructor `__construct(string $value)` checks `in_array($value, VALID_STATES, true)`

**Actual gaps found:**
- `fromValue('')` would hit the constructor with empty string → rejected, but error message was unclear ("Invalid contract state: " with nothing after colon)
- No detection of impossible state/condition combinations (e.g., `fulfilled` with unfulfilled conditions)

## Fix

### 1. Explicit empty-string guard in `ContractState::fromValue()`

```php
public static function fromValue(string $value): self
{
    if ($value === '') {
        throw new InvalidArgumentException('Invalid contract state: empty string');
    }
    return new self($value);
}
```

Defense in depth — the constructor already rejects empty strings, but the explicit check gives a clear error message and documents intent.

### 2. State/condition consistency warning in `PaymentContract::fromArray()`

Added `validateStateConsistency()` that detects impossible combinations and triggers `E_USER_WARNING`. Defensive, NOT blocking — the DB is authoritative.

```php
private static function validateStateConsistency(ContractState $state, array $conditions): void
{
    if (!$state->isFulfilled() || empty($conditions)) {
        return;
    }
    $unfulfilled = array_filter($conditions, fn(ContractCondition $c) => !$c->isFulfilled());
    if (!empty($unfulfilled)) {
        trigger_error(sprintf(
            'PaymentContract state/condition inconsistency: state=%s but %d conditions unfulfilled',
            $state->getValue(), count($unfulfilled)
        ), E_USER_WARNING);
    }
}
```

**Why warning, not exception?** Throwing would prevent loading existing contracts with data inconsistencies — causing a data-driven outage. Warnings alert developers without breaking the app.

## Files Modified (2)

- `payment-component/src/Contract/ContractState.php`
  - Added empty-string guard in `fromValue()` before delegating to constructor
- `payment-component/src/Contract/PaymentContract.php`
  - Added `validateStateConsistency()` private static method
  - Called from `fromArray()` after `extractConditions()`

## Files Created (1)

### Tests
- `payment-component/tests/Unit/Contract/PaymentContractFromArrayGuardTest.php`

## Test Results

```
Tests: 6, Assertions: 19, Failures: 0
```

| # | Test | Input | Expected |
|---|------|-------|----------|
| 1 | `fromArrayRejectsInvalidState` | `state='hacked'` | `InvalidArgumentException` |
| 2 | `fromArrayAcceptsAllValidStates` | All 10 valid states | No exception for any |
| 3 | `fromArrayRejectsEmptyState` | `state=''` | `InvalidArgumentException("empty string")` |
| 4 | `fromArrayRejectsNonStringState` | `state` key missing | `InvalidArgumentException("must be a string")` |
| 5 | `fromArrayPreservesStateWithConditions` | `fulfilled` + all conditions fulfilled | Valid, no warning |
| 6 | `fromArrayDetectsInconsistentStateConditions` | `fulfilled` + 1 unfulfilled condition | `E_USER_WARNING` triggered |

## SOLID Compliance

- **S**: `ContractState` validates state values; `PaymentContract` validates consistency
- **O**: Existing tests unaffected — no public API changes
- **L**: `fromValue()` contract unchanged — still returns `ContractState` or throws
- **I**: No new interfaces needed
- **D**: No new dependencies introduced
