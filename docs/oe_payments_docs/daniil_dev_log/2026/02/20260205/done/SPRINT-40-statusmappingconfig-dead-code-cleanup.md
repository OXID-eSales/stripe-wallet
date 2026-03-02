# Sprint 40: StatusMappingConfig Dead Code Cleanup

**Date:** 2026-02-05
**Status:** TODO
**Priority:** Medium (Code Cleanup)

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

Remove `StatusMappingConfig` class which is dead code - created but never integrated into the application.

---

## Analysis

### Current State

| Class | Status | Usage |
|-------|--------|-------|
| `StripeStatusMapper` | **ACTIVE** | Used in StripeAdapter, StripePaymentStatusHandler |
| `StatusMappingConfig` | **DEAD CODE** | Only referenced in comments, has unused test |

### StatusMappingConfig Details

**File:** `src/Stripe/Config/StatusMappingConfig.php`

```php
final class StatusMappingConfig
{
    public const STRIPE_PENDING = 'PENDING';      // OXID OXTRANSSTATUS
    public const STRIPE_PROCESSING = 'OK';        // OXID OXTRANSSTATUS
    public const STRIPE_CANCELLED = 'ERROR';      // OXID OXTRANSSTATUS

    public static function getAll(): array { ... }
    public static function getOxidStatus(string $stripeState): ?string { ... }
}
```

**Purpose:** Map Stripe payment states to OXID `OXTRANSSTATUS` values.

**Problem:** Never imported or used anywhere in production code!

### Why It's Dead Code

1. Created during Sprint 29 to replace admin-configurable status mappings
2. Has a unit test but no production code uses it
3. Only referenced in comments:
   - `metadata.php`: "Sprint 29: Status mappings moved to StatusMappingConfig class"
   - `ModuleConfigurationService.php`: "use StatusMappingConfig constants instead"
   - `MetadataTest.php`: "now in StatusMappingConfig class"

### StripeStatusMapper (Actively Used)

**File:** `src/Stripe/Adapter/StripeStatusMapper.php`

Maps Stripe API statuses to normalized payment statuses:
- `succeeded` → `captured`
- `requires_capture` → `authorized`
- `canceled` → `cancelled`

This IS actively used throughout the codebase.

---

## Files to Delete

### 1. `src/Stripe/Config/StatusMappingConfig.php`

Dead code - never used in production.

### 2. `tests/Unit/Stripe/Config/StatusMappingConfigTest.php`

Tests for deleted class.

---

## Files to Update (Remove Comments)

### 1. `metadata.php`

```php
// DELETE this comment:
// Sprint 29: Status mappings moved to StatusMappingConfig class (not admin-configurable)
```

### 2. `src/Stripe/Service/ModuleConfigurationService.php`

```php
// DELETE this comment:
// Sprint 29: Status mapping methods removed - use StatusMappingConfig constants instead
```

### 3. `tests/Integration/Module/MetadataTest.php`

```php
// DELETE this comment:
// Sprint 29: Status mapping settings removed - now in StatusMappingConfig class
```

### 4. `views/twig/extensions/themes/default/module_config.html.twig`

```twig
{# DELETE this comment: #}
{# Sprint 29: Status mappings (sStripeStatusPending, etc.) moved to StatusMappingConfig class #}
```

---

## TDD Approach

### Step 1: Verify No Production Usage

```bash
# Should return only the class definition and test file
grep -r "StatusMappingConfig" src/ --include="*.php" | grep -v "class StatusMappingConfig"
# Expected: Only comment in ModuleConfigurationService.php
```

### Step 2: Delete Test First

Delete `tests/Unit/Stripe/Config/StatusMappingConfigTest.php`

### Step 3: Delete Class

Delete `src/Stripe/Config/StatusMappingConfig.php`

### Step 4: Remove Comments

Update the 4 files to remove Sprint 29 comments.

### Step 5: Run Full Validation

```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

- [ ] Confirmed no production code imports StatusMappingConfig
- [ ] `StatusMappingConfigTest.php` deleted
- [ ] `StatusMappingConfig.php` deleted
- [ ] `src/Stripe/Config/` directory deleted (if empty)
- [ ] Comment removed from `metadata.php`
- [ ] Comment removed from `ModuleConfigurationService.php`
- [ ] Comment removed from `MetadataTest.php`
- [ ] Comment removed from `module_config.html.twig`
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] PHPStan passes
- [ ] PHPCS passes
- [ ] PHPMD passes

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Breaking functionality | Class is not used - grep confirms no imports |
| Future need | Can be recreated if needed; StripeStatusMapper handles the actual status mapping |

---

## Acceptance Criteria

1. `StatusMappingConfig` class does not exist
2. No references to `StatusMappingConfig` in codebase (except git history)
3. All tests pass with `./bin/pre-commit-check.sh --full`
4. `StripeStatusMapper` continues to work (it's unaffected)

---

## Estimated Changes

| File | Action |
|------|--------|
| `src/Stripe/Config/StatusMappingConfig.php` | DELETE |
| `tests/Unit/Stripe/Config/StatusMappingConfigTest.php` | DELETE |
| `metadata.php` | Remove 1 comment line |
| `ModuleConfigurationService.php` | Remove 1 comment line |
| `MetadataTest.php` | Remove 1 comment line |
| `module_config.html.twig` | Remove 1 comment line |
| **Total** | 2 files deleted, 4 comments removed |
