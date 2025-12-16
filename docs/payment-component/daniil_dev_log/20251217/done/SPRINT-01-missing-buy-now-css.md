# SPRINT-01: Missing buy-now.css File

**Date:** 2025-12-17
**Type:** Bugfix
**Status:** ✅ Completed
**Priority:** High

## Executive Summary

Fix for `FileException: Requested file not found for module osc_stripe_wallet (css/buy-now.css)` error occurring on product detail pages when the Buy Now button feature is enabled.

## Error Details

```
[2025-12-16 17:43:39] OXID Logger.ERROR: Requested file not found for module osc_stripe_wallet
(/var/www/source/out/modules/osc_stripe_wallet/css/buy-now.css)
```

**Stack trace location:** `ViewConfig.php:1419` → `getModulePath()` → `getModuleUrl()`

## Root Cause Analysis

1. **Template Reference:** `views/twig/frontend/buy_now_button.html.twig:7` references:
   ```twig
   <link rel="stylesheet" href="{{ oViewConf.getModuleUrl('osc_stripe_wallet', 'css/buy-now.css') }}">
   ```

2. **Expected Path:** `out/modules/osc_stripe_wallet/css/buy-now.css` (symlinked to `assets/css/buy-now.css`)

3. **Actual Location:** CSS file exists only at `resources/build/scss/buy-now.css`

4. **Missing Structure:** No `assets/css/` directory exists in the module

## Current File Structure

```
extensions/stripe/
├── assets/
│   ├── img/
│   ├── js/
│   │   ├── stripe-frontend.js
│   │   ├── stripe-frontend.min.js
│   │   └── ...
│   └── README.md
│   └── [NO css/ folder!]
├── resources/
│   └── build/
│       └── scss/
│           └── buy-now.css   ← Source file exists here
```

## Solution

Create `assets/css/` directory and copy `buy-now.css` to it:

```bash
mkdir -p assets/css
cp resources/build/scss/buy-now.css assets/css/buy-now.css
```

## Files to Modify

1. **Create:** `assets/css/buy-now.css` - Copy from `resources/build/scss/buy-now.css`

## Verification Steps

1. Ensure file exists at `assets/css/buy-now.css`
2. Verify OXID module symlink points correctly: `out/modules/osc_stripe_wallet/` → `extensions/stripe/assets/`
3. Clear shop cache: `rm -rf var/cache/*`
4. Access product detail page and verify no errors in log
5. Verify Buy Now button displays with proper styling

## SOLID Principles Applied

- **SRP:** Each asset type has its own directory (js/, css/, img/)
- **DRY:** Single CSS file serving all Buy Now button instances

## Testing

- [x] Unit tests pass: `./bin/pre-commit-check.sh` (1426 tests, 3389 assertions)
- [x] Style checks pass: PHPStan, PHPCS, PHPMD
- [ ] Product detail page loads without errors
- [ ] Buy Now button styling renders correctly
- [ ] No new entries in `oxideshop.log`

## Risks

- **Low:** This is a straightforward file placement fix
- **Mitigation:** Pre-commit check will validate code quality

---

**Author:** Development Team
**Last Updated:** 2025-12-17
