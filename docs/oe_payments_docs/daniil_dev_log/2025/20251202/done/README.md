# Sprint Index - December 2, 2025

## Active Sprints

| Sprint | Priority | Status | TDD Plan |
|--------|----------|--------|----------|
| [Sprint 1](sprint-1-order-creation-fix.md) | CRITICAL | TODO | [TDD Plan](sprint-1-tdd-implementation.md) |
| [Sprint 2](sprint-2-db-architecture-review.md) | HIGH | TODO | [TDD Plan](sprint-2-tdd-implementation.md) |

---

## TDD Methodology

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           TDD CYCLE                                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. RED:    Write failing test that defines expected behavior               │
│  2. GREEN:  Write minimal code to make test pass                            │
│  3. REFACTOR: Improve code while keeping tests green                        │
│  4. REPEAT: Next test case                                                  │
│                                                                             │
│  Run tests frequently:                                                      │
│  docker compose exec -T php vendor/bin/phpunit --filter "TestName"          │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Sprint 1: Session Restoration via URL Hash

**Problem:** Order creation fails because session data is lost after Stripe redirect.

**TDD Implementation Plan:**

| Phase | New Class/Test | Est. Tests | Est. LOC |
|-------|----------------|------------|----------|
| 1 | `SecurityValidationResult` (Value Object) | 5 | 30 |
| 2 | `ContractTokenService` | 12 | 50 |
| 3 | `ReturnSessionSecurityService` | 18 | 100 |
| 4 | Update `StripeContractCreationHandler` | +7 | 40 |
| 5 | Update `StripeCheckoutSessionHandler` | +3 | 20 |
| 6 | Update `StripeCheckoutReturnHandler` | +12 | 80 |
| 7 | Integration tests | 3 | 50 |

**Total:** ~60 tests, ~370 LOC

**Key Test Files:**
- `tests/Unit/Stripe/Service/ReturnSessionSecurityServiceTest.php`
- `tests/Unit/Stripe/Service/ContractTokenServiceTest.php`
- `tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php`
- `tests/Integration/Stripe/SessionRestorationIntegrationTest.php`

---

## Sprint 2: Database Table Consolidation

**Problem:** 3 redundant tables need cleanup.

**TDD Implementation Plan:**

| Phase | Task | Est. Tests |
|-------|------|------------|
| 1.1 | Baseline tests for existing behavior | 5 |
| 1.2 | WebhookController uses repository | 3 |
| 1.3 | WebhookProcessingService uses repository | 2 |
| 2.1 | StripeCustomerService baseline | 3 |
| 2.2 | PaymentCustomerRepository | 3 |
| 3.1 | Verify payment details table unused | 3 |
| 4.1 | Migration creates correct schema | 3 |
| 4.2 | Events.php cleanup | 4 |

**Total:** ~26 tests

**Key Test Files:**
- `tests/Integration/Component/Repository/DoctrineWebhookLogRepositoryTest.php`
- `tests/Unit/Stripe/Controller/Webhook/WebhookControllerRepositoryTest.php`
- `tests/Unit/Stripe/Service/StripeCustomerServiceProviderAgnosticTest.php`
- `tests/Integration/Database/TableConsolidationMigrationTest.php`

---

## Development Workflow

### Before Starting a Task

```bash
# 1. Pull latest
git pull origin b-8.0.x

# 2. Run existing tests (ensure green baseline)
./source/extensions/stripe/bin/pre-commit-check.sh

# 3. Create feature branch
git checkout -b feature/sprint-1-session-restoration
```

### During Development (TDD Cycle)

```bash
# 1. Write failing test
# 2. Run just that test
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --filter "testMethodName"

# 3. Write code to make it pass
# 4. Run test again
# 5. Refactor if needed
# 6. Repeat
```

### After Completing Task

```bash
# 1. Run full test suite
./source/extensions/stripe/bin/pre-commit-check.sh

# 2. Commit with descriptive message
git add -A
git commit -m "feat: implement session restoration via URL hash

- Add ContractTokenService for secure token generation
- Add ReturnSessionSecurityService for fraud scoring
- Update handlers to store/restore session data
- Inject delivery address hash into \$_REQUEST on return

Tests: 60 new tests, all passing"

# 3. Push for review
git push origin feature/sprint-1-session-restoration
```

---

## Quick Commands

```bash
# ═══════════════════════════════════════════════════════════════════════════════
# UNIT TESTS (no database required)
# ═══════════════════════════════════════════════════════════════════════════════

# Run specific unit test class
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "ReturnSessionSecurityServiceTest"

# Run unit tests matching pattern
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "testValidateReturn"

# Run all unit tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit

# ═══════════════════════════════════════════════════════════════════════════════
# INTEGRATION TESTS (requires database + OXID bootstrap)
# ═══════════════════════════════════════════════════════════════════════════════

# Run specific integration test class
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --filter "SessionRestorationIntegrationTest"

# Run all integration tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php

# ═══════════════════════════════════════════════════════════════════════════════
# FULL TEST SUITE
# ═══════════════════════════════════════════════════════════════════════════════

# Full pre-commit check (includes static analysis + all tests)
./source/extensions/stripe/bin/pre-commit-check.sh

# Run with coverage (slow)
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --coverage-text
```

---

## Files Structure After Implementation

```
tests/
├── Unit/
│   ├── Stripe/
│   │   ├── Service/
│   │   │   ├── ReturnSessionSecurityServiceTest.php    # NEW
│   │   │   ├── ContractTokenServiceTest.php            # NEW
│   │   │   └── Result/
│   │   │       └── SecurityValidationResultTest.php    # NEW
│   │   ├── EventSystem/Handler/
│   │   │   └── StripeCheckoutReturnHandlerTest.php     # UPDATED
│   │   └── Controller/Webhook/
│   │       └── WebhookControllerRepositoryTest.php     # NEW
│   └── Component/Repository/
│       └── PaymentCustomerRepositoryTest.php           # NEW
└── Integration/
    ├── Stripe/
    │   └── SessionRestorationIntegrationTest.php       # NEW
    └── Database/
        └── TableConsolidationMigrationTest.php         # NEW

src/
├── Stripe/
│   ├── Service/
│   │   ├── ReturnSessionSecurityService.php            # NEW
│   │   ├── ContractTokenService.php                    # NEW
│   │   └── Result/
│   │       └── SecurityValidationResult.php            # NEW
│   └── EventSystem/Handler/
│       └── StripeCheckoutReturnHandler.php             # UPDATED
└── Component/Repository/
    └── DoctrinePaymentCustomerRepository.php           # NEW (or update existing)
```

---

**Created:** 2025-12-02
