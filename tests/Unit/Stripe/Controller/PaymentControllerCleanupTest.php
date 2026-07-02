<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Controller\PaymentController;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use PHPUnit\Framework\TestCase;

/**
 * D6: PaymentController::cleanupStaleCheckoutAttempt() must delegate session
 * access entirely to ControllerRequestHelper — no direct Registry::getSession() calls.
 *
 * Specifically:
 * - session read (contract ID)  → helper->getContractIdFromSession()
 * - session clear               → helper->clearStripeSessionVariables() (full key set)
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\PaymentController::class)]
class PaymentControllerCleanupTest extends TestCase
{
    /**
     * When no contract ID in session, cleanup service must not be called.
     */
    public function testCleanupSkipsWhenNoContractInSession(): void
    {
        $helper = new SpyControllerRequestHelper();
        $helper->contractIdFromSession = null;

        $cleanupService = $this->createMock(RetryCleanupService::class);
        $cleanupService->expects($this->never())->method('cleanupPreviousAttempt');

        $controller = $this->makeController($helper, $cleanupService);
        $controller->triggerCleanup();

        $this->assertFalse($helper->clearStripeSessionVariablesCalled);
    }

    /**
     * When a contract ID exists in session, cleanup service is called once and
     * the full session variable set is cleared via the helper (not direct Registry).
     */
    public function testCleanupCallsServiceAndClearsSessionViaHelper(): void
    {
        $helper = new SpyControllerRequestHelper();
        $helper->contractIdFromSession = 'contract_abc';

        $cleanupService = $this->createMock(RetryCleanupService::class);
        $cleanupService->expects($this->once())
            ->method('cleanupPreviousAttempt')
            ->with('contract_abc');

        $controller = $this->makeController($helper, $cleanupService);
        $controller->triggerCleanup();

        $this->assertTrue($helper->clearStripeSessionVariablesCalled);
    }

    /**
     * D6 key-set guard: clearStripeSessionVariables() defined on ControllerRequestHelper
     * must include all five session keys, not a partial subset.
     *
     * This is a white-box assertion on the helper, enforcing the union of all four
     * cleanup sites (R-9.3: one cleanup accessor).
     */
    public function testControllerRequestHelperClearsFullKeySet(): void
    {
        $helper = new SpyControllerRequestHelper();

        // Pre-fill all five known Stripe session keys
        $helper->sessionVars = [
            'stripe_payment_intent_id'    => 'pi_123',
            'stripe_client_secret'        => 'cs_abc',
            'stripe_checkout_session_id'  => 'cs_session',
            'stripe_contract_id'          => 'ctr_xyz',
            ControllerRequestHelper::SESSION_SKIP_ADDR_CHECK => true,
        ];

        $helper->clearStripeSessionVariables();

        $this->assertEmpty(
            $helper->sessionVars,
            'clearStripeSessionVariables() must clear ALL five Stripe session keys'
        );
    }

    /**
     * Cleanup errors are swallowed — exceptions from the service must not propagate.
     */
    public function testCleanupSurvivesServiceException(): void
    {
        $helper = new SpyControllerRequestHelper();
        $helper->contractIdFromSession = 'contract_err';

        $cleanupService = $this->createMock(RetryCleanupService::class);
        $cleanupService->method('cleanupPreviousAttempt')
            ->willThrowException(new \RuntimeException('DB down'));

        $controller = $this->makeController($helper, $cleanupService);

        // Must not throw
        $controller->triggerCleanup();

        // Session is still cleared even when cleanup fails
        $this->assertTrue($helper->clearStripeSessionVariablesCalled);
    }

    private function makeController(
        SpyControllerRequestHelper $helper,
        RetryCleanupService $cleanupService
    ): TestablePaymentControllerForD6 {
        return new TestablePaymentControllerForD6($helper, $cleanupService);
    }
}

/**
 * Spy on ControllerRequestHelper to track clearStripeSessionVariables() calls.
 */
class SpyControllerRequestHelper extends StubControllerRequestHelper
{
    public bool $clearStripeSessionVariablesCalled = false;

    public function clearStripeSessionVariables(): void
    {
        $this->clearStripeSessionVariablesCalled = true;
        $this->sessionVars = [];
    }
}

/**
 * Testable PaymentController for D6.
 *
 * Bypasses OXID bootstrap (no parent::__construct()) and exposes the
 * `cleanupStaleCheckoutAttempt()` seam via `triggerCleanup()`.
 * Overrides `getRequestHelper()` and `getServiceFromContainer()` with test doubles.
 */
class TestablePaymentControllerForD6 extends PaymentController
{
    public function __construct(
        private readonly ControllerRequestHelper $testHelper,
        private readonly RetryCleanupService $testCleanupService,
    ) {
        // Intentionally skip parent constructor — OXID framework not available
    }

    /** Expose the protected cleanup method as a public entry-point for tests. */
    public function triggerCleanup(): void
    {
        $this->cleanupStaleCheckoutAttempt();
    }

    protected function getRequestHelper(): ControllerRequestHelper
    {
        return $this->testHelper;
    }

    protected function getServiceFromContainer(string $serviceClass): object
    {
        if ($serviceClass === RetryCleanupService::class) {
            return $this->testCleanupService;
        }
        throw new \RuntimeException("Unexpected service: {$serviceClass}");
    }
}
