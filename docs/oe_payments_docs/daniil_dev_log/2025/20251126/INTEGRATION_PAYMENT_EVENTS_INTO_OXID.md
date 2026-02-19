# Integration: Payment Events into OXID Controllers

**Version:** 1.1.0
**Date:** 2025-11-26
**Author:** Daniil
**Status:** Implementation Plan
**OXID Version:** 7.4+
**Methodology:** TDD, SOLID, Clean Code

---

## Development Environment

### Docker Setup

This is a **Docker-based application**. All commands must be run inside the PHP container.

| Local Path | Container Path |
|------------|----------------|
| `/home/oxidshop/osc/strpwt7-nov26/source` | `/var/www` |
| `/home/oxidshop/osc/strpwt7-nov26/source/extensions/stripe` | `/var/www/extensions/stripe` |

### Running Commands

```bash
# Interactive shell
docker compose exec php bash

# One-off command
docker compose exec php bash -c "cd /var/www && <command>"

# Examples:
# Run PHPUnit tests
docker compose exec php bash -c "cd /var/www && vendor/bin/phpunit extensions/stripe/tests/"

# Run PHPStan
docker compose exec php bash -c "cd /var/www && vendor/bin/phpstan analyse extensions/stripe/src -l 8"

# Run PHP CS Fixer
docker compose exec php bash -c "cd /var/www && vendor/bin/php-cs-fixer fix extensions/stripe/src"

# Clear cache
docker compose exec php bash -c "cd /var/www && bin/oe-console oe:cache:clear"

# Activate module
docker compose exec php bash -c "cd /var/www && bin/oe-console oe:module:activate osc_stripe_wallet"

# Deactivate module
docker compose exec php bash -c "cd /var/www && bin/oe-console oe:module:deactivate osc_stripe_wallet"
```

### Quick Aliases (Optional)

Add to your shell profile for convenience:
```bash
alias dphp='docker compose exec php bash -c'
alias dtest='docker compose exec php bash -c "cd /var/www && vendor/bin/phpunit"'
alias dclear='docker compose exec php bash -c "cd /var/www && bin/oe-console oe:cache:clear"'
```

---

## Table of Contents

