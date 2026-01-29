# Sprint Plan Index: Payment Events Integration

**Project:** Integration of Payment Events into OXID Controllers
**Start Date:** 2025-11-26
**Methodology:** TDD, SOLID, Clean Code
**Reference:** [INTEGRATION_PAYMENT_EVENTS_INTO_OXID.md](../INTEGRATION_PAYMENT_EVENTS_INTO_OXID.md)

---

## Implementation Status

### Sprint 1: Event System DI Wiring - COMPLETED

**Completion Date:** 2025-11-26
**Status:** ✅ All tickets completed

| Ticket | Title | Status | Notes |
|--------|-------|--------|-------|
| STRP-101 | Create EventListenerProviderInterface | ✅ Complete | Interface created with `getListenersForEvent()` and `addListener()` methods |
| STRP-102 | Implement EventListenerProvider | ✅ Complete | Implementation with tagged iterator support. Updated `HandlerInterface` to require `getHandledEventClass()` method |
| STRP-103 | Update services.yaml for Event System | ✅ Complete | DI wiring for EventDispatcher and EventListenerProvider. Handler registration deferred until Sprint 2 |

#### Files Created

```
src/Component/EventSystem/
├── EventListenerProviderInterface.php  # New interface
├── EventListenerProvider.php           # New implementation
└── EventDispatcher.php                 # Modified to accept optional provider

tests/Unit/Component/EventSystem/
├── EventListenerProviderInterfaceTest.php  # Interface contract tests
└── EventListenerProviderTest.php           # Implementation tests
```

#### Files Modified

| File | Changes |
|------|---------|
| `src/Component/EventSystem/EventDispatcher.php` | Added constructor accepting `?EventListenerProviderInterface`, dispatch now checks provider first |
| `src/Component/EventSystem/Handler/HandlerInterface.php` | Added `getHandledEventClass(): string` method |
| `src/Component/EventSystem/Handler/AbstractHandler.php` | Added abstract `getHandledEventClass()` method |
| All concrete handlers (11 files) | Added `getHandledEventClass()` implementations |
| `services.yaml` | Added EventListenerProvider and EventDispatcher DI configuration |

#### Test Results

```
PHPUnit 11.5.44
Tests: 643, Assertions: 1489, Skipped: 1
Status: OK
```

#### DI Verification

```bash
docker compose exec -T php bash -c "cd /var/www/source && php -r \"
require 'bootstrap.php';
\\\$container = \\OxidEsales\\EshopCommunity\\Internal\\Container\\ContainerFactory::getInstance()->getContainer();
\\\$dispatcher = \\\$container->get(\\OxidSolutionCatalysts\\Payments\\Component\\EventSystem\\EventDispatcherInterface::class);
echo 'EventDispatcher loaded: ' . get_class(\\\$dispatcher);
\""

# Output: EventDispatcher loaded: OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher
```

#### Notes

- Handler DI registration is commented out in `services.yaml` until Sprint 2 when their dependencies (ContractServiceInterface, ContractRepositoryInterface, etc.) are registered
- The `HandlerInterface` was extended with `getHandledEventClass()` to enable explicit event-to-handler mapping (replaces reflection-based approach)
- Backward compatibility maintained: `EventDispatcher` works without provider (for existing tests)

---

### Sprint 2: Value Objects & Orchestrator - COMPLETED

**Completion Date:** 2025-11-26
**Status:** ✅ All tickets completed

| Ticket | Title | Status | Notes |
|--------|-------|--------|-------|
| STRP-201 | Create CheckoutResult Value Object | ✅ Complete | Immutable readonly class with factory methods |
| STRP-202 | Create OrderConfirmationResult Value Object | ✅ Complete | Immutable readonly class with state constants |
| STRP-203 | Create CheckoutOrchestratorInterface | ✅ Complete | Interface for backend checkout accounting |
| STRP-204 | Implement CheckoutOrchestrator | ✅ Complete | Implementation with event dispatch, registered in DI |

