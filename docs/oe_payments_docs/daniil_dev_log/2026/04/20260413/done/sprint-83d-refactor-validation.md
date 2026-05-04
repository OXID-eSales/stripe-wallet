# Sprint 83d: REFACTOR — Pre-commit Validation

**Date:** 2026-04-13
**Branch:** `b-7.4.x`
**Parent:** Sprint 83 (Transaction History Table)
**Blocked by:** Sprint 83c

## Objective

Run full pre-commit check and fix any issues. Confirm zero regressions.

## Validation Steps

1. `./bin/pre-commit-check.sh --full` — must pass:
   - PHPCS: 0 errors
   - PHPStan: 0 errors (level max)
   - PHPMD: 0 new violations (baseline unchanged)
   - PHPUnit Unit: all green
   - PHPUnit Integration: all green

2. Verify test count increased by 5 (from Sprint 83a tests)

3. Review PHPMD baseline — if `OrderRefundViewDataProvider` triggers new violation (e.g., TooManyMethods from adding 1 method), evaluate:
   - If reasonable: add to baseline
   - If not: refactor

## Acceptance Criteria

- [ ] `./bin/pre-commit-check.sh --full` passes with 0 errors
- [ ] No new PHPMD baseline entries needed
- [ ] Test count: baseline + 5
- [ ] Sprint 83 completion report written to `done/`
