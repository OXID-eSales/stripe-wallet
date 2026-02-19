# Sprint 59: Critical Security Findings Tests (F12–F14)

**Status:** COMPLETE
**Date:** 2026-02-19
**Branch:** `b-7.4.x-mcp-STRP-88`

## Objective

Write security tests documenting the 3 CRITICAL findings from the 2026-02-19 deep dive audit. These tests prove the vulnerabilities exist and will become regression tests when fixes are implemented.

## Findings Covered

| # | Finding | Severity | Standard | Module |
|---|---------|----------|----------|--------|
| F12 | JWT signature not verified in JwtTokenValidator | CRITICAL | OWASP A07, PCI DSS 6.5.10 | payment-component |
| F13 | MCP error responses leak internal details | CRITICAL | OWASP A01, PCI DSS 6.5.5 | payment-component |
| F14 | Open redirect via ACP order permalink (missing urlencode) | CRITICAL | PCI DSS 6.5.1, OWASP A01 | stripe |

## Test Files

### Phase 1: JWT Authentication Bypass
- [x] `tests/Security/Auth/JwtSignatureVerificationTest.php` — F12

### Phase 2: Information Disclosure
- [x] `tests/Security/Auth/McpErrorDisclosureTest.php` — F13

### Phase 3: Open Redirect
- [x] `tests/Security/InputValidation/AcpPermalinkInjectionTest.php` — F14

## Verification

```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Security
docker compose exec -w /var/www/extensions/stripe -T php php vendor/bin/phpstan analyse --level=max --configuration=tests/PhpStan/phpstan.neon tests/Security/
```
