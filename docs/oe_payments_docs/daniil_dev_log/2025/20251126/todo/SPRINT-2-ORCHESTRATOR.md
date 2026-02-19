# Sprint 2: Value Objects & Orchestrator

**Sprint Goal:** Create the CheckoutOrchestrator service that coordinates event dispatch from OXID controllers
**Duration:** 1 day
**Dependencies:** Sprint 1 (Event System DI Wiring)

---

## Tickets

---

### STRP-201: Create CheckoutResult Value Object

**Priority:** High
**Estimate:** 1 hour
**Type:** Feature

#### Description

Create an immutable value object that represents the result of the checkout process. This encapsulates success/failure state, contract ID, and error information.

#### Acceptance Criteria

- [ ] Class created at `src/Component/Service/Result/CheckoutResult.php`
- [ ] Immutable (readonly properties)
- [ ] Unit tests with 100% coverage
- [ ] Factory methods for success/failure cases

#### Technical Details

**File:** `src/Component/Service/Result/CheckoutResult.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service\Result;

/**
 * Immutable value object representing checkout process result.
 */
readonly class CheckoutResult
{
    private function __construct(
        private bool $success,
        private ?string $contractId = null,
        private ?string $errorMessage = null,
        private ?string $errorCode = null
    ) {
    }

    /**
     * Creates a successful result.
     */
    public static function success(string $contractId): self
    {
        return new self(
            success: true,
            contractId: $contractId
        );
    }

    /**
     * Creates a failure result.
     */
    public static function failure(string $errorMessage, ?string $errorCode = null): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            errorCode: $errorCode
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getContractId(): ?string
    {
        return $this->contractId;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
```

#### Test Plan

**File:** `tests/Component/Unit/Service/Result/CheckoutResultTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Component\Unit\Service\Result;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\Service\Result\CheckoutResult;

class CheckoutResultTest extends TestCase
{
    public function testSuccess_CreatesSuccessfulResult(): void
    {
        $result = CheckoutResult::success('contract_123');

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_123', $result->getContractId());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testFailure_CreatesFailedResult(): void
    {
        $result = CheckoutResult::failure('Basket is empty', 'EMPTY_BASKET');

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getContractId());
        $this->assertEquals('Basket is empty', $result->getErrorMessage());
        $this->assertEquals('EMPTY_BASKET', $result->getErrorCode());
    }

    public function testFailure_WithoutErrorCode_AllowsNullCode(): void
    {
        $result = CheckoutResult::failure('Unknown error');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('Unknown error', $result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testIsImmutable(): void
    {
        $result = CheckoutResult::success('contract_123');

        // Verify readonly - this should not allow modification
        $reflection = new \ReflectionClass($result);
        $this->assertTrue($reflection->isReadOnly());
    }
}
```

#### Commands

```bash
# Create directory
mkdir -p /var/www/extensions/stripe/src/Component/Service/Result
mkdir -p /var/www/extensions/stripe/tests/Component/Unit/Service/Result

# Run tests
docker compose exec php bash -c "cd /var/www && vendor/bin/phpunit extensions/stripe/tests/Component/Unit/Service/Result/CheckoutResultTest.php"
```

#### Checklist

- [ ] TDD: Write tests first (RED)
- [ ] Implement class (GREEN)
- [ ] Tests pass
- [ ] PHPStan passes
- [ ] PHP CS Fixer passes

---

### STRP-202: Create OrderConfirmationResult Value Object

**Priority:** High
**Estimate:** 1 hour
**Type:** Feature

#### Description

Create an immutable value object that represents the result of order confirmation (from ThankyouController).

#### Acceptance Criteria

- [ ] Class created at `src/Component/Service/Result/OrderConfirmationResult.php`
- [ ] Immutable (readonly properties)
- [ ] Includes contract state information
- [ ] Unit tests with 100% coverage

#### Technical Details

