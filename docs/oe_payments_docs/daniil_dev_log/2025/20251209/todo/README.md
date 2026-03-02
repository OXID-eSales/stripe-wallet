# Sprint Backlog: Code Review Remediation

**Date:** 2025-12-09
**Source:** CODE_REVIEW.md analysis
**Developer:** Daniil (Claude Code)

---

## Core Development Principles

**All sprints MUST follow these principles:**

| Principle | Description | Enforcement |
|-----------|-------------|-------------|
| **TDD-FIRST** | Write failing tests BEFORE implementation (RED → GREEN → REFACTOR) | Every sprint starts with test file |
| **SOLID** | Single Responsibility, Open/Closed, Liskov, Interface Segregation, DI | Code review checklist |
| **LSP (Liskov Substitution)** | Use interfaces as types, subtypes must be substitutable | All new classes implement interfaces |
| **DI (Dependency Injection)** | All dependencies injected via constructor, no `new` in business logic | No ContainerFactory access |
| **Clean Code** | 15-25 line methods, no else expressions, early returns | PHPStan level 6 |
| **DRY** | No duplicate code, extract shared logic to services | Max 1 location for each operation |
| **No Over-Engineering** | Minimal changes to achieve the goal | Review scope before implementation |
| **Containerization** | All tests run in Docker, consistent environment | `docker compose exec` commands |

---

## Sprint Overview

| Sprint | Description | Priority | Est. Effort | Status |
|--------|-------------|----------|-------------|--------|
| Sprint 15 | Remove test class import from production | CRITICAL | 2h | **DONE** |
| Sprint 16 | Extract OrderPaymentStateService (OXPAID consolidation) | CRITICAL | 3h | **DONE** |
| Sprint 17 | Fix false-positive tests | CRITICAL | 2h | **DONE** |
| Sprint 18 | Extract ContractFulfillmentService | HIGH | 3h | **DONE** |
| [Sprint 19](sprint-19-stripe-adapter-routing.md) | Route Stripe SDK calls through adapter | HIGH | 4h | PENDING |
| [Sprint 20](sprint-20-remove-request-modification.md) | Remove $_REQUEST modification | HIGH | 2h | PENDING |
| [Sprint 21](sprint-21-refactor-fat-handlers.md) | Refactor ALL fat handlers (6 handlers → services) | MEDIUM-HIGH | 16h | PENDING |
| [Sprint 22](sprint-22-resolve-container-factory.md) | Resolve ContainerFactory usage | MEDIUM | 3h | PENDING |
| [Sprint 23](sprint-23-documentation-updates.md) | Update architecture documentation | MEDIUM | 2h | PENDING |

**Total Estimated Effort:** 37 hours

---

## Dependency Graph

```
Sprint 15: Test Class Removal (CRITICAL - blocks deployment)
    │
    └──► Unblocks: Clean production code

Sprint 16: OrderPaymentStateService (CRITICAL - data consistency)
    │
    └──► Consolidates: 4 OXPAID update locations

Sprint 17: False-Positive Tests (CRITICAL - test reliability)
    │
    └──► Enables: Confident refactoring

Sprint 18: ContractFulfillmentService (HIGH)
    │
    ├── Depends on: Sprint 16 (shared service pattern)
    └──► Consolidates: 3 fulfillment locations

Sprint 19: Stripe Adapter Routing (HIGH)
    │
    └──► Enables: Provider-agnostic handlers

Sprint 20: $_REQUEST Removal (HIGH)
    │
    └──► Improves: Security and testability

Sprint 21: ALL Fat Handlers Refactoring (MEDIUM-HIGH)
    │
    ├── Depends on: Sprint 18, 19, 20 (service extraction patterns)
    └──► Creates: 8 new services (RefundService, CheckoutReturnService, etc.)

Sprint 22: ContainerFactory Resolution (MEDIUM)
    │
    ├── Depends on: Sprint 21 (DI cleanup)
    └──► Resolves: Circular dependencies

Sprint 23: Documentation Updates (MEDIUM)
    │
    ├── Depends on: Sprint 15-22 (code changes)
    └──► Updates: Architecture docs
```

---

## Test Commands

```bash
# Run all unit tests
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"

# Run integration tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/test-module/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php

# Run single test file
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Path/To/TestFile.php"

# Pre-commit check
./bin/pre-commit-check.sh

# E2E tests
cd tests/e2e/playwright && npx playwright test tests/checkout/
```

---

## Quality Gates

### Before Each Sprint Completion

- [ ] All new tests pass (TDD)
- [ ] All existing tests pass
- [ ] PHPStan level 6 passes
- [ ] PHPCS (PSR-12) passes
- [ ] No new ContainerFactory usage
- [ ] All dependencies injected via constructor
- [ ] Methods ≤ 25 lines
- [ ] No else expressions (use early returns)

### Before Final Merge

- [ ] All sprints complete
- [ ] E2E checkout flow passes
- [ ] Documentation updated
- [ ] Code review by peer

---

## Progress Tracking

| Sprint | Status | Started | Completed |
|--------|--------|---------|-----------|
| Sprint 15 | **DONE** | 2025-12-09 | 2025-12-09 |
| Sprint 16 | **DONE** | 2025-12-09 | 2025-12-09 |
| Sprint 17 | **DONE** | 2025-12-09 | 2025-12-09 |
| Sprint 18 | **DONE** | 2025-12-09 | 2025-12-09 |
| Sprint 19 | PENDING | - | - |
| Sprint 20 | PENDING | - | - |
| Sprint 21 | PENDING | - | - |
| Sprint 22 | PENDING | - | - |
| Sprint 23 | PENDING | - | - |

---

## Files in This Directory

```
20251209/
├── todo/
│   ├── README.md                           # This file - sprint overview
│   ├── sprint-19-stripe-adapter-routing.md
│   ├── sprint-20-remove-request-modification.md
│   ├── sprint-21-refactor-fat-handlers.md
│   ├── sprint-22-resolve-container-factory.md
│   └── sprint-23-documentation-updates.md
└── done/
    ├── sprint-15-test-class-in-production.md           # Plan
    ├── sprint-15-test-class-in-production-report.md    # Report
    ├── sprint-16-order-payment-state-service.md        # Plan
    ├── sprint-16-order-payment-state-service-report.md # Report
    ├── sprint-17-fix-false-positive-tests.md           # Plan
    ├── sprint-17-fix-false-positive-tests-report.md    # Report
    ├── sprint-18-contract-fulfillment-service.md       # Plan
    └── sprint-18-contract-fulfillment-service-report.md # Report
```

---

**Last Updated:** 2025-12-09