#### Files Created

```
src/Component/Service/
├── Result/
│   ├── CheckoutResult.php              # Immutable result for checkout process
│   └── OrderConfirmationResult.php     # Immutable result for order confirmation
├── CheckoutOrchestratorInterface.php   # Interface for checkout orchestration
└── CheckoutOrchestrator.php            # Implementation

tests/Unit/Component/Service/
├── Result/
│   ├── CheckoutResultTest.php          # 6 tests
│   └── OrderConfirmationResultTest.php # 8 tests
└── CheckoutOrchestratorTest.php        # 10 tests
```

#### Files Modified

| File | Changes |
|------|---------|
| `services.yaml` | Added CheckoutOrchestratorInterface DI registration |

#### Test Results

```
PHPUnit 11.5.44
Tests: 667, Assertions: 1552, Skipped: 1
Status: OK (24 new tests added)
```

#### DI Verification

```bash
docker compose exec -T php bash -c "cd /var/www/source && php -r \"
require 'bootstrap.php';
\\\$container = \\OxidEsales\\EshopCommunity\\Internal\\Container\\ContainerFactory::getInstance()->getContainer();
\\\$orchestrator = \\\$container->get(\\OxidSolutionCatalysts\\Payments\\Component\\Service\\CheckoutOrchestratorInterface::class);
echo 'CheckoutOrchestrator loaded: ' . get_class(\\\$orchestrator);
\""

# Output: CheckoutOrchestrator loaded: OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestrator
```

#### Key Design Decisions

1. **Readonly value objects**: Both `CheckoutResult` and `OrderConfirmationResult` are immutable (PHP 8.2+ readonly classes)
2. **Factory methods**: `success()` and `failure()` static methods for clear intent
3. **State constants**: `OrderConfirmationResult` includes state constants matching contract state machine
4. **No Stripe API**: Orchestrator handles backend accounting only - payment happens on frontend
5. **Event-driven**: Uses existing `EventDispatcher` and events (`PaymentInitiatedEvent`, `OrderCompletedEvent`)

---

### Sprint 3: Controller Integration - COMPLETED

**Completion Date:** 2025-11-26
**Status:** ✅ All tickets completed

| Ticket | Title | Status | Notes |
|--------|-------|--------|-------|
| STRP-301 | Update OrderController with Event Dispatch | ✅ Complete | Stripe payment detection, orchestrator integration, session handling |
| STRP-302 | Update ThankyouController with Event Dispatch | ✅ Complete | Order confirmation, session cleanup, error logging |

#### Files Modified

| File | Changes |
|------|---------|
| `src/Component/Controller/Http/OrderController.php` | Added `ServiceContainer` trait, `isStripePaymentMethod()`, orchestrator integration, session contract storage |
| `src/Component/Controller/Http/ThankyouController.php` | Added `ServiceContainer` trait, `confirmStripeOrderCompletion()`, session cleanup, logging |

#### Files Created

```
tests/Unit/Component/Controller/Http/
├── OrderControllerTest.php      # 10 tests for OrderController
└── ThankyouControllerTest.php   # 8 tests for ThankyouController
```

#### Test Results

```
PHPUnit 11.5.44
Tests: 685, Assertions: 1590, Skipped: 1
Status: OK (18 new tests added)
```

#### Key Design Decisions

1. **ServiceContainer trait**: Uses existing trait for DI mock support in tests
2. **Protected methods**: `getSession()`, `executeParent()`, `renderParent()` extracted for testability
3. **Non-breaking errors**: ThankyouController catches exceptions to never break the thankyou page
4. **Session cleanup**: Only on success - failed confirmations preserve session for debugging
5. **Stripe prefix detection**: `isStripePaymentMethod()` checks for `stripe_` prefix

---

### Sprint 4: Integration Tests & Cleanup - COMPLETED

**Completion Date:** 2025-11-26
**Status:** ✅ All tickets completed

