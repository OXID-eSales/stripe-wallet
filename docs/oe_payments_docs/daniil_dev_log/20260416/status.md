# Status — 2026-04-16

## Sprint 89: STRP-124 Preserve Active Language and Shop in Stripe Return URL

### Problem

Return URL from Stripe loses both language and shop context:
1. **Language** — defaults to `lang=0` (German) regardless of user's active language
2. **Shop** — `shp=` missing, defaults to main shop in multi-shop setups

### Root Cause

`buildSuccessUrl()` and cancel URL construction don't include `&lang=` or `&shp=` parameters.

### Fix

Pass `languageId` + `shopId` through event context → append `&lang={id}&shp={id}` to both URLs.

### Sub-Sprint Progress

| Sprint | Description | Status | Notes |
|--------|-------------|--------|-------|
| 89a | RED — Failing tests for lang + shp in URLs | done | 2 tests: lang/shp included, defaults to 0/1 |
| 89b | GREEN — Implement lang + shp params | done | 5 files: interface, service, handler, controller, helper |
| 89c | REFACTOR — Pre-commit | done | All checks pass, COMMITABLE |
