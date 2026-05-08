<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 72: Tests for retry cleanup in StripeOrderController::createCheckoutSession().
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\StripeOrderController
 */
class StripeOrderControllerRetryTest extends TestCase
{
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private RetryCleanupService&MockObject $cleanupService;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->cleanupService = $this->createMock(RetryCleanupService::class);
    }

    public function testCreateCheckoutSessionCleansUpPreviousAttempt(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->contractIdFromSession = 'contract_previous_123';
        $helper->sessionChallengeResult = true;
        $helper->agbAcceptedFromRequest = true; // Sprint 101: AGB gate must be satisfied
        $helper->basket = $this->createBasketMock();

        $this->cleanupService
            ->expects($this->once())
            ->method('cleanupPreviousAttempt')
            ->with('contract_previous_123')
            ->willReturn(true);

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('checkoutSessionId', 'cs_new');
                $event->getContext()->set('contractId', 'contract_new');
                return $event;
            });

        $controller = $this->createController($helper);

        ob_start();
        @$controller->createCheckoutSession();
        ob_get_clean();
    }

    public function testCreateCheckoutSessionGeneratesNewSessChallenge(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->contractIdFromSession = 'contract_old';
        $helper->sessionChallengeResult = true;
        $helper->agbAcceptedFromRequest = true; // Sprint 101: AGB gate must be satisfied
        $helper->basket = $this->createBasketMock();

        $this->cleanupService->method('cleanupPreviousAttempt')->willReturn(true);

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('checkoutSessionId', 'cs_test');
                $event->getContext()->set('contractId', 'contract_new');
                return $event;
            });

        $controller = $this->createController($helper);

        ob_start();
        @$controller->createCheckoutSession();
        ob_get_clean();

        // After cleanup, sess_challenge should be set with a new value
        $this->assertArrayHasKey('sess_challenge', $helper->sessionVars);
        $this->assertSame('new_uid_generated', $helper->sessionVars['sess_challenge']);
    }

    public function testCreateCheckoutSessionSkipsCleanupWhenNoContract(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->contractIdFromSession = null;
        $helper->sessionChallengeResult = true;
        $helper->agbAcceptedFromRequest = true; // Sprint 101: AGB gate must be satisfied
        $helper->basket = $this->createBasketMock();

        $this->cleanupService
            ->expects($this->never())
            ->method('cleanupPreviousAttempt');

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('checkoutSessionId', 'cs_test');
                $event->getContext()->set('contractId', 'contract_new');
                return $event;
            });

        $controller = $this->createController($helper);

        ob_start();
        @$controller->createCheckoutSession();
        ob_get_clean();
    }

    public function testCheckoutCancelCleansUpAndRedirectsToPayment(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->contractIdFromSession = 'contract_cancelled';
        $helper->basket = $this->createBasketMock();

        $this->cleanupService
            ->expects($this->once())
            ->method('cleanupPreviousAttempt')
            ->with('contract_cancelled')
            ->willReturn(true);

        $controller = $this->createController($helper);
        $result = $controller->checkoutCancel();

        $this->assertSame('payment', $result);
        // Sprint 88: Session cleared + new sess_challenge generated for retry
        $this->assertArrayHasKey('sess_challenge', $helper->sessionVars);
        $this->assertNotEmpty($helper->sessionVars['sess_challenge']);
    }

    public function testCheckoutCancelContinuesOnCleanupFailure(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->contractIdFromSession = 'contract_fail';
        $helper->basket = $this->createBasketMock();

        $this->cleanupService
            ->expects($this->once())
            ->method('cleanupPreviousAttempt')
            ->with('contract_fail')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $controller = $this->createController($helper);
        $result = $controller->checkoutCancel();

        $this->assertSame('payment', $result);
        // Sprint 88: New sess_challenge always generated, even on cleanup failure
        $this->assertArrayHasKey('sess_challenge', $helper->sessionVars);
    }

    public function testCheckoutCancelSkipsCleanupWhenNoContract(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->contractIdFromSession = null;
        $helper->basket = $this->createBasketMock();

        $this->cleanupService
            ->expects($this->never())
            ->method('cleanupPreviousAttempt');

        $controller = $this->createController($helper);
        $result = $controller->checkoutCancel();

        $this->assertSame('payment', $result);
    }

    private function createBasketMock(): Basket
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_123');

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn(1);
        $basket->method('getPaymentId')->willReturn('oe_payments_stripe_wallet');
        $basket->method('getBasketUser')->willReturn($user);

        return $basket;
    }

    private function createController(StubControllerRequestHelper $helper): StripeOrderController
    {
        $eventDispatcher = $this->eventDispatcher;
        $cleanupService = $this->cleanupService;

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_123');

        return new class ($eventDispatcher, $helper, $cleanupService, $user) extends StripeOrderController {
            private EventDispatcherInterface $mockDispatcher;
            private StubControllerRequestHelper $stubHelper;
            private RetryCleanupService $mockCleanupService;
            private User $mockUser;

            public function __construct(
                EventDispatcherInterface $dispatcher,
                StubControllerRequestHelper $helper,
                RetryCleanupService $cleanupService,
                User $user
            ) {
                $this->mockDispatcher = $dispatcher;
                $this->stubHelper = $helper;
                $this->mockCleanupService = $cleanupService;
                $this->mockUser = $user;
            }

            protected function getRequestHelper(): ControllerRequestHelper
            {
                return $this->stubHelper;
            }

            protected function getEventDispatcher(): EventDispatcherInterface
            {
                return $this->mockDispatcher;
            }

            public function getUser(): ?User
            {
                return $this->mockUser;
            }

            public function addTplParam($name, $value): void
            {
                // No-op in tests
            }

            protected function exitWithJson(): void
            {
                // Don't exit in tests
            }

            protected function setHttpResponseCode(int $code): void
            {
                // No-op in tests
            }

            protected function generateNewSessChallenge(): string
            {
                return 'new_uid_generated';
            }

            protected function getServiceFromContainer(string $serviceName): object
            {
                if ($serviceName === RetryCleanupService::class) {
                    return $this->mockCleanupService;
                }
                if ($serviceName === ConfigurationValidatorInterface::class) {
                    return new class {
                        public function getKeyValidationError(): ?string
                        {
                            return null;
                        }
                    };
                }
                throw new \RuntimeException("Unknown service: $serviceName");
            }
        };
    }
}
