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
 * Sprint 101: Tests for AGB confirmation enforcement in StripeOrderController::createCheckoutSession().
 *
 * Verifies that when blConfirmAGB is active, the controller rejects requests
 * that do not carry ord_agb=1, and passes requests that do.
 *
 * Mirrors the testable-subclass pattern from StripeOrderControllerRetryTest.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\StripeOrderController
 */
class StripeOrderControllerAgbConfirmationTest extends TestCase
{
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private RetryCleanupService&MockObject $cleanupService;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->cleanupService = $this->createMock(RetryCleanupService::class);
    }

    // ==========================================
    // T1 — blConfirmAGB on; ord_agb missing → HTTP 400; event NOT dispatched
    // ==========================================

    public function testCreateCheckoutSessionRejectsWhenAgbRequiredAndParamMissing(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult = true;
        $helper->agbConfirmationRequired = true;
        $helper->agbAcceptedFromRequest = false;
        $helper->basket = $this->createBasketMock();

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController($helper);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $json = json_decode((string) $output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
        $this->assertSame(400, $controller->getLastHttpStatusCode());
    }

    // ==========================================
    // T2 — blConfirmAGB on; ord_agb = "0" → HTTP 400; event NOT dispatched
    // ==========================================

    public function testCreateCheckoutSessionRejectsWhenAgbRequiredAndParamIsZero(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult = true;
        $helper->agbConfirmationRequired = true;
        $helper->agbAcceptedFromRequest = false; // "0" maps to false in stub
        $helper->basket = $this->createBasketMock();

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController($helper);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $json = json_decode((string) $output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
        $this->assertSame(400, $controller->getLastHttpStatusCode());
    }

    // ==========================================
    // T3 — blConfirmAGB on; ord_agb = "1" → HTTP 200; event dispatched
    // ==========================================

    public function testCreateCheckoutSessionProceedsWhenAgbRequiredAndParamIsOne(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult = true;
        $helper->agbConfirmationRequired = true;
        $helper->agbAcceptedFromRequest = true; // "1"
        $helper->basket = $this->createBasketMock();

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripeCheckoutSessionRequestEvent::class))
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('checkoutSessionId', 'cs_test_abc');
                $event->getContext()->set('checkoutUrl', 'https://checkout.stripe.com/pay/cs_test_abc');
                $event->getContext()->set('contractId', 'contract_xyz');
                return $event;
            });

        $controller = $this->createController($helper);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $json = json_decode((string) $output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('id', $json);
        $this->assertArrayHasKey('url', $json);
        $this->assertArrayHasKey('contract_id', $json);
        $this->assertSame(200, $controller->getLastHttpStatusCode());
    }

    // ==========================================
    // T4 — blConfirmAGB off; ord_agb missing → gate bypassed; HTTP 200
    // ==========================================

    public function testCreateCheckoutSessionBypassesGateWhenAgbNotRequired(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult = true;
        $helper->agbConfirmationRequired = false;
        $helper->agbAcceptedFromRequest = false; // still false — but gate must be skipped
        $helper->basket = $this->createBasketMock();

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripeCheckoutSessionRequestEvent::class))
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('checkoutSessionId', 'cs_no_agb');
                $event->getContext()->set('checkoutUrl', 'https://checkout.stripe.com/pay/cs_no_agb');
                $event->getContext()->set('contractId', 'contract_no_agb');
                return $event;
            });

        $controller = $this->createController($helper);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $json = json_decode((string) $output, true);
        $this->assertIsArray($json);
        $this->assertSame(200, $controller->getLastHttpStatusCode());
        $this->assertArrayHasKey('id', $json);
    }

    // ==========================================
    // T5 — blConfirmAGB on; ord_agb = "1"; basket empty → 500 wins over AGB check
    // AGB check passes but basket validation fails — proves ordering is correct.
    // ==========================================

    public function testCreateCheckoutSessionBasketErrorWinsAfterAgbPasses(): void
    {
        $emptyBasket = $this->createMock(Basket::class);
        $emptyBasket->method('getProductsCount')->willReturn(0);
        $emptyBasket->method('getBasketUser')->willReturn($this->createMock(User::class));

        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult = true;
        $helper->agbConfirmationRequired = true;
        $helper->agbAcceptedFromRequest = true; // AGB passes
        $helper->basket = $emptyBasket;         // but basket is empty → 500

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController($helper);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $json = json_decode((string) $output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
        // AGB passed — error is about the basket, not AGB → 500
        $this->assertSame(500, $controller->getLastHttpStatusCode());
    }

    // ==========================================
    // T6 — blConfirmAGB on; ord_agb missing; session challenge invalid → 403 wins
    // AGB check is AFTER session validation — session error takes precedence.
    // ==========================================

    public function testCreateCheckoutSessionSessionErrorWinsBeforeAgbCheck(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult = false; // invalid session → 403
        $helper->agbConfirmationRequired = true;
        $helper->agbAcceptedFromRequest = false;
        $helper->basket = $this->createBasketMock();

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController($helper);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $json = json_decode((string) $output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
        // Session validation failed → 403 (not 400 from AGB)
        $this->assertSame(403, $controller->getLastHttpStatusCode());
    }

    // ==========================================
    // Helpers
    // ==========================================

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
            private int $lastHttpStatusCode = 200;

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

            public function getLastHttpStatusCode(): int
            {
                return $this->lastHttpStatusCode;
            }

            protected function setHttpResponseCode(int $code): void
            {
                $this->lastHttpStatusCode = $code;
            }
        };
    }
}