| Ticket | Title | Status | Notes |
|--------|-------|--------|-------|
| STRP-401 | Create Integration Tests for Checkout Flow | ✅ Complete | 13 integration tests for DI wiring, event dispatch, orchestrator |
| STRP-402 | Final Cleanup and Documentation | ✅ Complete | All tests pass, module activates, documentation updated |

#### Files Created

```
tests/Integration/Component/Controller/
└── CheckoutFlowIntegrationTest.php   # 13 integration tests
```

#### Test Results

```
PHPUnit 11.5.44
Unit Tests: 685, Assertions: 1590, Skipped: 1
Integration Tests (new): 13, Assertions: 37
Status: OK
```

#### Integration Tests Coverage

| Test | Description |
|------|-------------|
| testCheckoutOrchestrator_IsRegisteredInContainer | Verifies DI registration |
| testEventDispatcher_IsRegisteredInContainer | Verifies EventDispatcher DI |
| testCheckoutOrchestrator_HasEventDispatcherInjected | Verifies dependency injection |
| testProcessCheckout_WithEmptyBasket_ReturnsFailure | Validation test |
| testProcessCheckout_WithInvalidUser_ReturnsFailure | Validation test |
| testProcessCheckout_WithValidData_DispatchesEvent | Event dispatch test |
| testConfirmOrderCompletion_WithoutContractId_ReturnsFailure | Validation test |
| testConfirmOrderCompletion_WithContractId_DispatchesEvent | Event dispatch test |
| testEventDispatcher_DispatchesPaymentInitiatedEvent | Event test |
| testCheckoutResultValueObject_SuccessFactory | Value object test |
| testCheckoutResultValueObject_FailureFactory | Value object test |
| testOrderConfirmationResultValueObject_SuccessWithCommittedState | Value object test |
| testOrderConfirmationResultValueObject_SuccessWithFulfilledState | Value object test |

#### Module Verification

```bash
# Module activation successful
docker compose exec -T php bin/oe-console oe:module:deactivate osc_stripe_wallet
docker compose exec -T php bin/oe-console oe:module:activate osc_stripe_wallet
# Output: Module - "osc_stripe_wallet" was activated.
```

---

## Sprint Overview

| Sprint | Focus | Duration | Tickets |
|--------|-------|----------|---------|
| **Sprint 1** | Event System DI Wiring | 1 day | 3 tickets |
| **Sprint 2** | Value Objects & Orchestrator | 1 day | 4 tickets |
| **Sprint 3** | Controller Integration | 0.5 day | 2 tickets |
| **Sprint 4** | Integration Tests & Cleanup | 0.5 day | 2 tickets |

**Total Estimated Duration:** 3 days

---

## CI/CD Reference

### GitHub Actions Workflows (from `.github/workflows/development.yml`)

| Job | Purpose | Matrix |
|-----|---------|--------|
| `install_shop_with_module` | Installs OXID shop with module | PHP 8.2, MySQL 5.7 |
| `styles` | Runs pre-commit style checks | PHP 8.2 |
| `isolated_unit_tests` | Runs isolated unit tests | PHP 8.2, 8.3, 8.4 |
| `integration_tests` | Runs integration tests with shop | PHP 8.2/8.3, MySQL 5.7/8.1 |

### Test Commands Quick Reference

