# Status - 2025-12-16

**Feature:** Delayed/Manual Capture Implementation
**Branch:** b-7.4.x-code-review-STRP-75

---

## Current Sprint Status

| Sprint | Description | Status | Notes |
|--------|-------------|--------|-------|
| Sprint 1 | Add AUTHORIZED state to ContractState | **DONE** | 15 new tests added |
| Sprint 2 | Add module configuration setting | **DONE** | 10 new tests added |
| Sprint 3 | Modify CheckoutSessionService | **DONE** | 2 new tests, config integration |
| Sprint 4 | Create CaptureRequestedEvent and handler | **DONE** | 24 tests added |
| Sprint 5 | Handle authorization in return flow | **DONE** | 3 tests added |
| Sprint 6 | Admin backend capture UI | **DONE** | UI + translations |
| Sprint 7 | Webhook handler for charge.captured | **DONE** | 2 new tests added |
| Sprint 8 | Unit tests | **DONE** | 23 new tests added |
| Sprint 9 | Integration tests | **DONE** | 10 new tests added |
| Sprint 10 | Documentation updates | **DONE** | Full feature docs + translations |
| Sprint 11 | Fix DI circular dependency | **DONE** | EventListenerProvider lazy init |

---

## Progress Summary

### Completed Today
- [x] Project state review (dev logs 20251205 - 20251215)
- [x] Architecture analysis for delayed capture feature
- [x] Task elaboration and documentation
- [x] Sprint planning
- [x] **Sprint 1:** AUTHORIZED state added to ContractState
- [x] **Sprint 2:** Module configuration setting added (sStripeCaptureMode)
- [x] **Sprint 3:** CheckoutSessionService reads capture mode from config
- [x] **Sprint 4:** CaptureRequestedEvent and StripeCaptureRequestHandler created
- [x] **Sprint 5:** Return flow handles requires_capture status
- [x] **Sprint 6:** Admin backend capture UI added
- [x] **Sprint 7:** Webhook handler for charge.captured (AUTHORIZED -> READY_TO_COMMIT)
- [x] **Sprint 8:** Unit tests (23 new tests: CheckoutReturnResult + edge cases)
- [x] **Sprint 9:** Integration tests (10 new tests: DelayedCaptureIntegrationTest)
- [x] **Sprint 10:** Documentation updates (07-01-delayed-capture.md + translations)
- [x] **Sprint 11:** Fix circular dependency in DI container (EventListenerProvider lazy init)

### In Progress
- (none - all sprints completed!)

### Blocked
- (none)

---

## Code Quality Baseline

Current session (2025-12-16):

| Metric | Value | Status |
|--------|-------|--------|
| Unit Tests | 1426 | PASSING |
| Assertions | 3389 | OK |
| Skipped Tests | 1 | Known issues |
| PHPStan Level 6 | WARNING | Pre-existing controller type issues |
| PHPCS (PSR-12) | PASSING | |
| PHPMD | WARNING | Pre-existing PaymentContract complexity |

---

## Key Decisions Made

1. **State Machine Approach:** Add `AUTHORIZED` state between `PENDING` and `READY_TO_COMMIT`
2. **Configuration Scope:** Global setting in module config (not per-payment-method)
3. **Trigger Mechanism:** Event-driven via `CaptureRequestedEvent`
4. **Admin UI:** Add capture button to order detail page

---

## Open Questions

| # | Question | Decision | Notes |
|---|----------|----------|-------|
| 1 | Partial capture support? | TBD | May add in future sprint |
| 2 | Auto-capture cron job? | TBD | Out of scope for initial implementation |
| 3 | Void/cancel authorization? | TBD | May add in future sprint |

---

## Files Created Today

| File | Purpose |
|------|---------|
| `20251216/README.md` | Task description and technical analysis |
| `20251216/status.md` | This file - progress tracking |
| `20251216/todo/sprint-*.md` | Sprint task definitions |
| `20251216/done/sprint-1-authorized-state-report.md` | Sprint 1 completion report |
| `20251216/done/sprint-2-module-config-report.md` | Sprint 2 completion report |
| `20251216/done/sprint-3-checkout-session-report.md` | Sprint 3 completion report |
| `20251216/done/sprint-4-capture-event-handler-report.md` | Sprint 4 completion report |
| `20251216/done/sprint-5-return-flow-report.md` | Sprint 5 completion report |
| `20251216/done/sprint-6-admin-capture-ui-report.md` | Sprint 6 completion report |
| `20251216/done/sprint-7-webhook-charge-captured-report.md` | Sprint 7 completion report |
| `20251216/done/sprint-8-unit-tests-report.md` | Sprint 8 completion report |
| `tests/Unit/Stripe/DTO/CheckoutReturnResultTest.php` | New test file (21 tests) |
| `20251216/done/sprint-9-integration-tests-report.md` | Sprint 9 completion report |
| `tests/Integration/Stripe/Webhook/DelayedCaptureIntegrationTest.php` | New test file (10 tests) |
| `20251216/done/sprint-10-documentation-report.md` | Sprint 10 completion report |
| `20251216/done/sprint-11-circular-dependency-report.md` | Sprint 11 completion report |
| `docs/payment-component/07-01-delayed-capture.md` | Delayed capture documentation |

---

## Next Steps

**All sprints completed!**

1. Run final pre-commit checks
2. Create git commit
3. Create pull request for code review

---

## Commands Reference

```bash
# Run unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Run specific test
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter testAuthorizedState extensions/stripe/tests/Unit/Component/Contract/ContractStateTest.php

# Pre-commit checks
./bin/pre-commit-check.sh

# E2E tests
cd tests/e2e/playwright && npx playwright test
```

---

**Last Updated:** 2025-12-16 (initial)