**File:** `src/Component/Service/Result/OrderConfirmationResult.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service\Result;

/**
 * Immutable value object representing order confirmation result.
 */
readonly class OrderConfirmationResult
{
    public const STATE_PENDING = 'PENDING';
    public const STATE_COMMITTED = 'COMMITTED';
    public const STATE_FULFILLED = 'FULFILLED';
    public const STATE_FAILED = 'FAILED';

    private function __construct(
        private bool $success,
        private string $contractState,
        private ?string $errorMessage = null
    ) {
    }

    /**
     * Creates a successful confirmation result.
     */
    public static function success(string $contractState): self
    {
        return new self(
            success: true,
            contractState: $contractState
        );
    }

    /**
     * Creates a failed confirmation result.
     */
    public static function failure(string $errorMessage, string $contractState = self::STATE_FAILED): self
    {
        return new self(
            success: false,
            contractState: $contractState,
            errorMessage: $errorMessage
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getContractState(): string
    {
        return $this->contractState;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Returns true if contract is waiting for payment webhook confirmation.
     */
    public function isAwaitingPaymentConfirmation(): bool
    {
        return $this->contractState === self::STATE_COMMITTED;
    }

    /**
     * Returns true if contract is fully completed.
     */
    public function isFullyCompleted(): bool
    {
        return $this->contractState === self::STATE_FULFILLED;
    }
}
```

#### Test Plan

**File:** `tests/Component/Unit/Service/Result/OrderConfirmationResultTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Component\Unit\Service\Result;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\Service\Result\OrderConfirmationResult;

class OrderConfirmationResultTest extends TestCase
{
    public function testSuccess_CreatesSuccessfulResult(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('COMMITTED', $result->getContractState());
        $this->assertNull($result->getErrorMessage());
    }

    public function testFailure_CreatesFailedResult(): void
    {
        $result = OrderConfirmationResult::failure('Contract not found');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('Contract not found', $result->getErrorMessage());
        $this->assertEquals('FAILED', $result->getContractState());
    }

    public function testIsAwaitingPaymentConfirmation_WithCommittedState_ReturnsTrue(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->assertTrue($result->isAwaitingPaymentConfirmation());
        $this->assertFalse($result->isFullyCompleted());
    }

    public function testIsFullyCompleted_WithFulfilledState_ReturnsTrue(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_FULFILLED);

        $this->assertTrue($result->isFullyCompleted());
        $this->assertFalse($result->isAwaitingPaymentConfirmation());
    }

    public function testIsAwaitingPaymentConfirmation_WithPendingState_ReturnsFalse(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_PENDING);

        $this->assertFalse($result->isAwaitingPaymentConfirmation());
        $this->assertFalse($result->isFullyCompleted());
    }
}
```

#### Commands

```bash
# Run tests
docker compose exec php bash -c "cd /var/www && vendor/bin/phpunit extensions/stripe/tests/Component/Unit/Service/Result/OrderConfirmationResultTest.php"
```

#### Checklist

- [ ] TDD: Write tests first (RED)
- [ ] Implement class (GREEN)
- [ ] Tests pass
- [ ] PHPStan passes
- [ ] PHP CS Fixer passes

---

### STRP-203: Create CheckoutOrchestratorInterface

**Priority:** High
**Estimate:** 1 hour
**Type:** Feature
**Depends On:** STRP-201, STRP-202

#### Description

Create the interface that defines the contract for checkout orchestration. This is what controllers will depend on (Dependency Inversion).

#### Acceptance Criteria

- [ ] Interface created at `src/Component/Service/CheckoutOrchestratorInterface.php`
- [ ] Documents the contract clearly with PHPDoc
- [ ] Follows existing service interface patterns

#### Technical Details

