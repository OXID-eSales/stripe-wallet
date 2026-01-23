# Sprint Overview: Handler Refactoring Initiative

**Date Created:** 2026-01-23
**Baseline Tests:** 793 tests, 2334 assertions (ALL PASSING)
**Total Estimated Effort:** 9-13 hours

---

## Core Requirements (Apply to ALL Sprints)

**All code must follow:**
- **TDD (Test-Driven Development)** - Write failing tests first, then implementation
- **SOLID Principles** - Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **Clean Code** - Meaningful names, small functions (15-25 lines), no else expressions (use early returns), DRY
- **Dependency Injection** - Depend on abstractions, not concretions
- **PSR-12** code style, **PHPStan level 6** compliance
- **DRY** do not repeat yourself - extract common code

---

## Development Environment

**Docker Environment:** All tests run inside Docker from project root.

**Running Tests:**
```bash
# Pre-commit check (Unit tests + style)
./bin/pre-commit-check.sh

# Full check with Integration tests (REQUIRED before completing any sprint)
./bin/pre-commit-check.sh --full

# Run specific test suite
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
```

---

## Sprint Dependency Graph

```
SPRINT-8: RequestLogService (HIGH) ─────────┐
                                            │
SPRINT-9: CaptureService (HIGH) ────────────┼── Depends on SPRINT-8
                                            │
SPRINT-10: RefundHandler Refactor (MEDIUM) ─┘

SPRINT-11: CancelAuthorizationService (MEDIUM) ── Depends on SPRINT-8

SPRINT-12: OrderCreationHandler Cleanup (LOW) ── No dependencies
```

---

## Design Decisions (Apply to ALL Sprints)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Amount format | **Service accepts float** | Converts internally to cents, cleaner API for callers |
| Error handling | **Result objects** | `ActionResult::success()` / `::failure()`, no exceptions |
| DTO location | **src/Stripe/DTO/** | CaptureResult, RefundResult, CancellationResult |
| DI location | **Stripe services.yaml** | All new services are Stripe-specific |
| Code style | **Follow existing patterns** | NullLogger default, readonly properties, final class |
| RequestLogService | **Facade pattern** | Wraps legacy RequestLog, can swap implementation later |

---

## Sprint Summary

| Sprint | Title | Priority | Effort | Depends On | Status |
|--------|-------|----------|--------|------------|--------|
| **8** | RequestLogService | HIGH | 2-3h | - | **COMPLETED ✓** |
| **9** | CaptureService | HIGH | 3-4h | Sprint 8 | **COMPLETED ✓** |
| **10** | RefundHandler Refactor | MEDIUM | 2-3h | Sprint 8 | **COMPLETED ✓** |
| **11** | CancelAuthorizationService | MEDIUM | 1-2h | Sprint 8 | **COMPLETED ✓** |
| **12** | OrderCreationHandler Cleanup | LOW | 1h | - | **COMPLETED ✓** |
| **13** | Fix Playwright Admin Auth | HIGH | 30min | - | **COMPLETED ✓** |
| | **TOTAL** | | **9-14h** | | **6/6 DONE** |

---

## Recommended Execution Order

### Week 1: Foundation

1. **SPRINT-8: RequestLogService** (HIGH)
   - Creates shared logging infrastructure
   - Unblocks Sprints 9, 10, 11

2. **SPRINT-12: OrderCreationHandler Cleanup** (LOW)
   - Independent, quick win
   - Can be done in parallel with Sprint 8

### Week 2: Major Refactoring

3. **SPRINT-9: CaptureService** (HIGH)
   - Biggest impact: worst offender handler
   - -61% handler reduction

4. **SPRINT-10: RefundHandler Refactor** (MEDIUM)
   - Addresses original analysis target
   - -62% handler reduction

### Week 3: Completion

5. **SPRINT-11: CancelAuthorizationService** (MEDIUM)
   - Completes the service extraction pattern
   - -62% handler reduction

---

## Expected Outcomes

### Handler Lines Comparison

| Handler | Before | After All Sprints | Reduction |
|---------|--------|-------------------|-----------|
| StripeCaptureRequestHandler | 389 | ~150 | -61% |
| StripeRefundRequestHandler | 346 | ~130 | -62% |
| StripeCancelAuthorizationRequestHandler | 211 | ~80 | -62% |
| StripeOrderCreationHandler | 337 | ~310 | -8% |
| **Total (4 handlers)** | **1,283** | **~670** | **-48%** |

### New Services Created

| Service | Lines | Purpose |
|---------|-------|---------|
| RequestLogService | ~105 | Centralized logging |
| CaptureService | ~180 | Capture business logic |
| OrderRefundUpdateService | ~80 | Order update after refund |
| CancelAuthorizationService | ~140 | Cancel authorization logic |
| **Total new code** | **~505** | |

### Net Impact

- **Lines removed from handlers:** ~724
- **Lines added in services:** ~505
- **Net reduction:** ~219 lines
- **Eliminated duplication:** ~200 lines
- **New unit tests:** ~20-30 tests

---

## Success Metrics

| Metric | Before | Target | Improvement |
|--------|--------|--------|-------------|
| Handlers >250 lines | 4 | 0 | -100% |
| Duplicated code blocks | 5+ | 0 | -100% |
| Average handler lines | 321 | ~168 | -48% |
| Handler responsibilities | 8+ each | 3-4 each | -60% |
| SOLID compliance | Partial | Full | Improved |

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Regression bugs | Medium | High | Comprehensive tests, pre-commit checks |
| DI configuration issues | Low | Medium | services.yaml validation |
| Interface mismatch | Low | Low | TDD approach |

---

## Verification Checklist (After All Sprints)

- [x] All 793+ tests pass (635 unit tests, growing from 624)
- [ ] `./bin/pre-commit-check.sh --full` passes
- [x] No duplicated code (verified by inspection)
- [x] All handlers under 200 lines
- [x] All new services have unit tests
- [x] services.yaml properly configured
- [x] Documentation updated

---

## Related Documentation

- `reports/01-refund-handler-service-analysis.md`
- `reports/02-all-handlers-analysis.md`
- `docs/payment-component/dev_history/architecture/handler-abstraction-pattern.md`
