# Sprint 61: Medium Severity Security Findings Tests (F21–F25)

**Status:** COMPLETE
**Date:** 2026-02-19
**Branch:** `b-7.4.x-mcp-STRP-88`

## Objective

Write security tests documenting the 5 MEDIUM severity findings from the deep dive audit.

## Findings Covered

| # | Finding | Severity | Standard | Module |
|---|---------|----------|----------|--------|
| F21 | Payment intent ID not format-validated | MEDIUM | Input validation | stripe |
| F22 | UCP profile URL not validated | MEDIUM | BSI TR-03116 | payment-component |
| F23 | Exception messages logged without sanitization | MEDIUM | GDPR Art.25, OWASP A09 | payment-component |
| F24 | CreateCheckoutTool JSON schema not enforced at runtime | MEDIUM | PCI DSS 6.5.1 | payment-component |
| F25 | `oxidstripe` hardcoded in EarlyOrderCreationHandler (Sprint 56b regression) | MEDIUM | Code correctness | payment-component |

## Test Files

### Phase 1: Input Validation
- [x] `tests/Security/InputValidation/PaymentIntentIdValidationTest.php` — F21
- [x] `tests/Security/InputValidation/UcpProfileUrlValidationTest.php` — F22

### Phase 2: Logging & Schema
- [x] `tests/Security/DataProtection/ExceptionMessageLeakageTest.php` — F23
- [x] `tests/Security/InputValidation/CheckoutToolSchemaEnforcementTest.php` — F24

### Phase 3: Regression
- [x] `tests/Security/ContractStateMachine/PaymentIdRegressionTest.php` — F25

## Verification

```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Security
docker compose exec -w /var/www/extensions/stripe -T php php vendor/bin/phpstan analyse --level=max --configuration=tests/PhpStan/phpstan.neon tests/Security/
```