**File:** `src/Component/Service/CheckoutOrchestratorInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service;

use OxidSolutionCatalysts\Payments\Component\Service\Result\CheckoutResult;
use OxidSolutionCatalysts\Payments\Component\Service\Result\OrderConfirmationResult;

/**
 * Orchestrates checkout accounting for Stripe payments.
 *
 * Note: This service handles BACKEND ACCOUNTING only.
 * Actual payment processing happens on the frontend via Stripe.js.
 *
 * Responsibilities:
 * - Create payment contracts
 * - Dispatch payment events
 * - Coordinate with event handlers
 * - Return results to controllers
 */
interface CheckoutOrchestratorInterface
{
    /**
     * Processes checkout: creates contract, snapshots basket, dispatches events.
     *
     * Called from OrderController::execute() BEFORE parent::execute().
     *
     * Does NOT:
     * - Call Stripe API
     * - Process payments
     * - Handle redirects
     *
     * Does:
     * - Create PaymentContract
     * - Snapshot basket data
     * - Store payment_intent_id for later webhook matching
     * - Emit PaymentInitiatedEvent
     *
     * @param object $basket OXID basket object
     * @param object $user OXID user object
     * @param string $paymentMethodId Payment method (e.g., 'stripe_card')
     * @param string|null $paymentIntentId Stripe PaymentIntent ID from frontend (optional)
     * @return CheckoutResult Result containing contract_id or error
     */
    public function processCheckout(
        object $basket,
        object $user,
        string $paymentMethodId,
        ?string $paymentIntentId = null
    ): CheckoutResult;

    /**
     * Confirms order completion and transitions contract state.
     *
     * Called from ThankyouController::render().
     *
     * Transitions contract: PENDING → COMMITTED
     * Final transition to FULFILLED happens via webhook.
     *
     * @param string $orderId OXID order ID
     * @param string|null $contractId Contract ID from session
     * @return OrderConfirmationResult Result with contract state
     */
    public function confirmOrderCompletion(
        string $orderId,
        ?string $contractId = null
    ): OrderConfirmationResult;
}
```

#### Checklist

- [ ] Interface file created
- [ ] PHPDoc comments complete
- [ ] Method signatures match plan
- [ ] PHPStan passes

---

### STRP-204: Implement CheckoutOrchestrator

**Priority:** High
**Estimate:** 3 hours
**Type:** Feature
**Depends On:** STRP-203

#### Description

Implement the CheckoutOrchestrator service that:
1. Creates EventContext from basket/user data
2. Dispatches PaymentInitiatedEvent
3. Returns CheckoutResult with contract ID
4. Handles order confirmation in ThankyouController

#### Acceptance Criteria

- [ ] Class created at `src/Component/Service/CheckoutOrchestrator.php`
- [ ] Implements `CheckoutOrchestratorInterface`
- [ ] Uses existing `EventDispatcher`, `EventContext`
- [ ] Unit tests with 100% coverage
- [ ] Registered in services.yaml

#### Technical Details

