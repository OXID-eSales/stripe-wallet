# Sprint 60: High Severity Security Findings Tests (F15–F20)

**Status:** COMPLETE
**Date:** 2026-02-19
**Branch:** `b-7.4.x-mcp-STRP-88`

## Objective

Write security tests documenting the 6 HIGH severity findings from the deep dive audit.

## Findings Covered

| # | Finding | Severity | Standard | Module |
|---|---------|----------|----------|--------|
| F15 | Amount fields lack negative/overflow validation | HIGH | PCI DSS 3.2 | payment-component |
| F16 | Currency not validated against ISO 4217 | HIGH | PCI DSS 6.5.1 | payment-component |
| F17 | Race condition in basket-to-payment flow | HIGH | OWASP A04 | stripe |
| F18 | Webhook endpoint has no rate limiting | HIGH | PCI DSS 10.2.1 | stripe |
| F19 | APCu rate limiter fails open when unavailable | HIGH | OWASP A05 | payment-component |
| F20 | Bearer token empty string bypass | HIGH | OWASP A07 | payment-component |

## Test Files

### Phase 1: Financial Integrity
- [x] `tests/Security/InputValidation/AmountValidationTest.php` — F15
- [x] `tests/Security/InputValidation/CurrencyValidationTest.php` — F16

### Phase 2: Rate Limiting & DoS
- [x] `tests/Security/Webhook/WebhookRateLimitAuditTest.php` — F18
- [x] `tests/Security/Auth/RateLimiterFailOpenTest.php` — F19

### Phase 3: Authentication & Race Conditions
- [x] `tests/Security/Auth/BearerEmptyStringBypassTest.php` — F20
- [x] `tests/Security/Idempotency/BasketRaceConditionTest.php` — F17

## Verification

```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Security
docker compose exec -w /var/www/extensions/stripe -T php php vendor/bin/phpstan analyse --level=max --configuration=tests/PhpStan/phpstan.neon tests/Security/
```
