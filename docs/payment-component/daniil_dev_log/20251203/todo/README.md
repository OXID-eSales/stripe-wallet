# Sprint Plans - December 3, 2025

## Sprint Index

| Sprint | Status | Description | Priority |
|--------|--------|-------------|----------|
| [Sprint 1](../done/sprint-1-webhook-tests.md) | **COMPLETED** | Webhook Tests for Stripe Events | HIGH |
| [Sprint 2](../done/sprint-2-oxorder-field-tests.md) | **COMPLETED** | OXORDER Field Persistence Tests | HIGH |
| [Sprint 3](../done/sprint-3-playwright-e2e.md) | **COMPLETED** | Playwright E2E Tests Setup | MEDIUM |

### Completed Sprints

- **Sprint 1:** 32 tests, 177 assertions - [Report](../done/sprint-1-webhook-tests-REPORT.md)
- **Sprint 2:** 14 tests, 24 assertions - [Report](../done/sprint-2-oxorder-field-tests-REPORT.md)
- **Sprint 3:** 4 E2E tests - [Report](../done/sprint-3-playwright-e2e-REPORT.md)

---

## Execution Order

```
Sprint 1: Webhook Tests
    ├── Phase 1: Unit Tests (TDD RED)
    ├── Phase 2: Implementation (TDD GREEN)
    ├── Phase 3: Refactor
    └── Phase 4: Integration Tests
           │
           ▼
Sprint 2: OXORDER Field Tests
    ├── Phase 1: Unit Tests (TDD RED)
    ├── Phase 2: Implementation (TDD GREEN)
    ├── Phase 3: Integration Tests
    └── Phase 4: Verify via Webhook Flow
           │
           ▼
Sprint 3: Playwright E2E
    ├── Phase 1: Setup Infrastructure
    ├── Phase 2: Page Objects
    ├── Phase 3: Core Test Scenarios
    └── Phase 4: Integration with CI
```

---

## Key Rules

1. **Always run pre-commit-check.sh** before finishing each sprint
2. **When sprint completes**:
   - Move sprint file from `todo/` to `done/`
   - Create report: `done/<sprint-X-name>-REPORT.md`
   - Example: `done/sprint-1-webhook-tests-REPORT.md`
3. **Update status.md** after each significant progress
4. **TDD-FIRST**: Write failing tests before implementation
5. **No duplication**: Reuse existing services and handlers
6. **Focus on critical paths**: Don't over-engineer

## Sprint Completion Pattern

```
todo/sprint-1-webhook-tests.md          → done/sprint-1-webhook-tests.md
                                        + done/sprint-1-webhook-tests-REPORT.md
```

---

## Docker Test Commands

```bash
# Unit Tests
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit

# Integration Tests (with OXID bootstrap)
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php

# Pre-commit checks
./source/extensions/stripe/bin/pre-commit-check.sh
```

---

## Existing Services to Reuse

| Service | Location | Purpose |
|---------|----------|---------|
| `WebhookProcessingService` | `src/Stripe/Service/` | Processes webhook events |
| `WebhookController` | `src/Stripe/Controller/Webhook/` | HTTP endpoint for webhooks |
| `WebhookLogRepository` | `src/Component/Repository/` | Stores webhook logs |
| `DoctrineContractRepository` | `src/Component/Repository/` | Contract persistence |
| `DoctrineTransactionRepository` | `src/Component/Repository/` | Transaction persistence |
| `EventDispatcher` | `src/Component/EventSystem/` | Event-driven architecture |

---

## Architecture Reference

See previous documentation:
- `daniil_dev_log/20251201/puml/` - Complete checkout flow diagrams
- `daniil_dev_log/20251202/puml/` - Refund and error flow diagrams
- `05-02-webhooks-with-smart-contracts.md` - Webhook + Contract integration
