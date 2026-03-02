# Sprint 38: Remove Dead API Key Fields

**Date:** 2026-02-05
**Status:** TODO
**Priority:** High (Dead Code Cleanup)

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

Remove unused API key fields `sStripeTestKey` and `sStripeLiveKey` from the codebase.

These fields are **DEAD CODE**:
- Defined in metadata.php but never used in any service or controller
- Displayed in admin UI but serve no purpose
- Confuse users with redundant "Private Key" fields

---

## Files to Modify

### 1. `metadata.php`

**Action:** DELETE lines 81-82

```php
// BEFORE:
['group' => 'STRIPE_GENERAL', 'name' => 'sStripeTestKey', 'type' => 'str', 'value' => '', 'position' => 32],
['group' => 'STRIPE_GENERAL', 'name' => 'sStripeLiveKey', 'type' => 'str', 'value' => '', 'position' => 33],

// AFTER:
// (lines removed)
```

---

### 2. `views/twig/extensions/themes/default/module_config.html.twig`

**Action:** DELETE lines 77-89 (the `sStripeTestKey`/`sStripeLiveKey` template block)

```twig
// BEFORE:
{% elseif module_var == 'sStripeTestKey' or module_var == 'sStripeLiveKey' %}
    <dl>
        <dt>
            <div>
                <input type="text" class="txt" style="width: 250px;" name="confstrs[{{ module_var }}]" value="{{ confstrs[module_var] }}" {{ readonly }}>
                {% include "inputhelp.html.twig" with {'sHelpId': help_id("HELP_SHOP_MODULE_" ~ module_var), 'sHelpText': help_text("HELP_SHOP_MODULE_" ~ module_var)} %}
            </div>
        </dt>
        <dd style="white-space: nowrap;">
            <span style="float:left;">{{ translate({ ident: "SHOP_MODULE_" ~ module_var }) }}</span>
        </dd>
        <div class="spacer"></div>
    </dl>

// AFTER:
// (block removed)
```

---

### 3. `views/admin_twig/en/stripe_lang.php`

**Action:** DELETE 4 translation entries

```php
// DELETE:
'SHOP_MODULE_sStripeTestKey'                        => 'Test API Private Key',
'SHOP_MODULE_sStripeLiveKey'                        => 'Live API Private  Key',
'HELP_SHOP_MODULE_sStripeTestKey'                   => 'Fill in your personal TEST private API key that will be used to set up the webhook endpoint.',
'HELP_SHOP_MODULE_sStripeLiveKey'                   => 'Fill in your personal LIVE private API key that will be used to set up the webhook endpoint.',
```

---

### 4. `views/admin_twig/de/stripe_lang.php`

**Action:** DELETE 4 translation entries

```php
// DELETE:
'SHOP_MODULE_sStripeTestKey'                        => 'Test API Private Key',
'SHOP_MODULE_sStripeLiveKey'                        => 'Live API Private  Key',
'HELP_SHOP_MODULE_sStripeTestKey'                   => 'Geben Sie Ihren persönlichen privaten TEST-API-Schlüssel ein, der zum Einrichten des Webhook-Endpunkts verwendet wird.',
'HELP_SHOP_MODULE_sStripeLiveKey'                   => 'Geben Sie Ihren persönlichen privaten LIVE-API-Schlüssel ein, der zum Einrichten des Webhook-Endpunkts verwendet wird.',
```

---

### 5. `recipe/var/configuration/shops/1/modules/oe_payments_stripe_wallet.yaml`

**Action:** DELETE settings entries

```yaml
# DELETE:
  sStripeTestKey:
    group: STRIPE_GENERAL
    type: str
    value: ''
  sStripeLiveKey:
    group: STRIPE_GENERAL
    type: str
    value: ''
```

---

### 6. `tests/Integration/Module/MetadataTest.php`

**Action:** DELETE test data entries for removed fields

```php
// DELETE from settings data provider:
'sStripeTestKey',
// ...
'sStripeLiveKey',
```

---

## TDD Approach

### Step 1: Update Tests First

1. Open `tests/Integration/Module/MetadataTest.php`
2. Remove `sStripeTestKey` and `sStripeLiveKey` from the expected settings list
3. Run tests - they should FAIL (fields still exist in metadata.php)

```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter MetadataTest extensions/stripe/tests/Integration/Module/MetadataTest.php
```

### Step 2: Remove from metadata.php

1. Delete the two settings entries
2. Run tests again - should now PASS

### Step 3: Remove from Template

1. Delete the template block for these fields
2. Verify no Twig errors

### Step 4: Remove Translations

1. Delete from English lang file
2. Delete from German lang file

### Step 5: Remove from Recipe

1. Delete from YAML configuration

### Step 6: Full Validation

```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

- [ ] Tests updated (TDD)
- [ ] `metadata.php` - settings removed
- [ ] `module_config.html.twig` - template block removed
- [ ] `en/stripe_lang.php` - translations removed
- [ ] `de/stripe_lang.php` - translations removed
- [ ] `oe_payments_stripe_wallet.yaml` - recipe updated
- [ ] `MetadataTest.php` - assertions removed
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] PHPStan passes
- [ ] PHPCS passes
- [ ] PHPMD passes
- [ ] Unit tests pass
- [ ] Integration tests pass

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Breaking existing shops | Fields are never read - no functionality depends on them |
| Data loss | Values were never used anyway |
| UI regression | Admin config page will be cleaner |

**Impact:** None - removing unused code only.

---

## Acceptance Criteria

1. `sStripeTestKey` and `sStripeLiveKey` do not exist in codebase
2. Admin configuration page shows 4 API key fields (not 6)
3. All tests pass with `./bin/pre-commit-check.sh --full`
4. No references to removed fields in any file

---

## Estimated Changes

| File | Lines Removed |
|------|---------------|
| `metadata.php` | 2 |
| `module_config.html.twig` | 13 |
| `en/stripe_lang.php` | 4 |
| `de/stripe_lang.php` | 4 |
| `oe_payments_stripe_wallet.yaml` | 8 |
| `MetadataTest.php` | ~6 |
| **Total** | **~37 lines** |
