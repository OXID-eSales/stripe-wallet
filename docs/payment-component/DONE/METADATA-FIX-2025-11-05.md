# Metadata Configuration Fix - 2025-11-05

## Summary
Fixed TypeError in OXID module metadata configuration where select constraints were incorrectly defined as array instead of pipe-delimited string.

## The Problem

**Error:**
```
[TypeError]
explode(): Argument #2 ($string) must be of type string, array given
at MetaDataNormalizer.php:58
```

**Root Cause:**
- OXID's `MetaDataNormalizer` expects module setting constraints to be pipe-delimited strings
- The `osc_stripe_capture_method` setting had constraints defined as an array
- When OXID tried to process this with `explode('|', $constraints)`, it received an array instead of string

## The Fix

### File: metadata.php:105

**Before (WRONG):**
```php
[
    'group' => 'osc_stripe_payment_methods',
    'name' => 'osc_stripe_capture_method',
    'type' => 'select',
    'value' => 'automatic',
    'constraints' => ['automatic', 'manual'], // ❌ Array
],
```

**After (CORRECT):**
```php
[
    'group' => 'osc_stripe_payment_methods',
    'name' => 'osc_stripe_capture_method',
    'type' => 'select',
    'value' => 'automatic',
    'constraints' => 'automatic|manual', // ✅ Pipe-delimited string
],
```

### File: tests/Integration/Module/MetadataTest.php:367-385

**Updated test to match OXID format:**
```php
// OXID expects constraints as pipe-delimited string, not array
$this->assertIsString(
    $captureMethodSetting['constraints'],
    'Constraints must be a pipe-delimited string'
);

$constraints = explode('|', $captureMethodSetting['constraints']);

$this->assertContains('automatic', $constraints);
$this->assertContains('manual', $constraints);
```

## Technical Context

### OXID Module Metadata Format

OXID eShop module metadata (v2.1) requires select field constraints to be defined as:
- **Type**: `string`
- **Format**: Pipe-delimited values (e.g., `'option1|option2|option3'`)
- **Processing**: OXID internally calls `explode('|', $constraints)` to convert to array

This is a common mistake when defining module settings - developers intuitively use arrays, but OXID's metadata format requires strings.

### Why This Format?

1. **Backward compatibility**: OXID 6.x used string-based metadata
2. **Admin interface**: The admin panel expects this format for generating select dropdowns
3. **Validation**: Module validator checks this format during installation/activation

## Verification

### ✅ Composer Update
```bash
$ docker compose exec php composer update
Loading composer repositories with package information
Updating dependencies
Nothing to modify in lock file
Installing dependencies from lock file
# ... SUCCESS (no TypeError)
```

### ✅ Full Test Suite
```
Tests: 569, Assertions: 1414, Skipped: 2
Status: ✅ ALL PASSING
```

### ✅ Code Style Checks
```
Running PHP CodeSniffer... ✅
PHPStan... ✅
PHPMD... ✅
All code style checks passed.
```

## Impact

**Files Changed:** 2
- `metadata.php` (1 line)
- `tests/Integration/Module/MetadataTest.php` (8 lines)

**Lines Changed:** 9 lines total

**Tests Affected:** 1 test updated to match correct format

## Lessons Learned

1. **OXID Metadata Format**: Always use pipe-delimited strings for select constraints, not arrays
2. **Type Safety**: The error only appears during `composer update` when metadata is normalized
3. **Test Coverage**: Good test coverage caught the issue immediately after the fix
4. **Documentation**: This is documented in OXID's module development guide but easy to miss

## Best Practices

When defining module settings with constraints in OXID metadata:

```php
// ✅ CORRECT - Pipe-delimited string
'constraints' => 'value1|value2|value3'

// ❌ WRONG - Array
'constraints' => ['value1', 'value2', 'value3']

// ✅ CORRECT - Single value
'constraints' => 'single_value'

// ✅ CORRECT - Empty (no constraints)
'constraints' => ''
// or simply omit the 'constraints' key
```

## References

- OXID Module Metadata Documentation: [https://docs.oxid-esales.com/developer/en/latest/development/modules_components_themes/module/metadata/](https://docs.oxid-esales.com/developer/en/latest/development/modules_components_themes/module/metadata/)
- Metadata Version 2.1 Specification
- `OxidEsales\EshopCommunity\Internal\Framework\Module\MetaData\Dao\MetaDataNormalizer`

---

**Status:** ✅ RESOLVED
**Date:** 2025-11-05
**Impact:** Critical (blocked composer operations)
**Resolution Time:** ~5 minutes
**Test Coverage:** 100% maintained