1. [Overview](#1-overview)
2. [Phase 1: OrderController & ThankyouController Integration](#2-phase-1-ordercontroller--thankyoucontroller-integration)
3. [Architecture Decision](#3-architecture-decision)
4. [Implementation Plan](#4-implementation-plan)
5. [Test Strategy](#5-test-strategy)
6. [File Structure](#6-file-structure)
7. [Code Specifications](#7-code-specifications)
8. [Acceptance Criteria](#8-acceptance-criteria)

---

## 1. Overview

### 1.1 Goal

Integrate the Smart-Contract payment event system into OXID's checkout flow for **backend order accounting only**.

**Important Clarification:**
- **Payment processing happens on the FRONTEND** via Stripe JS SDK
- **Backend controllers only handle order/contract accounting**
- No direct Stripe API calls from OrderController/ThankyouController

### 1.2 Separation of Concerns

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              PAYMENT FLOW                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                         FRONTEND (Browser)                           │    │
│  │  ┌─────────────────┐    ┌─────────────────┐    ┌────────────────┐   │    │
│  │  │ Stripe Elements │───▶│ Payment Intent  │───▶│ 3DS / Confirm  │   │    │
│  │  │ (Card Input)    │    │ (stripe.js)     │    │ (stripe.js)    │   │    │
│  │  └─────────────────┘    └─────────────────┘    └───────┬────────┘   │    │
│  │                                                         │            │    │
│  │                                     Payment confirmed ──┘            │    │
│  └─────────────────────────────────────────┬───────────────────────────┘    │
│                                             │                                │
│                                             ▼                                │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                         BACKEND (PHP/OXID)                           │    │
│  │                                                                       │    │
│  │  ┌─────────────────┐         ┌─────────────────┐                     │    │
│  │  │ OrderController │         │ ThankyouController│                    │    │
│  │  │ ::execute()     │         │ ::render()       │                    │    │
│  │  │                 │         │                  │                    │    │
│  │  │ - Validate      │         │ - Verify payment │                    │    │
│  │  │ - Create contract│        │ - Complete order │                    │    │
│  │  │ - Snapshot basket│        │ - Emit events    │                    │    │
│  │  │ - Create order  │         │ - Cleanup session│                    │    │
│  │  └────────┬────────┘         └────────┬─────────┘                    │    │
│  │           │                            │                              │    │
│  │           ▼                            ▼                              │    │
│  │  ┌──────────────────────────────────────────────────────────────┐    │    │
│  │  │                    Event System                               │    │    │
│  │  │  PaymentInitiatedEvent ──▶ ContractCreatedEvent              │    │    │
│  │  │  OrderCompletedEvent ──▶ ContractFulfilledEvent              │    │    │
│  │  └──────────────────────────────────────────────────────────────┘    │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                         ASYNC (Webhooks)                             │    │
│  │  Stripe ──webhook──▶ WebhookController ──▶ ContractFulfillmentHandler│    │
│  │  (payment.succeeded)                       (backup confirmation)     │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.3 What Backend Controllers Do

| Controller | Responsibility | Does NOT Do |
|------------|----------------|-------------|
| `OrderController::execute()` | Create contract, snapshot basket, create oxorder | Call Stripe API |
| `ThankyouController::render()` | Verify order completion, emit completion event, cleanup | Process payments |

### 1.4 Current State

Both controllers have placeholder TODOs:

```php
// OrderController.php
public function execute()
{
    //TODO: Create and process event ContractCreatedEvent here
    return parent::execute();
}

// ThankyouController.php
public function render()
{
    //TODO: Create and process event OrderCompletedEvent here
    return parent::render();
}
```

### 1.5 Target State

Controllers emit events for **accounting/tracking purposes**:
1. `OrderController` → Emits `PaymentInitiatedEvent` → Creates contract + order
2. `ThankyouController` → Emits `OrderCompletedEvent` → Marks order as complete

---

## 2. Phase 1: OrderController & ThankyouController Integration

### 2.1 Event Flow Diagram (Backend Accounting Only)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    BACKEND CHECKOUT ACCOUNTING FLOW                      │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  [Frontend: Payment confirmed via Stripe.js]                             │
│  [Form submitted to OrderController with payment_intent_id]              │
│           │                                                              │
│           ▼                                                              │
│  ┌─────────────────┐                                                     │
│  │ OrderController │                                                     │
│  │   ::execute()   │                                                     │
│  └────────┬────────┘                                                     │
│           │                                                              │
│           ▼                                                              │
│  ┌─────────────────────────┐                                             │
│  │ CheckoutOrchestrator    │  (Backend accounting service)               │
│  │ ::processCheckout()     │                                             │
│  └────────┬────────────────┘                                             │
│           │                                                              │
│           ├── 1. Validate basket & user                                  │
│           │                                                              │
│           ├── 2. Create EventContext                                     │
│           │      - basket snapshot                                       │
│           │      - user data                                             │
│           │      - payment_intent_id (from frontend)                     │
│           │                                                              │
│           ├── 3. Dispatch PaymentInitiatedEvent                          │
│           │           │                                                  │
│           │           ▼                                                  │
│           │      ┌─────────────────────────┐                             │
│           │      │ ContractCreationHandler │                             │
│           │      │ - Create PaymentContract│                             │
│           │      │ - State: PENDING        │                             │
│           │      │ - Store payment_intent  │                             │
│           │      └────────┬────────────────┘                             │
│           │               │                                              │
│           │               ▼                                              │
│           │      ┌─────────────────────────┐                             │
│           │      │ ContractCreatedEvent    │                             │
│           │      └─────────────────────────┘                             │
│           │                                                              │
│           ├── 4. Call parent::execute()                                  │
│           │      - Creates oxorder record                                │
│           │      - Links contract to order                               │
│           │                                                              │
│           └── 5. Store contract_id in session                            │
│                                                                          │
│           │                                                              │
│           ▼                                                              │
│  ┌───────────────────┐                                                   │
│  │ Redirect to       │                                                   │
│  │ thankyou page     │                                                   │
│  └────────┬──────────┘                                                   │
│           │                                                              │
│           ▼                                                              │
│  ┌───────────────────┐                                                   │
│  │ ThankyouController│                                                   │
│  │   ::render()      │                                                   │
│  └────────┬──────────┘                                                   │
│           │                                                              │
│           ▼                                                              │
│  ┌─────────────────────────┐                                             │
│  │ CheckoutOrchestrator    │                                             │
│  │ ::confirmOrderCompletion│                                             │
│  └────────┬────────────────┘                                             │
│           │                                                              │
│           ├── 1. Load contract from session                              │
│           │                                                              │
│           ├── 2. Verify order exists                                     │
│           │                                                              │
│           ├── 3. Dispatch OrderCompletedEvent                            │
│           │           │                                                  │
│           │           ▼                                                  │
│           │      ┌─────────────────────────┐                             │
│           │      │ OrderCompletionHandler  │                             │
│           │      │ - Transition contract   │                             │
│           │      │   PENDING → COMMITTED   │                             │
│           │      │ - Mark order ready      │                             │
│           │      └────────┬────────────────┘                             │
│           │               │                                              │
│           │               ▼                                              │
│           │      ┌─────────────────────────┐                             │
│           │      │ ContractCommittedEvent  │                             │
│           │      │ (waiting for webhook)   │                             │
│           │      └─────────────────────────┘                             │
│           │                                                              │
│           └── 4. Cleanup session variables                               │
│                                                                          │
│                                                                          │
│  [ASYNC: Stripe webhook confirms payment]                                │
│           │                                                              │
│           ▼                                                              │
│  ┌─────────────────────────┐                                             │
│  │ WebhookController       │                                             │
│  │ (payment_intent.succeed)│                                             │
│  └────────┬────────────────┘                                             │
│           │                                                              │
│           ▼                                                              │
│  ┌─────────────────────────┐                                             │
│  │ ContractFulfillmentHdlr │                                             │
│  │ - COMMITTED → FULFILLED │                                             │
│  │ - oxorder.OXPAID = NOW()│                                             │
│  │ - Send confirmation     │                                             │
│  └─────────────────────────┘                                             │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Contract State Machine

```
                    ┌──────────┐
                    │  DRAFT   │  (Contract created but not submitted)
                    └────┬─────┘
                         │ processCheckout()
                         ▼
                    ┌──────────┐
                    │ PENDING  │  (Checkout initiated, awaiting order creation)
                    └────┬─────┘
                         │ confirmOrderCompletion()
                         ▼
                    ┌──────────┐
                    │ COMMITTED│  (Order created, waiting for payment webhook)
                    └────┬─────┘
                         │ Webhook: payment_intent.succeeded
                         ▼
                    ┌──────────┐
                    │ FULFILLED│  (Payment confirmed, order complete)
                    └──────────┘
```

---

## 3. Architecture Decision

### 3.1 SOLID Principles Applied

| Principle | Application |
|-----------|-------------|
| **S**ingle Responsibility | Controllers only handle HTTP; `CheckoutOrchestrator` handles business logic |
| **O**pen/Closed | New order processors added via event handlers, not controller modifications |
| **L**iskov Substitution | All handlers implement `HandlerInterface` |
| **I**nterface Segregation | `CheckoutOrchestratorInterface` only exposes needed methods |
| **D**ependency Inversion | Controllers depend on `CheckoutOrchestratorInterface`, not implementations |

### 3.2 Key Design Decisions

1. **No Stripe API Calls in Controllers**
   - Payment happens on frontend via Stripe.js
   - Backend receives `payment_intent_id` from form submission
   - Final confirmation comes via webhook

2. **Contract as Central Entity**
   - Contract created BEFORE oxorder
   - Contract tracks state through checkout flow
   - Contract links to oxorder after creation

3. **Event-Driven Accounting**
   - Events allow multiple handlers to react
   - Decouples order creation from notifications, analytics, etc.

### 3.3 Code Reuse Principle (CRITICAL)

**Rule: DO NOT reinvent the wheel. Reuse existing code.**

| Principle | Application |
|-----------|-------------|
| **No Duplication** | Before creating new classes, check if equivalent functionality exists |
| **Extend, Don't Override** | Prefer extending existing classes over creating parallel implementations |
| **Use Existing Events** | Use events from `src/Component/EventSystem/Event/` - don't create new ones if existing ones fit |
| **Use Existing Handlers** | Extend or modify existing handlers in `src/Component/EventSystem/Handler/` |
| **Use Existing Services** | Check `src/Component/Service/` for existing services before creating new ones |
| **Long-term Perspective** | Every new class adds maintenance burden - justify its necessity |

#### Existing Code to Reuse

**Events (Already Exist - DO NOT DUPLICATE):**
```
src/Component/EventSystem/Event/Payment/
├── PaymentInitiatedEvent.php          ✓ USE THIS
├── OrderCompletedEvent.php            ✓ USE THIS
├── PaymentCapturedEvent.php           ✓ USE THIS (for webhook)
└── ...

src/Component/EventSystem/Event/Contract/
├── ContractCreatedEvent.php           ✓ USE THIS
├── ContractFulfilledEvent.php         ✓ USE THIS
├── ContractCommittedEvent.php         ✓ USE THIS
└── ...
```

**Handlers (Already Exist - EXTEND IF NEEDED):**
```
src/Component/EventSystem/Handler/
├── ContractCreationHandler.php        ✓ USE THIS
├── ContractFulfillmentHandler.php     ✓ USE THIS
├── ContractConditionResolverHandler.php
├── OrderCreationHandler.php           ✓ USE THIS
├── PaymentAuthorizationHandler.php
└── AbstractHandler.php                ✓ EXTEND THIS for new handlers
```

**Services (Check Before Creating New):**
```
src/Component/Service/
├── ContractServiceInterface.php       ✓ USE THIS
├── ContractService.php                ✓ USE THIS
└── ...
```

**EventContext (Already Exists - DO NOT DUPLICATE):**
```
src/Component/EventSystem/Event/
├── EventContext.php                   ✓ USE THIS
├── EventContextInterface.php          ✓ USE THIS
└── ...
```

#### What We Actually Need to Create

Based on reuse analysis, we need **MINIMAL** new code:

| Component | Action | Justification |
|-----------|--------|---------------|
| `EventListenerProvider` | CREATE | Does not exist - needed to wire DI to event system |
| `EventListenerProviderInterface` | CREATE | Interface for above |
| `CheckoutOrchestratorInterface` | CREATE | Specific orchestration for OXID controllers |
| `CheckoutOrchestrator` | CREATE | Implementation of above |
| `CheckoutResult` | CREATE | Value object for orchestrator result |
| `OrderConfirmationResult` | CREATE | Value object for confirmation result |
| `OrderCompletionHandler` | CREATE | Handles `OrderCompletedEvent` - does not exist |
| `EventContextFactory` | **EVALUATE** | May not be needed if `EventContext` constructor suffices |

#### Code Review Checklist (Before Implementation)

- [ ] Searched for existing similar functionality
- [ ] Verified no duplicate event classes
- [ ] Verified no duplicate handler logic
- [ ] Checked if existing service can be extended
- [ ] Justified creation of any new class in PR description

---

## 4. Implementation Plan

### 4.1 Task Breakdown

#### Task 1: Create `CheckoutOrchestratorInterface` (TDD)

```
File: src/Component/Service/CheckoutOrchestratorInterface.php
Test: tests/Component/Unit/Service/CheckoutOrchestratorTest.php
```

**Interface Contract:**
```php
interface CheckoutOrchestratorInterface
{
    /**
     * Processes checkout: creates contract, snapshots basket, prepares for order.
     * Called from OrderController::execute() BEFORE parent::execute().
     *
     * @param object $basket OXID basket
     * @param object $user OXID user
     * @param string $paymentMethodId Payment method (e.g., 'stripe_card')
     * @param string|null $paymentIntentId Stripe PaymentIntent ID from frontend
     * @return CheckoutResult Contains contract_id, success status
     */
    public function processCheckout(
        object $basket,
        object $user,
        string $paymentMethodId,
        ?string $paymentIntentId = null
    ): CheckoutResult;

    /**
     * Confirms order completion after successful checkout.
     * Called from ThankyouController::render().
     *
     * @param string $orderId OXID order ID
     * @param string|null $contractId Contract ID from session
     * @return OrderConfirmationResult
     */
    public function confirmOrderCompletion(
        string $orderId,
        ?string $contractId = null
    ): OrderConfirmationResult;
}
```

#### Task 2: Create `CheckoutResult` Value Object (TDD)

```
File: src/Component/Service/Result/CheckoutResult.php
Test: tests/Component/Unit/Service/Result/CheckoutResultTest.php
```

```php
readonly class CheckoutResult
{
    public function __construct(
        private bool $success,
        private ?string $contractId = null,
        private ?string $errorMessage = null,
        private ?string $errorCode = null
    ) {}

    public function isSuccess(): bool;
    public function getContractId(): ?string;
    public function getErrorMessage(): ?string;
    public function getErrorCode(): ?string;
}
```

#### Task 3: Create `OrderConfirmationResult` Value Object (TDD)

```
File: src/Component/Service/Result/OrderConfirmationResult.php
Test: tests/Component/Unit/Service/Result/OrderConfirmationResultTest.php
```

```php
readonly class OrderConfirmationResult
{
    public function __construct(
        private bool $success,
        private string $contractState,
        private ?string $errorMessage = null
    ) {}

    public function isSuccess(): bool;
    public function getContractState(): string;
    public function getErrorMessage(): ?string;
    public function isAwaitingPaymentConfirmation(): bool; // State == COMMITTED
    public function isFullyCompleted(): bool; // State == FULFILLED
}
```

#### Task 4: Create `EventContextFactory` (TDD)

```
File: src/Component/Service/EventContextFactory.php
Test: tests/Component/Unit/Service/EventContextFactoryTest.php
```

#### Task 5: Implement `CheckoutOrchestrator` (TDD)

```
File: src/Component/Service/CheckoutOrchestrator.php
Test: tests/Component/Unit/Service/CheckoutOrchestratorTest.php
```

#### Task 6: Create `OrderCompletionHandler` (TDD)

```
File: src/Component/EventSystem/Handler/OrderCompletionHandler.php
Test: tests/Component/Unit/EventSystem/Handler/OrderCompletionHandlerTest.php
```

#### Task 7: Update `OrderController` (TDD)

```
File: src/Component/Controller/Http/OrderController.php
Test: tests/Component/Unit/Controller/Http/OrderControllerTest.php
```

#### Task 8: Update `ThankyouController` (TDD)

```
File: src/Component/Controller/Http/ThankyouController.php
Test: tests/Component/Unit/Controller/Http/ThankyouControllerTest.php
```

#### Task 9: Register Services in DI Container

```
File: services.yaml (update)
```

#### Task 10: Integration Tests

```
File: tests/Component/Integration/Controller/CheckoutFlowIntegrationTest.php
```

---

## 5. Test Strategy

### 5.1 Test Pyramid

```
                    ┌─────────┐
                    │   E2E   │  1-2 tests (browser checkout flow)
                   ─┴─────────┴─
                  ┌─────────────┐
                  │ Integration │  5-10 tests (controller + services)
                 ─┴─────────────┴─
                ┌─────────────────┐
                │      Unit       │  30+ tests (each class isolated)
               ─┴─────────────────┴─
```

### 5.2 Unit Test Examples

#### CheckoutOrchestratorTest.php

```php
<?php

declare(strict_types=1);

namespace Tests\Component\Unit\Service;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestrator;
use OxidSolutionCatalysts\Payments\Component\Service\EventContextFactoryInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface;

class CheckoutOrchestratorTest extends TestCase
{
    private CheckoutOrchestrator $orchestrator;
    private EventDispatcherInterface $eventDispatcher;
    private EventContextFactoryInterface $contextFactory;
    private ContractServiceInterface $contractService;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->contextFactory = $this->createMock(EventContextFactoryInterface::class);
        $this->contractService = $this->createMock(ContractServiceInterface::class);

        $this->orchestrator = new CheckoutOrchestrator(
            $this->eventDispatcher,
            $this->contextFactory,
            $this->contractService
        );
    }

    public function testProcessCheckout_WithValidBasket_CreatesContract(): void
    {
        // Arrange
        $basket = $this->createBasketMock(items: 2, total: 99.99);
        $user = $this->createUserMock(id: 'user_123');
        $context = $this->createMock(EventContextInterface::class);
        $contract = $this->createContractMock(id: 'contract_456');

        $this->contextFactory
            ->expects($this->once())
            ->method('createForCheckout')
            ->willReturn($context);

        $this->contractService
            ->expects($this->once())
            ->method('createContract')
            ->willReturn($contract);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentInitiatedEvent::class));

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

    public function testProcessCheckout_WithEmptyBasket_ReturnsError(): void
    {
        // Arrange
        $basket = $this->createBasketMock(items: 0, total: 0);
        $user = $this->createUserMock(id: 'user_123');

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_123'
        );

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('EMPTY_BASKET', $result->getErrorCode());
    }

    public function testProcessCheckout_WithoutPaymentIntent_StillCreatesContract(): void
    {
        // Payment intent may not be available for all payment methods
        // Contract should still be created for accounting

        // Arrange
        $basket = $this->createBasketMock(items: 1, total: 50.00);
        $user = $this->createUserMock(id: 'user_123');
        $context = $this->createMock(EventContextInterface::class);
        $contract = $this->createContractMock(id: 'contract_789');

        $this->contextFactory->method('createForCheckout')->willReturn($context);
        $this->contractService->method('createContract')->willReturn($contract);
        $this->eventDispatcher->method('dispatch');

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            null // No payment intent
        );

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($result->getContractId());
    }

    public function testConfirmOrderCompletion_WithValidContract_TransitionsState(): void
    {
        // Arrange
        $contract = $this->createContractMock(id: 'contract_123', state: 'PENDING');

        $this->contractService
            ->expects($this->once())
            ->method('findById')
            ->with('contract_123')
            ->willReturn($contract);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(OrderCompletedEvent::class));

        // Act
        $result = $this->orchestrator->confirmOrderCompletion(
            'order_456',
            'contract_123'
        );

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->isAwaitingPaymentConfirmation());
    }

    private function createBasketMock(int $items, float $total): object
    {
        $basket = $this->createMock(\OxidEsales\Eshop\Application\Model\Basket::class);
        $basket->method('getProductsCount')->willReturn($items);
        $basket->method('getPrice')->willReturn(
            (object) ['getBruttoPrice' => fn() => $total]
        );
        return $basket;
    }

    private function createUserMock(string $id): object
    {
        $user = $this->createMock(\OxidEsales\Eshop\Application\Model\User::class);
        $user->method('getId')->willReturn($id);
        return $user;
    }

    private function createContractMock(string $id, string $state = 'PENDING'): object
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        $contract->method('getStateValue')->willReturn($state);
        return $contract;
    }
}
```

#### OrderControllerTest.php

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
    public function testExecute_WithStripePayment_CallsOrchestrator(): void
    {
        // Arrange
        $orchestrator = $this->createMock(CheckoutOrchestratorInterface::class);
        $result = new CheckoutResult(success: true, contractId: 'contract_123');

        $orchestrator
            ->expects($this->once())
            ->method('processCheckout')
            ->willReturn($result);

        $controller = $this->createControllerWithMocks($orchestrator, 'stripe_card');

        // Act
        $viewName = $controller->execute();

        // Assert
        $this->assertNotNull($viewName);
    }

    public function testExecute_WithNonStripePayment_SkipsOrchestrator(): void
    {
        // Arrange
        $orchestrator = $this->createMock(CheckoutOrchestratorInterface::class);

        $orchestrator
            ->expects($this->never())
            ->method('processCheckout');

        $controller = $this->createControllerWithMocks($orchestrator, 'oxidcashondel');

        // Act
        $controller->execute();
    }

    public function testExecute_WithOrchestratorError_DisplaysError(): void
    {
        // Arrange
        $orchestrator = $this->createMock(CheckoutOrchestratorInterface::class);
        $result = new CheckoutResult(
            success: false,
            errorMessage: 'Basket validation failed',
            errorCode: 'VALIDATION_ERROR'
        );

        $orchestrator->method('processCheckout')->willReturn($result);

        $controller = $this->createControllerWithMocks($orchestrator, 'stripe_card');

        // Act
        $viewName = $controller->execute();

        // Assert
        $this->assertEquals('order', $viewName);
        // Verify error was added to display
    }

    public function testExecute_StoresContractIdInSession(): void
    {
        // Arrange
        $orchestrator = $this->createMock(CheckoutOrchestratorInterface::class);
        $result = new CheckoutResult(success: true, contractId: 'contract_xyz');

        $orchestrator->method('processCheckout')->willReturn($result);

        $controller = $this->createControllerWithMocks($orchestrator, 'stripe_card');

        // Act
        $controller->execute();

        // Assert
        // Verify session contains 'stripe_contract_id' => 'contract_xyz'
    }
}
```

---

## 6. File Structure

```
src/
├── Component/
│   ├── Controller/
│   │   └── Http/
│   │       ├── OrderController.php              # MODIFY
│   │       └── ThankyouController.php           # MODIFY
│   ├── Service/
│   │   ├── CheckoutOrchestratorInterface.php    # CREATE
│   │   ├── CheckoutOrchestrator.php             # CREATE
│   │   ├── EventContextFactoryInterface.php     # CREATE
│   │   ├── EventContextFactory.php              # CREATE
│   │   └── Result/
│   │       ├── CheckoutResult.php               # CREATE
│   │       └── OrderConfirmationResult.php      # CREATE
│   └── EventSystem/
│       └── Handler/
│           └── OrderCompletionHandler.php       # CREATE

tests/
├── Component/
│   ├── Unit/
│   │   ├── Controller/
│   │   │   └── Http/
│   │   │       ├── OrderControllerTest.php
│   │   │       └── ThankyouControllerTest.php
│   │   ├── Service/
│   │   │   ├── CheckoutOrchestratorTest.php
│   │   │   ├── EventContextFactoryTest.php
│   │   │   └── Result/
│   │   │       ├── CheckoutResultTest.php
│   │   │       └── OrderConfirmationResultTest.php
│   │   └── EventSystem/
│   │       └── Handler/
│   │           └── OrderCompletionHandlerTest.php
│   └── Integration/
│       └── Controller/
│           └── CheckoutFlowIntegrationTest.php
```

---

## 7. Code Specifications

### 7.1 OrderController (Final Implementation)

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Controller\Http;

use OxidEsales\Eshop\Core\Exception\ArticleInputException;
use OxidEsales\Eshop\Core\Exception\NoArticleException;
use OxidEsales\Eshop\Core\Exception\OutOfStockException;
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

    private ?CheckoutOrchestratorInterface $checkoutOrchestrator = null;

    public function execute(): mixed
    {
        if (!$this->isStripePaymentMethod()) {
            return $this->executeParentWithExceptionHandling();
        }

        try {
            return $this->executeWithStripeAccounting();
        } catch (NoArticleException|OutOfStockException|ArticleInputException $e) {
            return $this->handleCheckoutException($e);
        }
    }

    /**
     * Processes checkout with Stripe contract accounting.
     *
     * Flow:
     * 1. Call orchestrator to create contract and snapshot basket
     * 2. Store contract ID in session
     * 3. Call parent::execute() to create oxorder
     */
    private function executeWithStripeAccounting(): mixed
    {
        $basket = Registry::getSession()->getBasket();
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
            Registry::getUtilsView()->addErrorToDisplay($result->getErrorMessage());
            return $this->getViewName();
        }

        // Store contract ID for ThankyouController
        if ($result->getContractId()) {
            Registry::getSession()->setVariable('stripe_contract_id', $result->getContractId());
        }

        // Continue with standard OXID order creation
        return $this->executeParentWithExceptionHandling();
    }

    private function executeParentWithExceptionHandling(): mixed
    {
        try {
            return parent::execute();
        } catch (NoArticleException|OutOfStockException|ArticleInputException $e) {
            return $this->handleCheckoutException($e);
        }
    }

    private function handleCheckoutException(\Throwable $e): string
    {
        Registry::getSession()->setVariable('OrderException', $e);
        $this->setViewConfigParam('bOrderStepError', true);
        return $this->getViewName();
    }

    private function isStripePaymentMethod(): bool
    {
        $paymentId = Registry::getSession()->getBasket()->getPaymentId();
        return str_starts_with($paymentId ?? '', 'stripe_');
    }

    private function getCheckoutOrchestrator(): CheckoutOrchestratorInterface
    {
        if ($this->checkoutOrchestrator === null) {
            $this->checkoutOrchestrator = $this->getServiceFromContainer(
                CheckoutOrchestratorInterface::class
            );
        }
        return $this->checkoutOrchestrator;
    }

    /** @internal For testing only */
    public function setCheckoutOrchestrator(CheckoutOrchestratorInterface $orchestrator): void
    {
        $this->checkoutOrchestrator = $orchestrator;
    }
}
```

### 7.2 ThankyouController (Final Implementation)

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

    private ?CheckoutOrchestratorInterface $checkoutOrchestrator = null;

    public function render(): string
    {
        $contractId = Registry::getSession()->getVariable('stripe_contract_id');

        if ($contractId) {
            $this->confirmStripeOrderCompletion($contractId);
        }

        return parent::render();
    }

    private function confirmStripeOrderCompletion(string $contractId): void
    {
        $order = $this->getOrder();
        if (!$order) {
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
                Registry::getSession()->deleteVariable('stripe_contract_id');

                // Log state for debugging
                if ($result->isAwaitingPaymentConfirmation()) {
                    Registry::getLogger()->info(
                        'Stripe order awaiting payment confirmation via webhook',
                        ['orderId' => $orderId, 'contractId' => $contractId]
                    );
                }
            }
        } catch (\Throwable $e) {
            // Log but don't break the thankyou page
            Registry::getLogger()->error(
                'Failed to confirm Stripe order completion',
                [
                    'orderId' => $orderId,
                    'contractId' => $contractId,
                    'error' => $e->getMessage()
                ]
            );
        }
    }

    private function getCheckoutOrchestrator(): CheckoutOrchestratorInterface
    {
        if ($this->checkoutOrchestrator === null) {
            $this->checkoutOrchestrator = $this->getServiceFromContainer(
                CheckoutOrchestratorInterface::class
            );
        }
        return $this->checkoutOrchestrator;
    }

    /** @internal For testing only */
    public function setCheckoutOrchestrator(CheckoutOrchestratorInterface $orchestrator): void
    {
        $this->checkoutOrchestrator = $orchestrator;
    }
}
```

### 7.3 CheckoutOrchestratorInterface

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
 */
interface CheckoutOrchestratorInterface
{
    /**
     * Processes checkout: creates contract, snapshots basket.
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
     * @param object $basket OXID basket
     * @param object $user OXID user
     * @param string $paymentMethodId Payment method (e.g., 'stripe_card')
     * @param string|null $paymentIntentId Stripe PaymentIntent ID from frontend
     * @return CheckoutResult
     */
    public function processCheckout(
        object $basket,
        object $user,
        string $paymentMethodId,
        ?string $paymentIntentId = null
    ): CheckoutResult;

    /**
     * Confirms order completion and transitions contract state.
     * Called from ThankyouController::render().
     *
     * Transitions contract: PENDING → COMMITTED
     * Final transition to FULFILLED happens via webhook.
     *
     * @param string $orderId OXID order ID
     * @param string|null $contractId Contract ID from session
     * @return OrderConfirmationResult
     */
    public function confirmOrderCompletion(
        string $orderId,
        ?string $contractId = null
    ): OrderConfirmationResult;
}
```

### 7.4 services.yaml Addition

```yaml
services:
  # Event Context Factory
  OxidSolutionCatalysts\Payments\Component\Service\EventContextFactoryInterface:
    class: OxidSolutionCatalysts\Payments\Component\Service\EventContextFactory
    public: true

  # Checkout Orchestrator - Backend accounting for Stripe payments
  OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface:
    class: OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestrator
    arguments:
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
      - '@OxidSolutionCatalysts\Payments\Component\Service\EventContextFactoryInterface'
      - '@OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface'
      - '@OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface'
    public: true

  # Order Completion Handler - Reacts to OrderCompletedEvent
  OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\OrderCompletionHandler:
    arguments:
      - '@OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface'
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
    tags:
      - { name: 'payment.event_handler', event: 'OrderCompletedEvent' }
    public: false
```

---

## 8. Acceptance Criteria

### 8.1 Functional Requirements

| ID | Requirement | Test Method |
|----|-------------|-------------|
| FR-1 | OrderController creates contract for Stripe payments | Unit test |
| FR-2 | OrderController stores payment_intent_id in contract | Unit test |
| FR-3 | OrderController falls back to parent for non-Stripe payments | Unit test |
| FR-4 | ThankyouController transitions contract to COMMITTED | Unit test |
| FR-5 | ThankyouController cleans up session variables | Unit test |
| FR-6 | Errors are logged but don't break checkout flow | Unit test |
| FR-7 | Contract links to oxorder after order creation | Integration test |

### 8.2 Non-Functional Requirements

| ID | Requirement | Validation |
|----|-------------|------------|
| NFR-1 | No Stripe API calls from controllers | Code review |
| NFR-2 | 100% unit test coverage for new classes | PHPUnit coverage |
| NFR-3 | All tests pass in CI | GitHub Actions |
| NFR-4 | SOLID principles followed | Code review |
| NFR-5 | PHP 8.2+ syntax | Static analysis |

### 8.3 Definition of Done

- [ ] All unit tests written and passing
- [ ] All integration tests written and passing
- [ ] Code coverage >= 95%
- [ ] PHPStan level 8 passing
- [ ] PHP CS Fixer passing
- [ ] Code review approved
- [ ] Documentation updated
- [ ] services.yaml updated with new services

---

## Implementation Order (TDD Sequence)

1. **Write tests first** for `CheckoutResult` value object
2. **Implement** `CheckoutResult` to pass tests
3. **Write tests first** for `OrderConfirmationResult` value object
4. **Implement** `OrderConfirmationResult` to pass tests
5. **Write tests first** for `EventContextFactory`
6. **Implement** `EventContextFactory` to pass tests
7. **Write tests first** for `CheckoutOrchestrator`
8. **Implement** `CheckoutOrchestrator` to pass tests
9. **Write tests first** for `OrderCompletionHandler`
10. **Implement** `OrderCompletionHandler` to pass tests
11. **Write tests first** for `OrderController` changes
12. **Update** `OrderController` to pass tests
13. **Write tests first** for `ThankyouController` changes
14. **Update** `ThankyouController` to pass tests
15. **Write integration tests** for full flow
16. **Update** `services.yaml`
17. **Run full test suite**

---

**Status:** Ready for Implementation
**Estimated Effort:** 2-3 days
**Priority:** High

---

## 9. Event System Integration (CRITICAL)

### 9.1 Problem

OXID 7.4 does NOT have our custom event system integrated. The `EventDispatcher` and handlers exist in `src/Component/EventSystem/` but are **not wired into the DI container** and **not connected to Symfony's EventDispatcher**.

### 9.2 Solution Options

#### Option A: Standalone Event System (Recommended)

Use our own `EventDispatcher` as a standalone service, not connected to Symfony's EventDispatcher.

**Pros:**
- Simple, self-contained
- No OXID core modifications
- Easy to test in isolation

**Cons:**
- Not integrated with OXID's event system
- Cannot listen to OXID core events

#### Option B: Bridge to Symfony EventDispatcher

Create a bridge that connects our events to Symfony's EventDispatcher (which OXID uses internally).

**Pros:**
- Full integration with OXID ecosystem
- Can listen to OXID core events

**Cons:**
- More complex
- Tighter coupling to OXID internals

### 9.3 Recommended Approach: Option A (Standalone)

For Phase 1, we use a standalone event system. This keeps the component decoupled and testable.

### 9.4 Implementation: Event System Wiring

#### Task 0 (NEW): Wire Event System into DI Container

**Files to create/modify:**

```
src/Component/EventSystem/EventDispatcherInterface.php     # EXISTS
src/Component/EventSystem/EventDispatcher.php              # EXISTS
src/Component/EventSystem/EventListenerProvider.php        # CREATE
src/Component/EventSystem/EventListenerProviderInterface.php # CREATE
services.yaml                                               # UPDATE
```

#### 9.4.1 Create `EventListenerProviderInterface`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem;

/**
 * Provides event listeners/handlers for the EventDispatcher.
 * This is the bridge between DI container and event system.
 */
interface EventListenerProviderInterface
{
    /**
     * Returns all registered listeners for an event class.
     *
     * @param string $eventClass Fully qualified event class name
     * @return array<callable> Array of callables that handle the event
     */
    public function getListenersForEvent(string $eventClass): array;

    /**
     * Registers a listener for an event class.
     *
     * @param string $eventClass Event class to listen for
     * @param callable $listener Handler callable
     * @param int $priority Higher priority = executed first (default: 0)
     */
    public function addListener(string $eventClass, callable $listener, int $priority = 0): void;
}
```

#### 9.4.2 Create `EventListenerProvider`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;

/**
 * Manages event listeners and provides them to EventDispatcher.
 */
class EventListenerProvider implements EventListenerProviderInterface
{
    /** @var array<string, array<array{listener: callable, priority: int}>> */
    private array $listeners = [];

    /**
     * @param iterable<HandlerInterface> $handlers Handlers injected via DI (tagged services)
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->registerHandler($handler);
        }
    }

    public function getListenersForEvent(string $eventClass): array
    {
        if (!isset($this->listeners[$eventClass])) {
            return [];
        }

        // Sort by priority (descending) and return only the callables
        $listeners = $this->listeners[$eventClass];
        usort($listeners, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return array_map(fn($item) => $item['listener'], $listeners);
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$eventClass])) {
            $this->listeners[$eventClass] = [];
        }

        $this->listeners[$eventClass][] = [
            'listener' => $listener,
            'priority' => $priority,
        ];
    }

    /**
     * Registers a handler for all events it supports.
     */
    private function registerHandler(HandlerInterface $handler): void
    {
        // Handler's handle() method is the listener
        // The event class is determined by the handler's type hint
        $reflection = new \ReflectionMethod($handler, 'handle');
        $parameters = $reflection->getParameters();

        if (count($parameters) > 0) {
            $paramType = $parameters[0]->getType();
            if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                $eventClass = $paramType->getName();
                $this->addListener($eventClass, [$handler, 'handle']);
            }
        }
    }
}
```

#### 9.4.3 Update `EventDispatcher` to Use Provider

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;

class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private ?EventListenerProviderInterface $listenerProvider = null
    ) {
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        if ($this->listenerProvider instanceof EventListenerProvider) {
            $this->listenerProvider->addListener($eventClass, $listener, $priority);
        }
    }

    public function dispatch(EventInterface $event): EventInterface
    {
        $eventClass = get_class($event);
        $listeners = $this->listenerProvider?->getListenersForEvent($eventClass) ?? [];

        foreach ($listeners as $listener) {
            if ($this->isStoppableEvent($event) && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    private function isStoppableEvent(EventInterface $event): bool
    {
        return method_exists($event, 'isPropagationStopped');
    }
}
```

#### 9.4.4 Update `services.yaml` for Event System

```yaml
services:
  # ===========================================
  # Event System (Component Level)
  # ===========================================

  # Event Listener Provider - Collects all tagged handlers
  OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProviderInterface:
    class: OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProvider
    arguments:
      - !tagged_iterator payment.event_handler
    public: false

  # Event Dispatcher - Dispatches events to registered handlers
  OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface:
    class: OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher
    arguments:
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProviderInterface'
    public: true

  # ===========================================
  # Event Handlers (Tagged for auto-registration)
  # ===========================================

  # Contract Creation Handler - Creates contract on PaymentInitiatedEvent
  OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\ContractCreationHandler:
    arguments:
      - '@OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface'
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
    tags:
      - { name: payment.event_handler }
    public: false

  # Contract Fulfillment Handler - Fulfills contract on WebhookReceivedEvent
  OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\ContractFulfillmentHandler:
    arguments:
      - '@OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface'
      - '@OxidSolutionCatalysts\Payments\Component\Repository\OrderRepositoryInterface'
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
    tags:
      - { name: payment.event_handler }
    public: false

  # Order Completion Handler - Handles OrderCompletedEvent
  OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\OrderCompletionHandler:
    arguments:
      - '@OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface'
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
    tags:
      - { name: payment.event_handler }
    public: false

  # ===========================================
  # Services
  # ===========================================

  # Event Context Factory
  OxidSolutionCatalysts\Payments\Component\Service\EventContextFactoryInterface:
    class: OxidSolutionCatalysts\Payments\Component\Service\EventContextFactory
    public: true

  # Checkout Orchestrator
  OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface:
    class: OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestrator
    arguments:
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
      - '@OxidSolutionCatalysts\Payments\Component\Service\EventContextFactoryInterface'
      - '@OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface'
      - '@OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface'
    public: true
```

### 9.5 Handler Registration Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                      DI CONTAINER INITIALIZATION                         │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  1. Symfony DI compiles services.yaml                                    │
│                                                                          │
│  2. Creates handlers with tag 'payment.event_handler':                   │
│     - ContractCreationHandler                                            │
│     - ContractFulfillmentHandler                                         │
│     - OrderCompletionHandler                                             │
│                                                                          │
│  3. EventListenerProvider receives tagged handlers via:                  │
│     arguments:                                                           │
│       - !tagged_iterator payment.event_handler                           │
│                                                                          │
│  4. EventListenerProvider introspects each handler:                      │
│     - Reads handle() method signature                                    │
│     - Extracts event class from type hint                                │
│     - Registers: listeners[EventClass] = handler->handle()               │
│                                                                          │
│  5. EventDispatcher receives EventListenerProvider                       │
│                                                                          │
│  6. When dispatch(Event) called:                                         │
│     - Provider returns listeners for Event class                         │
│     - Each listener invoked with Event                                   │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### 9.6 Updated Implementation Order

1. **Write tests first** for `EventListenerProvider` ← NEW
2. **Implement** `EventListenerProvider` to pass tests ← NEW
3. **Write tests first** for `EventDispatcher` (with provider) ← UPDATE
4. **Update** `EventDispatcher` to use provider ← UPDATE
5. **Write tests first** for `CheckoutResult` value object
6. **Implement** `CheckoutResult` to pass tests
7. **Write tests first** for `OrderConfirmationResult` value object
8. **Implement** `OrderConfirmationResult` to pass tests
9. **Write tests first** for `EventContextFactory`
10. **Implement** `EventContextFactory` to pass tests
11. **Write tests first** for `CheckoutOrchestrator`
12. **Implement** `CheckoutOrchestrator` to pass tests
13. **Write tests first** for `OrderCompletionHandler`
14. **Implement** `OrderCompletionHandler` to pass tests
15. **Write tests first** for `OrderController` changes
16. **Update** `OrderController` to pass tests
17. **Write tests first** for `ThankyouController` changes
18. **Update** `ThankyouController` to pass tests
19. **Update** `services.yaml` with event system wiring ← CRITICAL
20. **Write integration tests** for full flow
21. **Run full test suite**

### 9.7 Test: EventListenerProviderTest.php

```php
<?php

declare(strict_types=1);

namespace Tests\Component\Unit\EventSystem;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProvider;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentInitiatedEvent;

class EventListenerProviderTest extends TestCase
{
    public function testGetListenersForEvent_WithRegisteredHandler_ReturnsListener(): void
    {
        // Arrange
        $handler = new class implements HandlerInterface {
            public function handle(PaymentInitiatedEvent $event): void {}
        };

        $provider = new EventListenerProvider([$handler]);

        // Act
        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        // Assert
        $this->assertCount(1, $listeners);
        $this->assertIsCallable($listeners[0]);
    }

    public function testGetListenersForEvent_WithNoHandlers_ReturnsEmptyArray(): void
    {
        // Arrange
        $provider = new EventListenerProvider([]);

        // Act
        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        // Assert
        $this->assertCount(0, $listeners);
    }

    public function testGetListenersForEvent_WithMultipleHandlers_ReturnsSortedByPriority(): void
    {
        // Arrange
        $provider = new EventListenerProvider([]);

        $lowPriorityListener = fn($e) => 'low';
        $highPriorityListener = fn($e) => 'high';

        $provider->addListener(PaymentInitiatedEvent::class, $lowPriorityListener, 0);
        $provider->addListener(PaymentInitiatedEvent::class, $highPriorityListener, 100);

        // Act
        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        // Assert
        $this->assertCount(2, $listeners);
        $this->assertSame($highPriorityListener, $listeners[0]); // Higher priority first
        $this->assertSame($lowPriorityListener, $listeners[1]);
    }

    public function testAddListener_ManuallyAdded_IsRetrievable(): void
    {
        // Arrange
        $provider = new EventListenerProvider([]);
        $listener = fn($e) => 'test';

        // Act
        $provider->addListener(PaymentInitiatedEvent::class, $listener);
        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        // Assert
        $this->assertContains($listener, $listeners);
    }
}
```

---

## Appendix: Key Clarifications

### Why No Stripe API Calls?

1. **Security**: Payment card data never touches our server (PCI compliance)
2. **UX**: Stripe.js handles 3DS/SCA natively in browser
3. **Reliability**: Frontend confirms payment before form submission
4. **Separation**: Backend only handles accounting, not payment processing

### How Does Payment Confirmation Work?

1. **Frontend**: Stripe.js confirms payment, returns success
2. **Form Submit**: Includes `payment_intent_id` as hidden field
3. **OrderController**: Creates contract with `payment_intent_id`
4. **Webhook**: Stripe sends `payment_intent.succeeded` event
5. **WebhookController**: Matches `payment_intent_id` to contract
6. **ContractFulfillmentHandler**: Transitions contract to FULFILLED
