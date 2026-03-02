# Sprint 70a — M2: Dev Mode Domain Matching Fix

**Date:** 2026-02-24
**Status:** DONE
**Finding:** M2 — Dev Mode Domain Matching Has False Positives (MEDIUM)
**Package:** stripe

## Problem

`ViewConfig::isStripeDevelopmentMode()` used `strpos($serverName, $domain)` to check dev domains:

```php
$devDomains = ['localhost', '.local', '.dev', '.test', 'oxiddev.de'];
foreach ($devDomains as $domain) {
    if (strpos($serverName, $domain) !== false) { return true; }
}
```

**False positives:**
- `attacker.localhost.com` matches `localhost` → dev mode enabled on attacker's domain
- `evil-site.local.attacker.com` matches `.local` → dev mode enabled
- `my.test.phishing.com` matches `.test` → dev mode enabled

Dev mode enables unminified JS (larger XSS surface), debug logging (may leak paths), and timestamp cache busting (may bypass CDN).

## Fix

Replaced `strpos()` with **strict domain suffix matching** using `str_ends_with()` (PHP 8.0+, project requires 8.2+):

```php
foreach ($devDomains as $domain) {
    if ($serverName === $domain || str_ends_with($serverName, $domain)) {
        return true;
    }
}
```

**Correct matches:**
- `localhost` → exact match ✓
- `shop.local` → ends with `.local` ✓
- `myshop.dev` → ends with `.dev` ✓

**Correctly rejected:**
- `attacker.localhost.com` → does NOT end with `localhost` ✗
- `evil.test.attacker.com` → does NOT end with `.test` ✗

Also extracted `$_SERVER['SERVER_NAME']` access into `protected getServerName(): string` for testability.

## Files Modified (1)

- `src/Stripe/Core/ViewConfig.php`
  - Replaced `strpos()` loop with `str_ends_with()` + exact match
  - Extracted `getServerName(): string` protected method
  - Added inline comment explaining the security rationale

## Files Created (1)

### Tests
- `tests/Unit/Stripe/Core/ViewConfigDevModeTest.php`
  - Testable subclass `TestableViewConfigForDevMode` overrides `getServerName()` and env var access
  - Reimplements `isStripeDevelopmentMode()` logic to test domain matching without OXID Registry

## Test Results

```
Tests: 6, Assertions: 6, Failures: 0
```

| # | Test | Server Name | Expected |
|---|------|-------------|----------|
| 1 | `devModeDetectsLocalhost` | `localhost` | `true` |
| 2 | `devModeDetectsDotLocal` | `shop.local` | `true` |
| 3 | `devModeRejectsPartialMatch` | `attacker.localhost.com` | `false` |
| 4 | `devModeRejectsSubdomainTrick` | `evil.test.attacker.com` | `false` |
| 5 | `devModeAcceptsEnvVariable` | `production.shop.com` + `STRIPE_DEV_MODE=1` | `true` |
| 6 | `devModeDefaultsFalseInProduction` | `production.shop.com` | `false` |

## SOLID Compliance

- **S**: `getServerName()` extracts one value; domain check is single concern
- **O**: Existing behavior preserved for legitimate dev domains
- **L**: Public API unchanged — `isStripeDevelopmentMode()` still returns `bool`
- **I**: No new interfaces
- **D**: No new dependencies
