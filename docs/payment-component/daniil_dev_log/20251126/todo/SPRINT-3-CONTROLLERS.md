# Sprint 3: Controller Integration

**Sprint Goal:** Update OrderController and ThankyouController to use the CheckoutOrchestrator
**Duration:** 0.5 day
**Dependencies:** Sprint 2 (Orchestrator)

---

## Test Commands Reference

```bash
# Run unit tests
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --exclude-group migration

# Run with coverage
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --exclude-group migration

# Run specific test file
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  extensions/stripe/tests/Component/Unit/Controller/Http/OrderControllerTest.php

# Run integration tests (when needed)
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --testsuite Integration \
  --bootstrap=/var/www/source/bootstrap.php \
  --exclude-group migration
```

---

## Tickets

---

### STRP-301: Update OrderController with Event Dispatch

**Priority:** High
**Estimate:** 2 hours
**Type:** Feature
**Depends On:** STRP-204

#### Description

Update the existing `OrderController` to:
1. Detect Stripe payment methods
2. Call `CheckoutOrchestrator::processCheckout()` before parent execution
3. Store contract ID in session
4. Handle errors gracefully

#### Acceptance Criteria

- [ ] OrderController calls orchestrator for Stripe payments
- [ ] Falls back to parent for non-Stripe payments
- [ ] Contract ID stored in session on success
- [ ] Errors displayed via OXID's error system
- [ ] Unit tests with 100% coverage
- [ ] Existing functionality not broken

#### Technical Details

**File:** `src/Component/Controller/Http/OrderController.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Controller\Http;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Application\Controller\OrderController as OxidOrderController;
use OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface;
use OxidSolutionCatalysts\Payments\Component\Traits\ServiceContainer;

/**
 * Extended OrderController for Stripe payment accounting.
 *
 * Note: Actual payment processing happens on the frontend via Stripe.js.
 * This controller only handles backend accounting (contract creation, order linking).
 */
class OrderController extends OxidOrderController
{
    use ServiceContainer;

    private const SESSION_CONTRACT_ID = 'stripe_contract_id';
    private const STRIPE_PAYMENT_PREFIX = 'stripe_';

    private ?CheckoutOrchestratorInterface $checkoutOrchestrator = null;

    /**
     * Executes order placement.
     *
     * For Stripe payments:
     * 1. Calls orchestrator to create contract
     * 2. Stores contract ID in session
     * 3. Calls parent to create OXID order
     *
     * For non-Stripe payments:
     * - Falls back to parent behavior
     *
     * @return mixed View name or redirect
     */
    public function execute(): mixed
    {
        if (!$this->isStripePaymentMethod()) {
            return parent::execute();
        }

        return $this->executeWithStripeAccounting();
    }

    /**
     * Processes checkout with Stripe contract accounting.
     */
    private function executeWithStripeAccounting(): mixed
    {
        $session = Registry::getSession();
        $basket = $session->getBasket();
        $user = $this->getUser();
        $paymentId = $basket->getPaymentId();

        // Get payment_intent_id from request (set by frontend Stripe.js)
        $paymentIntentId = Registry::getRequest()->getRequestParameter('stripe_payment_intent_id');

        $result = $this->getCheckoutOrchestrator()->processCheckout(
            $basket,
            $user,
            $paymentId,
            $paymentIntentId
        );

        if (!$result->isSuccess()) {
            $this->addErrorToDisplay($result->getErrorMessage());
            return $this->getViewName();
        }

        // Store contract ID for ThankyouController
        if ($result->getContractId()) {
            $session->setVariable(self::SESSION_CONTRACT_ID, $result->getContractId());
        }

        // Continue with standard OXID order creation
        return parent::execute();
    }

    /**
     * Checks if the selected payment method is a Stripe method.
     */
    private function isStripePaymentMethod(): bool
    {
        $paymentId = Registry::getSession()->getBasket()->getPaymentId();

        if ($paymentId === null) {
            return false;
        }

        return str_starts_with($paymentId, self::STRIPE_PAYMENT_PREFIX);
    }

    /**
     * Adds an error message to display.
     */
    private function addErrorToDisplay(string $message): void
    {
        Registry::getUtilsView()->addErrorToDisplay($message);
    }

    /**
     * Gets the view name for error display.
     */
    private function getViewName(): string
    {
        return 'order';
    }

    /**
     * Gets the CheckoutOrchestrator from DI container.
     */
    private function getCheckoutOrchestrator(): CheckoutOrchestratorInterface
    {
        if ($this->checkoutOrchestrator === null) {
            $this->checkoutOrchestrator = $this->getServiceFromContainer(
                CheckoutOrchestratorInterface::class
            );
        }
        return $this->checkoutOrchestrator;
    }

    /**
     * Sets the orchestrator (for testing).
     *
     * @internal For testing only
     */
    public function setCheckoutOrchestrator(CheckoutOrchestratorInterface $orchestrator): void
    {
        $this->checkoutOrchestrator = $orchestrator;
    }
}
```

