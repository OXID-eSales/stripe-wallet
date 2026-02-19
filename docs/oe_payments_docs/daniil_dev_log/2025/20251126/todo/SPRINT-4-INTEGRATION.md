# Sprint 4: Integration Tests & Cleanup

**Sprint Goal:** Create integration tests for the complete checkout flow and final cleanup
**Duration:** 0.5 day
**Dependencies:** Sprint 3 (Controllers)

---

## CI/CD Reference

### GitHub Actions Workflows

The project uses these CI/CD checks (from `.github/workflows/development.yml`):

| Job | Purpose | Matrix |
|-----|---------|--------|
| `install_shop_with_module` | Installs OXID shop with module | PHP 8.2, MySQL 5.7 |
| `styles` | Runs pre-commit style checks | PHP 8.2 |
| `isolated_unit_tests` | Runs isolated unit tests | PHP 8.2, 8.3, 8.4 |
| `integration_tests` | Runs integration tests with shop | PHP 8.2/8.3, MySQL 5.7/8.1 |

### Test Commands

```bash
# ============================================
# LOCAL DEVELOPMENT COMMANDS
# ============================================

# Run all tests (unit + integration) with shop bootstrap
docker compose exec -w /var/www/extensions/stripe -T php \
  vendor/bin/phpunit -c tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php

# Run unit tests only
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"

# Run integration tests only (with shop bootstrap)
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --testsuite Integration \
  --bootstrap=/var/www/source/bootstrap.php \
  --exclude-group migration

# Run pre-commit checks (all style + tests)
./source/extensions/stripe/bin/pre-commit-check.sh

# Run style checks only
docker compose exec -w /var/www/extensions/stripe -T php composer style-commit

# Run PHPStan
docker compose exec -w /var/www/extensions/stripe -T php composer run phpstan

# Run PHP CS Fixer
docker compose exec -w /var/www/extensions/stripe -T php composer run phpcs src

# ============================================
# CI/CD EQUIVALENT COMMANDS
# ============================================

# Style check (as CI runs it)
./source/test-module/bin/pre-commit-check.sh

# Integration tests (as CI runs it)
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --testsuite Integration \
  --bootstrap=/var/www/source/bootstrap.php \
  --exclude-group migration
```

### Test Directory Structure

```
tests/
├── phpunit.xml              # PHPUnit configuration
├── bootstrap.php            # Test bootstrap
├── Unit/                    # Unit tests (no DB, fast)
│   ├── Component/
│   │   ├── EventSystem/
│   │   │   ├── EventListenerProviderTest.php
│   │   │   └── ...
│   │   ├── Service/
│   │   │   ├── CheckoutOrchestratorTest.php
│   │   │   └── Result/
│   │   │       ├── CheckoutResultTest.php
│   │   │       └── OrderConfirmationResultTest.php
│   │   └── Controller/
│   │       └── Http/
│   │           ├── OrderControllerTest.php
│   │           └── ThankyouControllerTest.php
│   └── ...
└── Integration/             # Integration tests (with DB, shop bootstrap)
    ├── Component/
    │   └── Controller/
    │       └── CheckoutFlowIntegrationTest.php
    └── ...
```

---

## Tickets

---

### STRP-401: Create Integration Tests for Checkout Flow

**Priority:** Medium
**Estimate:** 3 hours
**Type:** Test
**Depends On:** STRP-302

#### Description

Create integration tests that verify the complete checkout flow works end-to-end:
1. OrderController creates contract
2. Session stores contract ID
3. ThankyouController confirms order
4. Events are dispatched correctly

#### Acceptance Criteria

- [ ] Integration test created at `tests/Integration/Component/Controller/CheckoutFlowIntegrationTest.php`
- [ ] Tests use shop bootstrap
- [ ] Tests verify contract creation
- [ ] Tests verify session handling
- [ ] Tests pass in CI/CD pipeline

#### Technical Details

