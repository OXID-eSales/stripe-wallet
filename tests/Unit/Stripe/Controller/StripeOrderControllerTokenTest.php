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
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 67a: H3 — Contract token validation in checkoutSuccess().
 *
 * Ensures that checkoutSuccess() validates the contract_token parameter
 * via ContractTokenService BEFORE dispatching any events.
 *
 * Sprint 71: Updated to use StubControllerRequestHelper after accessor extraction.
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
        $helper = new StubControllerRequestHelper();
        $helper->checkoutSessionId = 'cs_test_123';
        $helper->contractIdFromRequest = 'contract_abc';
        $helper->contractTokenFromRequest = 'invalid_token';
        $helper->contractIdFromSession = 'contract_abc';
        $helper->tokenValidationResult = false;

        $controller = new TestableStripeOrderControllerForToken($helper);

        $result = $controller->checkoutSuccess();

        $this->assertSame('payment', $result);
        $this->assertStringContainsString('Payment verification failed', $helper->lastError ?? '');
        $this->assertFalse($controller->wasEventDispatched(), 'Event should NOT be dispatched for invalid token');
    }

    /** @test */
    public function checkoutSuccessAcceptsValidContractToken(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->checkoutSessionId = 'cs_test_123';
        $helper->contractIdFromRequest = 'contract_abc';
        $helper->contractTokenFromRequest = 'valid_token';
        $helper->contractIdFromSession = 'contract_abc';
        $helper->tokenValidationResult = true;

        $controller = new TestableStripeOrderControllerForToken($helper);

        $result = $controller->checkoutSuccess();

        $this->assertTrue($controller->wasEventDispatched(), 'Event SHOULD be dispatched for valid token');
    }

    /** @test */
    public function checkoutSuccessRejectsMissingContractToken(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->checkoutSessionId = 'cs_test_123';
        $helper->contractIdFromRequest = 'contract_abc';
        $helper->contractTokenFromRequest = null;
        $helper->contractIdFromSession = 'contract_abc';

        $controller = new TestableStripeOrderControllerForToken($helper);

        $result = $controller->checkoutSuccess();

        $this->assertSame('payment', $result);
        $this->assertStringContainsString('Payment verification failed', $helper->lastError ?? '');
    }

    /** @test */
    public function checkoutSuccessRejectsMissingContractId(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->checkoutSessionId = 'cs_test_123';
        $helper->contractIdFromRequest = null;
        $helper->contractTokenFromRequest = 'some_token';
        $helper->contractIdFromSession = 'contract_abc';

        $controller = new TestableStripeOrderControllerForToken($helper);

        $result = $controller->checkoutSuccess();

        $this->assertSame('payment', $result);
        $this->assertStringContainsString('Payment verification failed', $helper->lastError ?? '');
    }

    /** @test */
    public function checkoutSuccessValidatesTokenBeforeSessionCheck(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->checkoutSessionId = 'cs_test_123';
        $helper->contractIdFromRequest = 'contract_abc';
        $helper->contractTokenFromRequest = 'invalid_token';
        $helper->contractIdFromSession = 'different_contract';
        $helper->tokenValidationResult = false;

        $controller = new TestableStripeOrderControllerForToken($helper);

        $result = $controller->checkoutSuccess();

        $this->assertSame('payment', $result);
        $this->assertStringContainsString('Payment verification failed', $helper->lastError ?? '');
        $this->assertFalse($controller->wasEventDispatched());
    }
}

/**
 * Testable subclass that injects a StubControllerRequestHelper.
 *
 * Sprint 71: Overrides getRequestHelper() instead of individual accessor methods.
 */
class TestableStripeOrderControllerForToken extends StripeOrderController
{
    private bool $eventDispatched = false;

    public function __construct(private readonly StubControllerRequestHelper $stubHelper)
    {
        // No parent constructor — skip OXID bootstrap
    }

    public function wasEventDispatched(): bool
    {
        return $this->eventDispatched;
    }

    protected function getRequestHelper(): ControllerRequestHelper
    {
        return $this->stubHelper;
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

    protected function processContextResults(EventContext $context): void
    {
        // No-op in tests
    }

    public function addTplParam($name, $value): void
    {
        // No-op in tests
    }

    protected function exitWithJson(): void
    {
        // No-op in tests
    }
}