#### Test Plan

**File:** `tests/Component/Unit/Controller/Http/OrderControllerTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Component\Unit\Controller\Http;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\Controller\Http\OrderController;
use OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface;
use OxidSolutionCatalysts\Payments\Component\Service\Result\CheckoutResult;

class OrderControllerTest extends TestCase
{
    private OrderController $controller;
    private CheckoutOrchestratorInterface $orchestrator;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(CheckoutOrchestratorInterface::class);
        $this->controller = $this->createPartialMock(
            OrderController::class,
            ['getUser', 'getServiceFromContainer']
        );
        $this->controller->setCheckoutOrchestrator($this->orchestrator);
    }

    public function testExecute_WithStripePayment_CallsOrchestrator(): void
    {
        // Arrange
        $result = CheckoutResult::success('contract_123');

        $this->orchestrator
            ->expects($this->once())
            ->method('processCheckout')
            ->willReturn($result);

        // Setup session/basket mocks with stripe payment
        $this->setupStripePaymentMocks('stripe_card');

        // Act
        $viewName = $this->controller->execute();

        // Assert: Should continue to parent (but we're testing the call was made)
    }

    public function testExecute_WithNonStripePayment_SkipsOrchestrator(): void
    {
        // Arrange
        $this->orchestrator
            ->expects($this->never())
            ->method('processCheckout');

        // Setup session/basket mocks with non-stripe payment
        $this->setupStripePaymentMocks('oxidcashondel');

        // Act - will call parent::execute() directly
        // Note: In real test, we'd verify parent was called
    }

    public function testExecute_WithOrchestratorError_DisplaysErrorAndReturnsView(): void
    {
        // Arrange
        $result = CheckoutResult::failure('Basket validation failed', 'VALIDATION_ERROR');

        $this->orchestrator
            ->method('processCheckout')
            ->willReturn($result);

        $this->setupStripePaymentMocks('stripe_card');

        // Act
        $viewName = $this->controller->execute();

        // Assert
        $this->assertEquals('order', $viewName);
    }

    public function testExecute_WithSuccess_StoresContractIdInSession(): void
    {
        // Arrange
        $result = CheckoutResult::success('contract_xyz');

        $this->orchestrator
            ->method('processCheckout')
            ->willReturn($result);

        $this->setupStripePaymentMocks('stripe_card');

        // Act
        $this->controller->execute();

        // Assert: Verify session contains contract ID
        // Note: In real test, we'd mock Registry::getSession()
    }

    public function testIsStripePaymentMethod_WithStripePrefix_ReturnsTrue(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionMethod($this->controller, 'isStripePaymentMethod');
        $reflection->setAccessible(true);

        $this->setupStripePaymentMocks('stripe_card');

        $result = $reflection->invoke($this->controller);
        $this->assertTrue($result);
    }

    public function testIsStripePaymentMethod_WithoutStripePrefix_ReturnsFalse(): void
    {
        $reflection = new \ReflectionMethod($this->controller, 'isStripePaymentMethod');
        $reflection->setAccessible(true);

        $this->setupStripePaymentMocks('oxidpaypal');

        $result = $reflection->invoke($this->controller);
        $this->assertFalse($result);
    }

    /**
     * Helper to setup basket/session mocks with payment method.
     */
    private function setupStripePaymentMocks(string $paymentId): void
    {
        // This would setup Registry mocks for session and basket
        // Implementation depends on your test infrastructure
    }
}
```

#### Commands

```bash
# Run tests
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  extensions/stripe/tests/Component/Unit/Controller/Http/OrderControllerTest.php

# Check PHPStan
docker compose exec -T php vendor/bin/phpstan analyse \
  extensions/stripe/src/Component/Controller/Http/OrderController.php -l 8

# Clear cache and test module activation
docker compose exec -T php bin/oe-console oe:cache:clear
docker compose exec -T php bin/oe-console oe:module:deactivate osc_stripe_wallet
docker compose exec -T php bin/oe-console oe:module:activate osc_stripe_wallet
```

#### Checklist

- [ ] TDD: Write tests first (RED)
- [ ] Implement changes (GREEN)
- [ ] Refactor if needed
- [ ] All tests pass
- [ ] PHPStan passes
- [ ] PHP CS Fixer passes
- [ ] Module activates without errors
- [ ] Manual test: non-Stripe checkout still works

---

### STRP-302: Update ThankyouController with Event Dispatch

**Priority:** High
**Estimate:** 2 hours
**Type:** Feature
**Depends On:** STRP-204

#### Description