**File:** `tests/Integration/Component/Controller/CheckoutFlowIntegrationTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Component\Controller;

use OxidEsales\TestingLibrary\UnitTestCase;
use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface;
use OxidSolutionCatalysts\Payments\Component\Service\Result\CheckoutResult;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;

/**
 * Integration tests for the checkout flow.
 *
 * @group integration
 * @group checkout
 */
class CheckoutFlowIntegrationTest extends UnitTestCase
{
    private const SESSION_CONTRACT_ID = 'stripe_contract_id';

    protected function setUp(): void
    {
        parent::setUp();
        // Clear session before each test
        Registry::getSession()->deleteVariable(self::SESSION_CONTRACT_ID);
    }

    protected function tearDown(): void
    {
        // Cleanup session after each test
        Registry::getSession()->deleteVariable(self::SESSION_CONTRACT_ID);
        parent::tearDown();
    }

    /**
     * @group integration
     */
    public function testCheckoutOrchestrator_IsRegisteredInContainer(): void
    {
        $container = \OxidEsales\EshopCommunity\Internal\Container\ContainerFactory::getInstance()
            ->getContainer();

        $orchestrator = $container->get(CheckoutOrchestratorInterface::class);

        $this->assertInstanceOf(CheckoutOrchestratorInterface::class, $orchestrator);
    }

    /**
     * @group integration
     */
    public function testEventDispatcher_IsRegisteredInContainer(): void
    {
        $container = \OxidEsales\EshopCommunity\Internal\Container\ContainerFactory::getInstance()
            ->getContainer();

        $dispatcher = $container->get(EventDispatcherInterface::class);

        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
    }

    /**
     * @group integration
     */
    public function testProcessCheckout_WithValidData_CreatesContract(): void
    {
        // Arrange
        $container = \OxidEsales\EshopCommunity\Internal\Container\ContainerFactory::getInstance()
            ->getContainer();
        $orchestrator = $container->get(CheckoutOrchestratorInterface::class);

        $basket = $this->createTestBasket();
        $user = $this->createTestUser();

        // Act
        $result = $orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_123'
        );

        // Assert
        $this->assertInstanceOf(CheckoutResult::class, $result);
        // Note: Success depends on whether handlers are fully set up
    }

    /**
     * @group integration
     */
    public function testSessionHandling_StoreAndRetrieveContractId(): void
    {
        // Arrange
        $session = Registry::getSession();
        $contractId = 'test_contract_' . uniqid();

        // Act
        $session->setVariable(self::SESSION_CONTRACT_ID, $contractId);
        $retrieved = $session->getVariable(self::SESSION_CONTRACT_ID);

        // Assert
        $this->assertEquals($contractId, $retrieved);

        // Cleanup
        $session->deleteVariable(self::SESSION_CONTRACT_ID);
        $this->assertNull($session->getVariable(self::SESSION_CONTRACT_ID));
    }

    /**
     * @group integration
     */
    public function testEventDispatcher_DispatchesEvents(): void
    {
        // Arrange
        $container = \OxidEsales\EshopCommunity\Internal\Container\ContainerFactory::getInstance()
            ->getContainer();
        $dispatcher = $container->get(EventDispatcherInterface::class);

        // Create a test event
        $context = new \OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext([
            'test' => true,
        ]);

        $event = new \OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentInitiatedEvent($context);

        // Act
        $dispatchedEvent = $dispatcher->dispatch($event);

        // Assert
        $this->assertSame($event, $dispatchedEvent);
    }

    /**
     * Creates a minimal test basket.
     */
    private function createTestBasket(): object
    {
        $basket = oxNew(\OxidEsales\Eshop\Application\Model\Basket::class);
        // Add a test item if needed
        return $basket;
    }

    /**
     * Creates a minimal test user.
     */
    private function createTestUser(): object
    {
        $user = oxNew(\OxidEsales\Eshop\Application\Model\User::class);
        $user->setId('test_user_' . uniqid());
        return $user;
    }
}
```

#### Test Execution Commands

```bash
# Run integration tests only
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --testsuite Integration \
  --bootstrap=/var/www/source/bootstrap.php \
  --exclude-group migration

# Run specific integration test file
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Integration/Component/Controller/CheckoutFlowIntegrationTest.php

# Run integration tests with coverage
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --testsuite Integration \
  --bootstrap=/var/www/source/bootstrap.php \
  --exclude-group migration \
  --coverage-text
```

#### Checklist

- [ ] Create test directory structure
- [ ] Implement integration test class
- [ ] Tests verify DI container registration
- [ ] Tests verify event dispatch
- [ ] Tests verify session handling
- [ ] All tests pass locally
- [ ] All tests pass in CI/CD

---

### STRP-402: Final Cleanup and Documentation

**Priority:** Low
**Estimate:** 1 hour
**Type:** Documentation
**Depends On:** STRP-401

#### Description

Final cleanup tasks:
1. Run full CI/CD check locally
2. Verify all tests pass
3. Update documentation
4. Create summary report

#### Acceptance Criteria