```bash
# ============================================
# UNIT TESTS (Isolated, no DB required)
# ============================================

# Run all unit tests
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"

# Run specific unit test file
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Unit/Component/EventSystem/EventListenerProviderTest.php

# ============================================
# INTEGRATION TESTS (With shop bootstrap)
# ============================================

# Run all integration tests
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --testsuite Integration \
  --bootstrap=/var/www/source/bootstrap.php \
  --exclude-group migration

# ============================================
# ALL TESTS
# ============================================

# Run all tests with shop bootstrap
docker compose exec -w /var/www/extensions/stripe -T php \
  vendor/bin/phpunit -c tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php

# ============================================
# STYLE CHECKS
# ============================================

# Run pre-commit checks (all style + tests)
./source/extensions/stripe/bin/pre-commit-check.sh

# Run style-commit only (no tests)
docker compose exec -w /var/www/extensions/stripe -T php composer style-commit

# Run PHPStan
docker compose exec -w /var/www/extensions/stripe -T php composer run phpstan

# Run PHP CS Fixer
docker compose exec -w /var/www/extensions/stripe -T php composer run phpcs src

# ============================================
# MODULE COMMANDS
# ============================================

# Clear cache
docker compose exec -T php bin/oe-console oe:cache:clear

# Deactivate module
docker compose exec -T php bin/oe-console oe:module:deactivate osc_stripe_wallet

# Activate module
docker compose exec -T php bin/oe-console oe:module:activate osc_stripe_wallet

# Full reactivation
docker compose exec -T php bin/oe-console oe:module:deactivate osc_stripe_wallet && \
docker compose exec -T php bin/oe-console oe:module:activate osc_stripe_wallet
```

---

## Sprint Documents

1. [SPRINT-1-EVENT-SYSTEM.md](./SPRINT-1-EVENT-SYSTEM.md) - Event System DI Wiring
2. [SPRINT-2-ORCHESTRATOR.md](./SPRINT-2-ORCHESTRATOR.md) - Value Objects & Orchestrator
3. [SPRINT-3-CONTROLLERS.md](./SPRINT-3-CONTROLLERS.md) - Controller Integration
4. [SPRINT-4-INTEGRATION.md](./SPRINT-4-INTEGRATION.md) - Integration Tests & Cleanup

---

## Ticket Summary

### Sprint 1: Event System DI Wiring

| Ticket | Title | Priority | Est. |
|--------|-------|----------|------|
| STRP-101 | Create EventListenerProviderInterface | High | 1h |
| STRP-102 | Implement EventListenerProvider | High | 2h |
| STRP-103 | Update services.yaml for Event System | High | 1h |

### Sprint 2: Value Objects & Orchestrator

| Ticket | Title | Priority | Est. |
|--------|-------|----------|------|
| STRP-201 | Create CheckoutResult Value Object | High | 1h |
| STRP-202 | Create OrderConfirmationResult Value Object | High | 1h |
| STRP-203 | Create CheckoutOrchestratorInterface | High | 1h |
| STRP-204 | Implement CheckoutOrchestrator | High | 3h |

### Sprint 3: Controller Integration

| Ticket | Title | Priority | Est. |
|--------|-------|----------|------|
| STRP-301 | Update OrderController with Event Dispatch | High | 2h |
| STRP-302 | Update ThankyouController with Event Dispatch | High | 2h |

### Sprint 4: Integration Tests & Cleanup

| Ticket | Title | Priority | Est. |
|--------|-------|----------|------|
| STRP-401 | Create Integration Tests for Checkout Flow | Medium | 3h |
| STRP-402 | Final Cleanup and Documentation | Low | 1h |

---

## Dependencies Graph

```
STRP-101 ──┬──▶ STRP-102 ──▶ STRP-103
           │
           │    STRP-201 ──┬──▶ STRP-203 ──▶ STRP-204
           │    STRP-202 ──┘
           │
           └────────────────────────────────▶ STRP-301
                                             STRP-302
                                                │
                                                ▼
                                             STRP-401 ──▶ STRP-402
```

---

## Definition of Done (DoD)

Each ticket is considered done when:

- [ ] Unit tests written and passing
- [ ] Code coverage >= 95% for new code
- [ ] PHPStan level 8 passing
- [ ] PHP CS Fixer passing
- [ ] Code reviewed (self or peer)
- [ ] Documentation updated if applicable

---

## Test Directory Structure