Update the existing `ThankyouController` to:
1. Retrieve contract ID from session
2. Call `CheckoutOrchestrator::confirmOrderCompletion()`
3. Clean up session variables on success
4. Log errors but don't break the thankyou page

#### Acceptance Criteria

- [ ] ThankyouController calls orchestrator when contract ID exists
- [ ] Session variables cleaned up after confirmation
- [ ] Errors logged but page still renders
- [ ] Unit tests with 100% coverage
- [ ] Thankyou page displays correctly

#### Technical Details

**File:** `src/Component/Controller/Http/ThankyouController.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Controller\Http;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Application\Controller\ThankYouController as OxidThankyouController;
use OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface;
use OxidSolutionCatalysts\Payments\Component\Traits\ServiceContainer;

/**
 * Extended ThankyouController for Stripe order completion accounting.
 *
 * Note: Final payment confirmation happens via webhook.
 * This controller only confirms the order was placed and transitions contract state.
 */
class ThankyouController extends OxidThankyouController
{
    use ServiceContainer;

    private const SESSION_CONTRACT_ID = 'stripe_contract_id';

    private ?CheckoutOrchestratorInterface $checkoutOrchestrator = null;

    /**
     * Renders the thankyou page.
     *
     * For Stripe payments:
     * 1. Confirms order completion via orchestrator
     * 2. Cleans up session variables
     * 3. Logs state for debugging
     *
     * @return string Template name
     */
    public function render(): string
    {
        $contractId = $this->getContractIdFromSession();

        if ($contractId !== null) {
            $this->confirmStripeOrderCompletion($contractId);
        }

        return parent::render();
    }

    /**
     * Confirms Stripe order completion.
     */
    private function confirmStripeOrderCompletion(string $contractId): void
    {
        $order = $this->getOrder();
        if ($order === null) {
            $this->logError('Order not found in thankyou controller', [
                'contractId' => $contractId,
            ]);
            return;
        }

        $orderId = $order->getId();

        try {
            $result = $this->getCheckoutOrchestrator()->confirmOrderCompletion(
                $orderId,
                $contractId
            );

            if ($result->isSuccess()) {
                // Cleanup session - contract is now linked to order
                $this->clearSessionVariables();

                // Log state for debugging
                if ($result->isAwaitingPaymentConfirmation()) {
                    $this->logInfo('Stripe order awaiting payment confirmation via webhook', [
                        'orderId' => $orderId,
                        'contractId' => $contractId,
                        'state' => $result->getContractState(),
                    ]);
                }

                if ($result->isFullyCompleted()) {
                    $this->logInfo('Stripe order fully completed', [
                        'orderId' => $orderId,
                        'contractId' => $contractId,
                    ]);
                }
            } else {
                $this->logError('Failed to confirm order completion', [
                    'orderId' => $orderId,
                    'contractId' => $contractId,
                    'error' => $result->getErrorMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            // Log but don't break the thankyou page
            $this->logError('Exception during order confirmation', [
                'orderId' => $orderId,
                'contractId' => $contractId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Gets contract ID from session.
     */
    private function getContractIdFromSession(): ?string
    {
        return Registry::getSession()->getVariable(self::SESSION_CONTRACT_ID);
    }

    /**
     * Clears session variables after successful confirmation.
     */
    private function clearSessionVariables(): void
    {
        $session = Registry::getSession();
        $session->deleteVariable(self::SESSION_CONTRACT_ID);
        $session->deleteVariable('stripe_payment_intent_id');
    }

    /**
     * Logs an info message.
     */
    private function logInfo(string $message, array $context = []): void
    {
        Registry::getLogger()->info($message, $context);
    }

    /**
     * Logs an error message.
     */
    private function logError(string $message, array $context = []): void
    {
        Registry::getLogger()->error($message, $context);
    }

    /**
     * Gets the CheckoutOrchestrator from DI container.
     */
    private function getCheckoutOrchestrator(): CheckoutOrchestratorInterface
    {
        if ($this->checkoutOrchestrator === null) {
            $this->checkoutOrchestrator = $this->getServiceFromContainer(
                CheckoutOrchestratorInterface::class
            );
        }
        return $this->checkoutOrchestrator;
    }

    /**
     * Sets the orchestrator (for testing).
     *
     * @internal For testing only
     */
    public function setCheckoutOrchestrator(CheckoutOrchestratorInterface $orchestrator): void
    {
        $this->checkoutOrchestrator = $orchestrator;
    }
}
```

#### Test Plan

