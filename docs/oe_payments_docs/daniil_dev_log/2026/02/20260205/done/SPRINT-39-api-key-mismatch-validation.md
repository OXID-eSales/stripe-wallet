# Sprint 39: API Key Mismatch Validation in Admin UI

**Date:** 2026-02-05
**Status:** TODO
**Priority:** High (User Experience / Error Prevention)

---

## Core Requirements

All changes MUST follow:

| Requirement | Description |
|-------------|-------------|
| **TDD-First** | Write/update failing tests first, then implementation |
| **SOLID** | Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion |
| **DRY** | Don't Repeat Yourself - no duplicate code |
| **Clean Code** | Meaningful names, small functions (15-25 lines), no else expressions (use early returns) |
| **PSR-12** | PHP coding style standard |
| **Dependency Injection** | Depend on abstractions, not concretions |

**Testing Command:**
```bash
./bin/pre-commit-check.sh --full
```

---

## Objective

Add validation to detect and warn admins when API keys are from different Stripe accounts.

### Current Problem

Keys can be from different accounts:
- `pk_test_51NXKT4E...` → Account A
- `sk_test_51OyDwdA...` → Account B

This causes checkout failures but admin sees no warning.

### Solution

1. Add `stripeGetKeyValidationError()` method to `ModuleConfiguration.php`
2. Display warning banner in admin config template when keys mismatch
3. Add validation in `StripeConnect::stripeFinishOnBoarding()` with warning

---

## Files to Modify

### 1. `src/Stripe/Controller/Admin/ModuleConfiguration.php`

**Action:** Add method to expose key validation error to template

```php
/**
 * Get API key validation error message for template display
 *
 * @return string|null Error message or null if keys are valid
 */
public function stripeGetKeyValidationError(): ?string
{
    return $this->getModuleConfig()->getKeyValidationError();
}
```

---

### 2. `views/twig/extensions/themes/default/module_config.html.twig`

**Action:** Add warning banner at top of API key section

```twig
{% if module_var == 'sStripeTestToken' %}
    {% set keyError = oView.stripeGetKeyValidationError() %}
    {% if keyError %}
        <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
            <strong style="color: #856404;">⚠️ API Key Mismatch Warning:</strong>
            <p style="color: #856404; margin: 5px 0 0 0;">{{ keyError }}</p>
        </div>
    {% endif %}
{% endif %}
```

---

### 3. `src/Stripe/Controller/Admin/StripeConnect.php`

**Action:** Add validation logging when keys are saved

```php
public function stripeFinishOnBoarding()
{
    // ... existing code ...

    // After saving, validate and log warning if mismatch
    $config = $this->getModuleConfigService();
    $keyError = $config->getKeyValidationError();
    if ($keyError !== null) {
        Registry::getLogger()->warning('Stripe API key mismatch after onboarding', [
            'error' => $keyError,
            'mode' => $sMode,
        ]);
    }
}
```

---

### 4. `views/admin_twig/en/stripe_lang.php`

**Action:** Add translation for mismatch warning (if needed for custom message)

```php
'STRIPE_KEY_MISMATCH_WARNING' => 'API Key Mismatch Warning',
```

---

### 5. `views/admin_twig/de/stripe_lang.php`

**Action:** Add German translation

```php
'STRIPE_KEY_MISMATCH_WARNING' => 'API-Schlüssel Konflikt Warnung',
```

---

## TDD Approach

### Step 1: Write Unit Test for ModuleConfiguration

Create/update test for the new method:

```php
// tests/Unit/Stripe/Controller/Admin/ModuleConfigurationTest.php

public function testStripeGetKeyValidationErrorReturnsNullWhenKeysMatch(): void
{
    // Mock ModuleConfigurationService to return null (keys valid)
    $mockConfig = $this->createMock(ModuleConfigurationService::class);
    $mockConfig->method('getKeyValidationError')->willReturn(null);

    $controller = $this->createControllerWithMockedConfig($mockConfig);

    $this->assertNull($controller->stripeGetKeyValidationError());
}

public function testStripeGetKeyValidationErrorReturnsMessageWhenKeysMismatch(): void
{
    // Mock ModuleConfigurationService to return error message
    $mockConfig = $this->createMock(ModuleConfigurationService::class);
    $mockConfig->method('getKeyValidationError')
        ->willReturn('API keys appear to be from different Stripe accounts.');

    $controller = $this->createControllerWithMockedConfig($mockConfig);

    $this->assertStringContainsString(
        'different Stripe accounts',
        $controller->stripeGetKeyValidationError()
    );
}
```

### Step 2: Implement ModuleConfiguration Method

Add the `stripeGetKeyValidationError()` method.

### Step 3: Update Template

Add the warning banner to the template.

### Step 4: Update StripeConnect (Optional Logging)

Add validation logging after save.

### Step 5: Run Full Validation

```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

- [ ] Unit test for `stripeGetKeyValidationError()` written
- [ ] Tests pass with mocked valid keys (returns null)
- [ ] Tests pass with mocked mismatched keys (returns error message)
- [ ] `ModuleConfiguration::stripeGetKeyValidationError()` implemented
- [ ] Template shows warning banner when keys mismatch
- [ ] Template shows no banner when keys match
- [ ] StripeConnect logs warning on mismatch (optional)
- [ ] English translation added (if custom message)
- [ ] German translation added (if custom message)
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] PHPStan passes
- [ ] PHPCS passes
- [ ] PHPMD passes

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| False positives | Use existing `extractAccountId()` logic which is well-tested |
| Performance | Validation only runs on admin config page load, minimal impact |
| UI clutter | Warning only shows when there's an actual problem |

---

## Acceptance Criteria

1. Admin config page shows warning banner when API keys are from different accounts
2. Warning message explains the problem clearly
3. No warning shown when keys are from same account
4. Existing checkout validation continues to work
5. All tests pass with `./bin/pre-commit-check.sh --full`

---

## Expected UI Result

**When keys mismatch:**
```
┌──────────────────────────────────────────────────────────────┐
│ ⚠️ API Key Mismatch Warning:                                 │
│ API keys appear to be from different Stripe accounts.        │
│ Publishable key account: 51NXKT4E, Secret key account:       │
│ 51OyDwdA. Please ensure both keys are from the same          │
│ Stripe dashboard.                                            │
└──────────────────────────────────────────────────────────────┘

Test API Access Token: sk_test_51OyDwdA... [Connect with Stripe]
Test API Publishable Key: pk_test_51NXKT4E...  ← editable field
```

**When keys match:** No warning shown.

---

## Estimated Changes

| File | Lines Added |
|------|-------------|
| `ModuleConfiguration.php` | ~10 |
| `module_config.html.twig` | ~10 |
| `StripeConnect.php` | ~10 (optional) |
| `en/stripe_lang.php` | ~1 |
| `de/stripe_lang.php` | ~1 |
| Unit tests | ~30 |
| **Total** | **~60 lines** |