```
tests/
├── phpunit.xml              # PHPUnit configuration
├── bootstrap.php            # Test bootstrap
├── Unit/                    # Unit tests (no DB, fast)
│   ├── Component/
│   │   ├── EventSystem/
│   │   │   ├── EventListenerProviderTest.php      # STRP-102
│   │   │   └── ...
│   │   ├── Service/
│   │   │   ├── CheckoutOrchestratorTest.php       # STRP-204
│   │   │   └── Result/
│   │   │       ├── CheckoutResultTest.php         # STRP-201
│   │   │       └── OrderConfirmationResultTest.php # STRP-202
│   │   └── Controller/
│   │       └── Http/
│   │           ├── OrderControllerTest.php        # STRP-301
│   │           └── ThankyouControllerTest.php     # STRP-302
│   └── ...
└── Integration/             # Integration tests (with DB, shop bootstrap)
    ├── Component/
    │   └── Controller/
    │       └── CheckoutFlowIntegrationTest.php    # STRP-401
    └── ...
```

---

## CI/CD Checklist (Before Merge)

Before merging, verify these CI/CD checks will pass:

| Check | Command | Status |
|-------|---------|--------|
| Pre-commit style | `./bin/pre-commit-check.sh` | ☐ |
| Unit tests (PHP 8.2) | `--testsuite Unit` | ☐ |
| Unit tests (PHP 8.3) | `--testsuite Unit` | ☐ |
| Unit tests (PHP 8.4) | `--testsuite Unit` | ☐ |
| Integration (PHP 8.2, MySQL 5.7) | `--testsuite Integration` | ☐ |
| Integration (PHP 8.2, MySQL 8.1) | `--testsuite Integration` | ☐ |
| Integration (PHP 8.3, MySQL 5.7) | `--testsuite Integration` | ☐ |
| Integration (PHP 8.3, MySQL 8.1) | `--testsuite Integration` | ☐ |
| Module activation | `oe:module:activate` | ☐ |

---

## Progress Tracking

| Date | Sprint | Status | Notes |
|------|--------|--------|-------|
| 2025-11-26 | Sprint 1 | ✅ Completed | Event System DI Wiring - all 3 tickets done |
| 2025-11-26 | Sprint 2 | ✅ Completed | Value Objects & Orchestrator - all 4 tickets done |
| 2025-11-26 | Sprint 3 | ✅ Completed | Controller Integration - all 2 tickets done |
| 2025-11-26 | Sprint 4 | ✅ Completed | Integration Tests & Cleanup - all 2 tickets done |

---

## Implementation Complete

All 4 sprints completed on 2025-11-26. Total: 11 tickets implemented.

### Final Test Summary

```
Unit Tests: 685 (18 new for controllers)
Integration Tests: 13 new for checkout flow
Total New Tests: 31
All tests passing
```

### Files Created (Sprint 1-4)

```
src/Component/
├── EventSystem/
│   ├── EventListenerProviderInterface.php
│   └── EventListenerProvider.php
├── Service/
│   ├── CheckoutOrchestratorInterface.php
│   ├── CheckoutOrchestrator.php
│   └── Result/
│       ├── CheckoutResult.php
│       └── OrderConfirmationResult.php
└── Controller/Http/
    ├── OrderController.php (modified)
    └── ThankyouController.php (modified)

tests/
├── Unit/Component/
│   ├── Controller/Http/
│   │   ├── OrderControllerTest.php
│   │   └── ThankyouControllerTest.php
│   ├── Service/
│   │   ├── CheckoutOrchestratorTest.php
│   │   └── Result/
│   │       ├── CheckoutResultTest.php
│   │       └── OrderConfirmationResultTest.php
│   └── EventSystem/
│       ├── EventListenerProviderInterfaceTest.php
│       └── EventListenerProviderTest.php
└── Integration/Component/Controller/
    └── CheckoutFlowIntegrationTest.php
```

### Next Steps

1. Enable event handlers in `services.yaml` when their dependencies are ready
2. Implement webhook handler integration
3. Add admin UI for contract management
4. Monitor production deployment