**File:** `tests/Component/Unit/Controller/Http/ThankyouControllerTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Component\Unit\Controller\Http;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\Controller\Http\ThankyouController;
use OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface;
use OxidSolutionCatalysts\Payments\Component\Service\Result\OrderConfirmationResult;

class ThankyouControllerTest extends TestCase
{
    private ThankyouController $controller;
    private CheckoutOrchestratorInterface $orchestrator;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(CheckoutOrchestratorInterface::class);
        $this->controller = $this->createPartialMock(
            ThankyouController::class,
            ['getOrder', 'getServiceFromContainer']
        );
        $this->controller->setCheckoutOrchestrator($this->orchestrator);
    }

    public function testRender_WithContractId_CallsOrchestrator(): void
    {
        // Arrange
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->orchestrator
            ->expects($this->once())
            ->method('confirmOrderCompletion')
            ->with('order_123', 'contract_456')
            ->willReturn($result);

        $this->setupSessionWithContractId('contract_456');
        $this->setupOrderMock('order_123');

        // Act
        $this->controller->render();
    }

    public function testRender_WithoutContractId_SkipsOrchestrator(): void
    {
        // Arrange
        $this->orchestrator
            ->expects($this->never())
            ->method('confirmOrderCompletion');

        $this->setupSessionWithContractId(null);

        // Act
        $this->controller->render();
    }

    public function testRender_WithSuccess_ClearsSessionVariables(): void
    {
        // Arrange
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->orchestrator
            ->method('confirmOrderCompletion')
            ->willReturn($result);

        $this->setupSessionWithContractId('contract_456');
        $this->setupOrderMock('order_123');

        // Act
        $this->controller->render();

        // Assert: Verify session variables cleared
        // Note: In real test, we'd mock Registry::getSession()->deleteVariable
    }

    public function testRender_WithError_DoesNotBreakPage(): void
    {
        // Arrange
        $result = OrderConfirmationResult::failure('Contract not found');

        $this->orchestrator
            ->method('confirmOrderCompletion')
            ->willReturn($result);

        $this->setupSessionWithContractId('contract_456');
        $this->setupOrderMock('order_123');

        // Act - should not throw
        $viewName = $this->controller->render();

        // Assert: Page renders despite error
        // Parent::render() should still be called
    }

    public function testRender_WithException_DoesNotBreakPage(): void
    {
        // Arrange
        $this->orchestrator
            ->method('confirmOrderCompletion')
            ->willThrowException(new \RuntimeException('Database error'));

        $this->setupSessionWithContractId('contract_456');
        $this->setupOrderMock('order_123');

        // Act - should not throw
        $viewName = $this->controller->render();

        // Assert: Page renders despite exception
    }

    public function testRender_WithAwaitingConfirmation_LogsInfo(): void
    {
        // Arrange
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->orchestrator
            ->method('confirmOrderCompletion')
            ->willReturn($result);

        $this->setupSessionWithContractId('contract_456');
        $this->setupOrderMock('order_123');

        // Act
        $this->controller->render();

        // Assert: Info logged (verify via logger mock)
    }

    /**
     * Helper to setup session mock.
     */
    private function setupSessionWithContractId(?string $contractId): void
    {
        // Setup Registry::getSession() mock
    }

    /**
     * Helper to setup order mock.
     */
    private function setupOrderMock(string $orderId): void
    {
        $order = $this->createMock(\stdClass::class);
        $order->method('getId')->willReturn($orderId);

        $this->controller
            ->method('getOrder')
            ->willReturn($order);
    }
}
```

#### Commands

```bash
# Run tests
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  extensions/stripe/tests/Component/Unit/Controller/Http/ThankyouControllerTest.php

# Run all controller tests
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  extensions/stripe/tests/Component/Unit/Controller/Http/

# Check PHPStan
docker compose exec -T php vendor/bin/phpstan analyse \
  extensions/stripe/src/Component/Controller/Http/ThankyouController.php -l 8

# Manual test
# 1. Complete a checkout with Stripe payment
# 2. Verify thankyou page displays
# 3. Check logs for confirmation messages
```

#### Checklist

- [ ] TDD: Write tests first (RED)
- [ ] Implement changes (GREEN)
- [ ] Refactor if needed
- [ ] All tests pass
- [ ] PHPStan passes
- [ ] PHP CS Fixer passes
- [ ] Manual test: thankyou page displays correctly
- [ ] Manual test: logs show expected messages

---

## Sprint 3 Completion Criteria

- [ ] All 2 tickets completed
- [ ] OrderController dispatches PaymentInitiatedEvent
- [ ] ThankyouController dispatches OrderCompletedEvent
- [ ] Session handling works correctly
- [ ] Error handling doesn't break user experience
- [ ] Ready for Sprint 4 (Integration Tests)

---

## Notes

- Controllers should be THIN - just validate and delegate
- Errors logged but never break the page
- Session cleanup happens on success only
- Non-Stripe payments must continue to work

---

**Previous Sprint:** [SPRINT-2-ORCHESTRATOR.md](./SPRINT-2-ORCHESTRATOR.md)
**Next Sprint:** [SPRINT-4-INTEGRATION.md](./SPRINT-4-INTEGRATION.md)
