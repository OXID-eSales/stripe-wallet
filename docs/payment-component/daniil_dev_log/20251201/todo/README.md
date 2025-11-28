# Sprint Backlog: Stripe Connection Issue Fix

**Project Start:** December 1, 2025
**Developer:** Daniil (Claude Code)

---

## Sprint Overview

| Sprint | Description | Est. Hours | Status |
|--------|-------------|------------|--------|
| [Sprint 1](sprint-1-fix-stripe-client-factory-test.md) | Fix StripeClientFactoryTest | 0.5h | **COMPLETE** |
| [Sprint 2](sprint-2-docker-dns-configuration.md) | Docker DNS Configuration | 0.5h | **COMPLETE** |
| [Sprint 3](sprint-3-fix-remaining-integration-tests.md) | Fix Remaining Integration Tests | 1.5h | **COMPLETE** |
| Additional | Status Mapping Fixes | 0.5h | **COMPLETE** |

**Total:** 3 hours

---

## Dependency Graph

```
Sprint 1: Fix StripeClientFactoryTest (independent)
    │
    └──► Unblocks: Unit test CI

Sprint 2: Docker DNS Configuration (independent)
    │
    └──► Unblocks: Integration tests with external API

Sprint 3: Fix Remaining Integration Tests
    │
    ├── Depends on: Sprint 2 (for external API tests)
    └── Can start: Structural fixes independent
```

---

## Test Commands

```bash
# Run all unit tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit

# Run integration tests
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --exclude-group migration

# Run specific test file
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Unit/Stripe/Adapter/StripeClientFactoryTest.php

# Pre-commit check (all checks)
./source/extensions/stripe/bin/pre-commit-check.sh
```

---

## Key Principles

### 1. TDD-First Approach
```
RED:    Write failing test first
GREEN:  Write minimum code to pass
REFACTOR: Clean up while keeping tests green
```

### 2. SOLID Principles
- **S**ingle Responsibility: One class, one reason to change
- **O**pen/Closed: Open for extension, closed for modification
- **L**iskov Substitution: Subtypes must be substitutable
- **I**nterface Segregation: Many specific interfaces
- **D**ependency Inversion: Depend on abstractions

### 3. Clean Code
- Human-readable code
- Self-documenting method names
- No code duplication (DRY)
- Small, focused methods

### 4. No Reinvention
- Use existing code patterns
- Follow established architecture
- Reference existing handler patterns

---

## Current Test Status

```
Unit Tests:        852 total | 847 pass | 5 fail
Integration Tests: 226 total | 118 pass | 47 error | 4 fail | 56 skip
```

### Target After Fixes

```
Unit Tests:        852 total | 852 pass | 0 fail
Integration Tests: 226 total | ~165 pass | 0 error | 0 fail | ~61 skip
```

(Note: Integration tests requiring external API calls may remain skipped in isolated environments)

---

## Architecture References

### From 20251128 Status
- Contract-first pattern: VERIFIED
- Event-driven handlers: COMPLETE
- Provider-agnostic layer: COMPLETE

### Documentation
- `../../00-overview.md` - System overview
- `../../01-architecture-layers.md` - Layer architecture
- `../../04-sdk-adapter-layer.md` - SDK adapter pattern

---

## Progress Tracking

After completing each sprint:
1. Update `../status.md` with progress
2. Create completion report in `../done/`
3. Update this README with actual hours spent

---

**Last Updated:** 2025-12-01