**File:** `src/Component/Service/CheckoutOrchestrator.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service;

use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\OrderCompletedEvent;
use OxidSolutionCatalysts\Payments\Component\Service\Result\CheckoutResult;
use OxidSolutionCatalysts\Payments\Component\Service\Result\OrderConfirmationResult;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates checkout accounting for Stripe payments.
 *
 * Note: No Stripe API calls here - payment happens on frontend.
 * This service only handles backend accounting (contract creation, event dispatch).
 */
class CheckoutOrchestrator implements CheckoutOrchestratorInterface
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function processCheckout(
        object $basket,
        object $user,
        string $paymentMethodId,
        ?string $paymentIntentId = null
    ): CheckoutResult {
        // Validate basket
        if ($this->isBasketEmpty($basket)) {
            return CheckoutResult::failure('Basket is empty', 'EMPTY_BASKET');
        }

        // Validate user
        if (!$this->isUserValid($user)) {
            return CheckoutResult::failure('Invalid user', 'INVALID_USER');
        }

        try {
            // Create event context with all necessary data
            $context = new EventContext([
                'basket' => $basket,
                'user' => $user,
                'paymentMethodId' => $paymentMethodId,
                'paymentIntentId' => $paymentIntentId,
            ]);

            // Dispatch PaymentInitiatedEvent
            // ContractCreationHandler will create the contract and set it in context
            $event = new PaymentInitiatedEvent($context);
            $this->eventDispatcher->dispatch($event);

            // Get contract from context (set by handler)
            $contract = $context->getContract();
            if (!$contract instanceof PaymentContractInterface) {
                return CheckoutResult::failure(
                    'Contract creation failed',
                    'CONTRACT_CREATION_FAILED'
                );
            }

            return CheckoutResult::success($contract->getId());

        } catch (\Throwable $e) {
            $this->logger?->error('Checkout processing failed', [
                'error' => $e->getMessage(),
                'paymentMethodId' => $paymentMethodId,
            ]);

            return CheckoutResult::failure(
                'Checkout processing failed: ' . $e->getMessage(),
                'PROCESSING_ERROR'
            );
        }
    }

    public function confirmOrderCompletion(
        string $orderId,
        ?string $contractId = null
    ): OrderConfirmationResult {
        if ($contractId === null) {
            return OrderConfirmationResult::failure(
                'No contract ID provided',
                OrderConfirmationResult::STATE_FAILED
            );
        }

        try {
            // Create context for order completion
            $context = new EventContext([
                'orderId' => $orderId,
                'contractId' => $contractId,
            ]);

            // Dispatch OrderCompletedEvent
            $event = new OrderCompletedEvent($context);
            $this->eventDispatcher->dispatch($event);

            // Get contract state from context (updated by handler)
            $contract = $context->getContract();
            $state = $contract?->getStateValue() ?? OrderConfirmationResult::STATE_COMMITTED;

            return OrderConfirmationResult::success($state);

        } catch (\Throwable $e) {
            $this->logger?->error('Order confirmation failed', [
                'orderId' => $orderId,
                'contractId' => $contractId,
                'error' => $e->getMessage(),
            ]);

            return OrderConfirmationResult::failure(
                'Order confirmation failed: ' . $e->getMessage()
            );
        }
    }

    private function isBasketEmpty(object $basket): bool
    {
        if (!method_exists($basket, 'getProductsCount')) {
            return false;
        }
        return $basket->getProductsCount() === 0;
    }

    private function isUserValid(object $user): bool
    {
        if (!method_exists($user, 'getId')) {
            return false;
        }
        return !empty($user->getId());
    }
}
```

#### Test Plan

