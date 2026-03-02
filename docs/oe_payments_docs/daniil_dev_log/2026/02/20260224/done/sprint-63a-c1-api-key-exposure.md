# Sprint 63a — C1: Remove API Key Exposure

**Date:** 2026-02-23
**Branch:** `b-7.4.x-security-STRP-99`
**Finding:** C1 — API Key Prefixes Exposed in JSON Response and Logs
**Severity:** CRITICAL | **CVSS:** 7.5
**Standard:** PCI DSS 3.5.1, BSI TR-03116

---

## Problem

`StripeOrderController::createCheckoutSession()` (lines 134-157):

1. Creates `$secretKeyPrefix = substr($config->getToken(), 0, 12) . '...'` — 12 chars of SECRET key
2. Logs key info via `Registry::getLogger()->info()` (lines 138-145)
3. Returns `_debug` block in JSON response to the **browser**:

```php
echo json_encode([
    'id' => $context->get('checkoutSessionId'),
    'url' => $context->get('checkoutUrl'),
    '_debug' => [
        'pk_prefix' => substr($publishableKey, 0, 20),
        'sk_prefix' => $secretKeyPrefix,
        'testMode' => $config->isTestMode(),
        'keysValid' => $validator->validateKeyPair(),
    ],
]);
```

**Impact:** Secret key prefix (`sk_test_`/`sk_live_` + 4 chars) sent to every checkout browser. Combined with test mode flag, narrows brute-force space. Logs may be accessible to operators without PCI clearance.

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
| CREATE | `tests/Unit/Stripe/Controller/StripeOrderControllerCheckoutResponseTest.php` |

---

## TDD Steps

### Step 1 — Tests (RED)

Write test class `StripeOrderControllerCheckoutResponseTest`:

```
testCreateCheckoutSessionResponseDoesNotContainDebugBlock()
  — Call createCheckoutSession() with mocked dependencies
  — Capture JSON output (ob_start/ob_get_clean or mock)
  — Decode JSON
  — assertArrayNotHasKey('_debug', $response)

testCreateCheckoutSessionResponseDoesNotContainKeyPrefixes()
  — Decode JSON response
  — Assert 'pk_prefix' not in response (any depth)
  — Assert 'sk_prefix' not in response (any depth)

testCreateCheckoutSessionResponseContainsRequiredFields()
  — Decode JSON response
  — assertArrayHasKey('id', $response)
  — assertArrayHasKey('url', $response)
  — Assert response has exactly 2 keys (no extras)
```

### Step 2 — Implement (GREEN)

In `StripeOrderController::createCheckoutSession()`:

1. **Remove** the `$secretKeyPrefix` variable assignment
2. **Remove** the `Registry::getLogger()->info()` call that logs key data
3. **Remove** the entire `_debug` block from the JSON response
4. Keep only:

```php
echo json_encode([
    'id' => $context->get('checkoutSessionId'),
    'url' => $context->get('checkoutUrl'),
]);
```

### Step 3 — Refactor

- `grep -r '_debug\|sk_prefix\|pk_prefix' src/` — confirm no references remain
- `grep -r 'secretKeyPrefix\|getToken.*substr' src/` — confirm no key truncation patterns
- Review remaining `Registry::getLogger()` calls in the controller for other key leaks

---

## Acceptance Criteria

| Criterion | Check |
|-----------|-------|
| JSON response contains no `_debug` key | unit test |
| No `sk_prefix` or `pk_prefix` in response | unit test |
| Response contains only `id` and `url` | unit test |
| No secret key logged via `Registry::getLogger()` | code review |
| No `$secretKeyPrefix` variable in controller | code review |
| All existing tests pass | pre-commit |
| 0 PHPCS / PHPStan / PHPMD errors | pre-commit |

---

## Completion Checklist

- [ ] Tests written and RED
- [ ] Implementation done, tests GREEN
- [ ] Grep confirms no remaining debug/key references
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] Sprint moved to `done/`
- [ ] Report created in `reports/`
- [ ] `status.md` updated
