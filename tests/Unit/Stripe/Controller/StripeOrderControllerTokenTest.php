<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 67a: H3 — Contract token validation in checkoutSuccess().
 *
 * Ensures that checkoutSuccess() validates the contract_token parameter
 * via ContractTokenService BEFORE dispatching any events.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\StripeOrderController
 * @group sprint-67a
 * @group security
 */
final class StripeOrderControllerTokenTest extends TestCase
{
    /** @test */
    public function checkoutSuccessRejectsInvalidContractToken(): void
    {
        $controller = new TestableStripeOrderControllerForToken();
        $controller->setCheckoutSessionId('cs_test_123');
        $controller->setContractIdFromRequest('contract_abc');
        $controller->setContractTokenFromRequest('invalid_token');
        $controller->setContractIdFromSession('contract_abc');
        $controller->setTokenValidationResult(false);

        $result = $controller->checkoutSuccess();

        $this->assertSame('payment', $result);
        $this->assertStringContainsString('Payment verification failed', $controller->getLastError());
        $this->assertFalse($controller->wasEventDispatched(), 'Event should NOT be dispatched for invalid token');
    }

    /** @test */
    public function checkoutSuccessAcceptsValidContractToken(): void
    {
        $controller = new TestableStripeOrderControllerForToken();
        $controller->setCheckoutSessionId('cs_test_123');
        $controller->setContractIdFromRequest('contract_abc');
        $controller->setContractTokenFromRequest('valid_token');
        $controller->setContractIdFromSession('contract_abc');
        $controller->setTokenValidationResult(true);

        $result = $controller->checkoutSuccess();

        $this->assertTrue($controller->wasEventDispatched(), 'Event SHOULD be dispatched for valid token');
    }

    /** @test */
    public function checkoutSuccessRejectsMissingContractToken(): void
    {
        $controller = new TestableStripeOrderControllerForToken();
        $controller->setCheckoutSessionId('cs_test_123');
        $controller->setContractIdFromRequest('contract_abc');
        $controller->setContractTokenFromRequest(null);
        $controller->setContractIdFromSession('contract_abc');

        $result = $controller->checkoutSuccess();

        $this->assertSame('payment', $result);
        $this->assertStringContainsString('Payment verification failed', $controller->getLastError());
    }

    /** @test */
    public function checkoutSuccessRejectsMissingContractId(): void
    {
        $controller = new TestableStripeOrderControllerForToken();
        $controller->setCheckoutSessionId('cs_test_123');
        $controller->setContractIdFromRequest(null);
        $controller->setContractTokenFromRequest('some_token');
        $controller->setContractIdFromSession('contract_abc');

        $result = $controller->checkoutSuccess();

        $this->assertSame('payment', $result);
        $this->assertStringContainsString('Payment verification failed', $controller->getLastError());
    }

    /** @test */
    public function checkoutSuccessValidatesTokenBeforeSessionCheck(): void
    {
        $controller = new TestableStripeOrderControllerForToken();
        $controller->setCheckoutSessionId('cs_test_123');
        $controller->setContractIdFromRequest('contract_abc');
        $controller->setContractTokenFromRequest('invalid_token');
        $controller->setContractIdFromSession('different_contract');
        $controller->setTokenValidationResult(false);

        $result = $controller->checkoutSuccess();

        $this->assertSame('payment', $result);
        $this->assertStringContainsString('Payment verification failed', $controller->getLastError());
        $this->assertFalse($controller->wasEventDispatched());
    }
}

/**
 * Testable subclass for token validation tests.
 *
 * Overrides framework-dependent methods to avoid OXID bootstrap in unit tests.
 */
class TestableStripeOrderControllerForToken extends StripeOrderController
{
    private ?string $checkoutSessionId = null;
    private ?string $contractIdFromRequest = null;
    private ?string $contractTokenFromRequest = null;
    private ?string $contractIdFromSession = null;
    private bool $tokenValidationResult = false;
    private bool $eventDispatched = false;
    private ?string $lastError = null;
    /** @var array<string, mixed> */
    private array $sessionVars = [];

    public function __construct()
    {
        // No parent constructor — skip OXID bootstrap
    }

    public function setCheckoutSessionId(?string $id): void
    {
        $this->checkoutSessionId = $id;
    }

    public function setContractIdFromRequest(?string $id): void
    {
        $this->contractIdFromRequest = $id;
    }

    public function setContractTokenFromRequest(?string $token): void
    {
        $this->contractTokenFromRequest = $token;
    }

    public function setContractIdFromSession(?string $id): void
    {
        $this->contractIdFromSession = $id;
    }

    public function setTokenValidationResult(bool $result): void
    {
        $this->tokenValidationResult = $result;
    }

    public function wasEventDispatched(): bool
    {
        return $this->eventDispatched;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    protected function getCheckoutSessionIdFromRequest(): ?string
    {
        return $this->checkoutSessionId;
    }

    protected function getContractIdFromRequest(): ?string
    {
        return $this->contractIdFromRequest;
    }

    protected function getContractTokenFromRequest(): ?string
    {
        return $this->contractTokenFromRequest;
    }

    protected function getContractIdFromSession(): ?string
    {
        return $this->contractIdFromSession;
    }

    protected function validateContractToken(?string $contractId, ?string $contractToken): bool
    {
        if ($contractId === null || $contractToken === null) {
            return false;
        }
        return $this->tokenValidationResult;
    }

    protected function getEventDispatcher(): EventDispatcherInterface
    {
        $this->eventDispatched = true;
        return new class implements EventDispatcherInterface {
            public function addListener(string $eventClass, callable $listener, int $priority = 0): void
            {
            }

            public function removeListener(string $eventClass, callable $listener): void
            {
            }

            public function dispatch(EventInterface $event): EventInterface
            {
                return $event;
            }
        };
    }

    protected function addErrorToDisplay(string $message): void
    {
        $this->lastError = $message;
    }

    protected function processContextResults(EventContext $context): void
    {
        // No-op in tests
    }

    protected function setSessionVariable(string $key, mixed $value): void
    {
        $this->sessionVars[$key] = $value;
    }

    protected function deleteSessionVariable(string $key): void
    {
        unset($this->sessionVars[$key]);
    }

    public function addTplParam($name, $value): void
    {
        // No-op in tests
    }

    protected function logError(string $message, \Throwable $e): void
    {
        // No-op in tests
    }
}