**File:** `tests/Component/Unit/Service/CheckoutOrchestratorTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Component\Unit\Service;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestrator;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use Psr\Log\NullLogger;

class CheckoutOrchestratorTest extends TestCase
{
    private CheckoutOrchestrator $orchestrator;
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->orchestrator = new CheckoutOrchestrator(
            $this->eventDispatcher,
            new NullLogger()
        );
    }

    public function testProcessCheckout_WithValidBasket_ReturnsSuccess(): void
    {
        // Arrange
        $basket = $this->createBasketMock(itemCount: 2);
        $user = $this->createUserMock(id: 'user_123');
        $contract = $this->createContractMock(id: 'contract_456');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentInitiatedEvent::class))
            ->willReturnCallback(function (PaymentInitiatedEvent $event) use ($contract) {
                $event->getContext()->setContract($contract);
                return $event;
            });

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_123'
        );

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_456', $result->getContractId());
    }

    public function testProcessCheckout_WithEmptyBasket_ReturnsFailure(): void
    {
        // Arrange
        $basket = $this->createBasketMock(itemCount: 0);
        $user = $this->createUserMock(id: 'user_123');

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card'
        );

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('EMPTY_BASKET', $result->getErrorCode());
    }

    public function testProcessCheckout_WithInvalidUser_ReturnsFailure(): void
    {
        // Arrange
        $basket = $this->createBasketMock(itemCount: 1);
        $user = $this->createUserMock(id: ''); // Empty ID

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card'
        );

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('INVALID_USER', $result->getErrorCode());
    }

    public function testProcessCheckout_WhenContractNotCreated_ReturnsFailure(): void
    {
        // Arrange
        $basket = $this->createBasketMock(itemCount: 1);
        $user = $this->createUserMock(id: 'user_123');

        // Event dispatched but contract not set in context
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnArgument(0);

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card'
        );

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('CONTRACT_CREATION_FAILED', $result->getErrorCode());
    }

    public function testConfirmOrderCompletion_WithValidContract_ReturnsSuccess(): void
    {
        // Arrange
        $contract = $this->createContractMock(id: 'contract_123', state: 'COMMITTED');

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) use ($contract) {
                $event->getContext()->setContract($contract);
                return $event;
            });

        // Act
        $result = $this->orchestrator->confirmOrderCompletion('order_456', 'contract_123');

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('COMMITTED', $result->getContractState());
        $this->assertTrue($result->isAwaitingPaymentConfirmation());
    }

    public function testConfirmOrderCompletion_WithoutContractId_ReturnsFailure(): void
    {
        // Act
        $result = $this->orchestrator->confirmOrderCompletion('order_456', null);

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('No contract ID provided', $result->getErrorMessage());
    }

    // Helper methods
    private function createBasketMock(int $itemCount): object
    {
        $basket = $this->createMock(\stdClass::class);
        $basket->method('getProductsCount')->willReturn($itemCount);
        return $basket;
    }

    private function createUserMock(string $id): object
    {
        $user = $this->createMock(\stdClass::class);
        $user->method('getId')->willReturn($id);
        return $user;
    }

    private function createContractMock(string $id, string $state = 'PENDING'): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        $contract->method('getStateValue')->willReturn($state);
        return $contract;
    }
}
```

#### services.yaml Addition

```yaml
  # Checkout Orchestrator - Coordinates checkout events
  OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface:
    class: OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestrator
    arguments:
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
      - '@Psr\Log\LoggerInterface'
    public: true
```

#### Commands

```bash
# Run tests
docker compose exec php bash -c "cd /var/www && vendor/bin/phpunit extensions/stripe/tests/Component/Unit/Service/CheckoutOrchestratorTest.php"

# Run with coverage
docker compose exec php bash -c "cd /var/www && vendor/bin/phpunit --coverage-text extensions/stripe/tests/Component/Unit/Service/CheckoutOrchestratorTest.php"

# Check PHPStan
docker compose exec php bash -c "cd /var/www && vendor/bin/phpstan analyse extensions/stripe/src/Component/Service/CheckoutOrchestrator.php -l 8"
```

#### Checklist

- [ ] TDD: Write tests first (RED)
- [ ] Implement class (GREEN)
- [ ] Refactor if needed
- [ ] All tests pass
- [ ] 100% coverage
- [ ] PHPStan passes
- [ ] PHP CS Fixer passes
- [ ] services.yaml updated

---

## Sprint 2 Completion Criteria

- [ ] All 4 tickets completed
- [ ] CheckoutResult value object created
- [ ] OrderConfirmationResult value object created
- [ ] CheckoutOrchestratorInterface defined
- [ ] CheckoutOrchestrator implemented and tested
- [ ] Services registered in DI container
- [ ] Ready for Sprint 3

---

## Notes

- Orchestrator uses existing EventContext - no need for factory
- Orchestrator delegates to handlers via events
- No Stripe API calls in orchestrator

---

**Previous Sprint:** [SPRINT-1-EVENT-SYSTEM.md](./SPRINT-1-EVENT-SYSTEM.md)
**Next Sprint:** [SPRINT-3-CONTROLLERS.md](./SPRINT-3-CONTROLLERS.md)
