# Sprint 63b — H2: Remove Capture Mode Override

**Date:** 2026-02-23
**Branch:** `b-7.4.x-security-STRP-99`
**Finding:** H2 — Capture Mode Override from Request Parameter
**Severity:** HIGH | **CVSS:** 5.0
**Standard:** PCI DSS 6.5.4 (Insecure Direct Object Reference)

---

## Problem

`StripeOrderController.php:355-358` accepts a URL query parameter that overrides the merchant's configured capture strategy:

```php
$override = Registry::getRequest()->getRequestParameter('capture_mode_override');
if (is_string($override) && in_array($override, ['automatic', 'manual'], true)) {
    return $override;
}
```

Any unauthenticated user can:
- `?capture_mode_override=manual` — payment authorized but not captured, merchant doesn't get funds
- `?capture_mode_override=automatic` — funds captured without merchant review

---

## Core Requirements

- **TDD-first** — failing test, then implementation, then refactor
- **Clean code** — no else, early returns, explicit imports
- **PSR-12**, **PHPStan level max**, **PHPMD** clean
- **Never suppress static analysis warnings**
- Validation: `./bin/pre-commit-check.sh --full` must pass

---

## File Plan

| Action | File |
|--------|------|
| MODIFY | `src/Stripe/Controller/StripeOrderController.php` |
| CREATE | `tests/Unit/Stripe/Controller/StripeOrderControllerCaptureModeTest.php` |

---

## TDD Steps

### Step 1 — Tests (RED)

Write test class `StripeOrderControllerCaptureModeTest`:

```
testCaptureModeIgnoresRequestOverrideParameter()
  — Set request parameter 'capture_mode_override' = 'manual'
  — Configure module capture mode as 'automatic' via mock
  — Call the method that resolves capture mode
  — assertEquals('automatic', $result) — config wins, override ignored

testCaptureModeReturnsConfiguredValue()
  — No override parameter set
  — Configure module capture mode as 'manual'
  — assertEquals('manual', $result)

testCaptureModeReturnsAutomaticFromConfig()
  — Configure module capture mode as 'automatic'
  — assertEquals('automatic', $result)
```

### Step 2 — Implement (GREEN)

Remove lines 355-358 entirely. Simplify the capture mode method to:

```php
private function getCaptureMode(): string
{
    return $this->moduleConfig->getCaptureMode();
}
```

If the method is more complex (e.g., has a fallback default), keep the config-based logic but remove the request parameter override.

### Step 3 — Refactor

- `grep -r 'capture_mode_override' .` — confirm no references remain in:
  - PHP source files
  - JavaScript files
  - Twig templates
  - Test files (update/remove any tests that relied on this parameter)
- Verify no frontend code sends this parameter

---

## Acceptance Criteria

| Criterion | Check |
|-----------|-------|
| `capture_mode_override` request parameter has no effect | unit test |
| Capture mode always sourced from module configuration | unit test |
| No references to `capture_mode_override` in entire codebase | grep |
| No `getRequestParameter('capture_mode_override')` calls | code review |
| All existing tests pass | pre-commit |
| 0 PHPCS / PHPStan / PHPMD errors | pre-commit |

---

## Completion Checklist

- [ ] Tests written and RED
- [ ] Implementation done, tests GREEN
- [ ] Grep confirms no remaining `capture_mode_override` references
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] Sprint moved to `done/`
- [ ] Report created in `reports/`
- [ ] `status.md` updated
