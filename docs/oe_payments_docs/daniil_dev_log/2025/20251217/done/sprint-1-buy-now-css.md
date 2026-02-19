# Sprint 1: Fix Missing buy-now.css File

**Date:** 2025-12-17
**Status:** DONE
**Branch:** b-7.4.x-code-review-STRP-75

---

## Problem Statement

The shop was throwing a FileException on product detail pages when the Buy Now button component tried to load its CSS file.

### Error Message
```
[2025-12-16 17:43:39] OXID Logger.ERROR: Requested file not found for module osc_stripe_wallet
(/var/www/source/out/modules/osc_stripe_wallet/css/buy-now.css)
```

---

## Root Cause Analysis

The template `buy_now_button.html.twig` referenced the CSS file at path `css/buy-now.css`:

```twig
{% block osc_stripe_wallet_buy_now_button_css %}
    {{ style({ file: "css/buy-now.css", priority: 10 }) }}
{% endblock %}
```

However, the `assets/css/` directory did not exist in the module. The CSS file existed only in `resources/build/scss/buy-now.css` which is the source file for SCSS compilation.

### Directory Structure Before Fix
```
extensions/stripe/
├── assets/           # Missing css/ subdirectory
├── resources/
│   └── build/
│       └── scss/
│           └── buy-now.css   # Source file exists here
```

---

## Solution

1. Created the `assets/css/` directory
2. Copied `buy-now.css` from `resources/build/scss/` to `assets/css/`

### Commands Used
```bash
mkdir -p assets/css
cp resources/build/scss/buy-now.css assets/css/buy-now.css
```

### Directory Structure After Fix
```
extensions/stripe/
├── assets/
│   └── css/
│       └── buy-now.css   # Now available for module asset loading
├── resources/
│   └── build/
│       └── scss/
│           └── buy-now.css
```

---

## Files Created

| File | Purpose |
|------|---------|
| `assets/css/buy-now.css` | Buy Now button styles for product detail pages |

---

## Verification

After module reinstallation, the product detail pages loaded without FileException errors.

---

## Technical Notes

- OXID module assets are served from `assets/` directory
- The `style()` Twig function looks for files relative to module's asset path
- Module asset path is: `out/modules/{module_id}/`
- Files must be in `assets/` for OXID to copy them during module installation

---

## Related Files

- `views/twig/components/buy_now_button.html.twig` - Template using the CSS
- `metadata.php` - Module asset configuration