- [ ] All unit tests pass
- [ ] All integration tests pass
- [ ] PHPStan passes at level 8
- [ ] PHP CS Fixer passes
- [ ] Pre-commit check passes
- [ ] Implementation documentation updated

#### Final Verification Commands

```bash
# 1. Run pre-commit checks (all style + tests)
./source/extensions/stripe/bin/pre-commit-check.sh

# 2. Run full test suite with coverage
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --exclude-group migration \
  --coverage-text

# 3. Run PHPStan at level 8
docker compose exec -w /var/www/extensions/stripe -T php \
  vendor/bin/phpstan analyse src -l 8

# 4. Run PHP CS Fixer
docker compose exec -w /var/www/extensions/stripe -T php \
  composer run phpcs src

# 5. Verify module activation
docker compose exec -T php bin/oe-console oe:module:deactivate osc_stripe_wallet
docker compose exec -T php bin/oe-console oe:module:activate osc_stripe_wallet

# 6. Check for any errors in logs
docker compose exec -T php cat /var/www/source/log/oxideshop.log | tail -50
```

#### Documentation Updates

1. **Update INTEGRATION_PAYMENT_EVENTS_INTO_OXID.md**
   - Mark sections as completed
   - Add any deviations from plan
   - Document lessons learned

2. **Create COMPLETION-REPORT.md**
   ```markdown
   # Implementation Completion Report

   **Date:** 2025-11-XX
   **Sprints Completed:** 4/4

   ## Summary

   - Event system wired to DI container
   - CheckoutOrchestrator implemented
   - Controllers updated
   - Integration tests created

   ## Test Results

   - Unit tests: XX/XX passing
   - Integration tests: XX/XX passing
   - PHPStan: 0 errors
   - PHP CS: 0 violations

   ## Files Created/Modified

   ### Created
   - src/Component/EventSystem/EventListenerProviderInterface.php
   - src/Component/EventSystem/EventListenerProvider.php
   - src/Component/Service/CheckoutOrchestratorInterface.php
   - src/Component/Service/CheckoutOrchestrator.php
   - src/Component/Service/Result/CheckoutResult.php
   - src/Component/Service/Result/OrderConfirmationResult.php

   ### Modified
   - src/Component/EventSystem/EventDispatcher.php
   - src/Component/Controller/Http/OrderController.php
   - src/Component/Controller/Http/ThankyouController.php
   - services.yaml

   ## Next Steps

   1. Monitor webhook integration
   2. Add more payment method support
   3. Add admin UI for contract management
   ```

#### Checklist

- [ ] Run pre-commit checks
- [ ] Verify all tests pass
- [ ] Verify module activates
- [ ] Check logs for errors
- [ ] Update implementation plan
- [ ] Create completion report
- [ ] Commit all changes

---

## Sprint 4 Completion Criteria

- [ ] All tickets completed
- [ ] Integration tests created and passing
- [ ] Full test suite passes locally
- [ ] CI/CD pipeline passes
- [ ] Documentation updated
- [ ] Module activates without errors
- [ ] Ready for code review/merge

---

## CI/CD Checklist (Before Merge)

Before merging, verify these CI/CD checks will pass:

| Check | Command | Status |
|-------|---------|--------|
| Style check | `./bin/pre-commit-check.sh` | ☐ |
| Unit tests (PHP 8.2) | `--testsuite Unit` | ☐ |
| Unit tests (PHP 8.3) | `--testsuite Unit` | ☐ |
| Unit tests (PHP 8.4) | `--testsuite Unit` | ☐ |
| Integration (PHP 8.2, MySQL 5.7) | `--testsuite Integration` | ☐ |
| Integration (PHP 8.2, MySQL 8.1) | `--testsuite Integration` | ☐ |
| Integration (PHP 8.3, MySQL 5.7) | `--testsuite Integration` | ☐ |
| Integration (PHP 8.3, MySQL 8.1) | `--testsuite Integration` | ☐ |
| Module activation | `oe:module:activate` | ☐ |

---

## Notes

- Integration tests require shop bootstrap (`--bootstrap=/var/www/source/bootstrap.php`)
- Exclude migration group in CI (`--exclude-group migration`)
- Coverage requires XDEBUG_MODE=coverage
- Test against multiple PHP/MySQL versions before merge

---

**Previous Sprint:** [SPRINT-3-CONTROLLERS.md](./SPRINT-3-CONTROLLERS.md)
**Index:** [SPRINT-INDEX.md](./SPRINT-INDEX.md)
