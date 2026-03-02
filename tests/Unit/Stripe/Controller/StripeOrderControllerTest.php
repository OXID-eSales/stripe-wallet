<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentReturnEvent;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeOrderController.
 *
 * These tests verify that the controller is THIN - it only dispatches events
 * and processes results from the context. No business logic in the controller.
 *
 * Sprint 71: Updated to use StubControllerRequestHelper after accessor extraction.
 * Sprint 74: Moved from Integration/ to Unit/. Added coverage for processContextResults,
 *            security validation, error paths, and edge cases.
 */
class StripeOrderControllerTest extends TestCase
{
    // ==========================================
    // executeStripePayment — happy path
    // ==========================================

    public function testExecuteDispatchesStripePaymentExecuteEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripePaymentExecuteEvent::class))
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('redirectTarget', 'thankyou');
                return $event;
            });

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_123',
            'basketNotEmpty' => true,
        ]);

        $result = $controller->executeStripePayment();

        $this->assertEquals('thankyou', $result);
    }

    public function testExecuteReturnsRedirectFromContext(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('redirectTarget', 'payment');
                $event->getContext()->set('error', 'Payment failed');
                return $event;
            });

        [$controller, $helper] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_failed',
            'basketNotEmpty' => true,
        ]);

        $result = $controller->executeStripePayment();

        $this->assertEquals('payment', $result);
        $this->assertEquals('Payment failed', $helper->lastError);
    }

    public function testContextContainsBasketData(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $capturedContext = null;
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$capturedContext) {
                $capturedContext = $event->getContext();
                $capturedContext->set('redirectTarget', 'thankyou');
                return $event;
            });

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_123',
            'basketNotEmpty' => true,
            'userId' => 'user_abc',
            'paymentId' => 'osc_stripe_card',
        ]);

        $controller->executeStripePayment();

        $this->assertNotNull($capturedContext);
        $this->assertEquals('pi_test_123', $capturedContext->get('paymentIntentId'));
        $this->assertEquals('user_abc', $capturedContext->get('userId'));
        $this->assertEquals('osc_stripe_card', $capturedContext->get('paymentId'));
    }

    // ==========================================
    // executeStripePayment — validation edge cases (F9)
    // ==========================================

    public function testExecuteReturnsPaymentOnExpiredSession(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller, $helper] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_123',
            'basketNotEmpty' => true,
            'sessionChallengeResult' => false,
        ]);

        $result = $controller->executeStripePayment();

        $this->assertEquals('payment', $result);
        $this->assertStringContainsString('Session expired', $helper->lastError ?? '');
    }

    public function testControllerReturnsPaymentOnMissingPaymentIntentId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => null,
            'basketNotEmpty' => true,
        ]);

        $result = $controller->executeStripePayment();

        $this->assertEquals('payment', $result);
    }

    public function testControllerReturnsBasketOnEmptyBasket(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_123',
            'basketNotEmpty' => false,
        ]);

        $result = $controller->executeStripePayment();

        $this->assertEquals('basket', $result);
    }

    // ==========================================
    // processContextResults — 3DS, error display, orderId (F2, F3)
    // ==========================================

    public function testExecuteSets3DSTemplateParams(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                $ctx = $event->getContext();
                $ctx->set('requires3DS', true);
                $ctx->set('clientSecret', 'secret_123');
                $ctx->set('paymentIntentId', 'pi_3ds');
                $ctx->set('redirectTarget', 'order');
                return $event;
            });

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_3ds',
            'basketNotEmpty' => true,
        ]);

        $controller->executeStripePayment();

        $tplParams = $controller->getTestTplParams();
        $this->assertTrue($tplParams['stripe3DSRequired']);
        $this->assertEquals('secret_123', $tplParams['stripeClientSecret']);
        $this->assertEquals('pi_3ds', $tplParams['paymentIntentId']);
    }

    public function testExecuteDisplaysErrorFromContext(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('error', 'Card declined');
                $event->getContext()->set('redirectTarget', 'payment');
                return $event;
            });

        [$controller, $helper] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_declined',
            'basketNotEmpty' => true,
        ]);

        $controller->executeStripePayment();

        $this->assertEquals('Card declined', $helper->lastError);
    }

    public function testExecuteSetsOrderIdInSession(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('orderId', 'order_abc');
                $event->getContext()->set('redirectTarget', 'thankyou');
                return $event;
            });

        [$controller, $helper] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_123',
            'basketNotEmpty' => true,
        ]);

        $controller->executeStripePayment();

        $this->assertEquals('order_abc', $helper->sessionVars['sess_challenge']);
    }

    // ==========================================
    // createCheckoutSession — happy path
    // ==========================================

    public function testCreateCheckoutSessionDispatchesEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripeCheckoutSessionRequestEvent::class))
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('checkoutSessionId', 'cs_test_abc');
                $event->getContext()->set('contractId', 'contract_xyz');
                return $event;
            });

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'basketNotEmpty' => true,
            'hasUser' => true,
        ]);

        ob_start();
        @$controller->createCheckoutSession();
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Output should be valid JSON: ' . $output);
        $this->assertEquals('cs_test_abc', $decoded['id'] ?? null, 'Output: ' . $output);
        $this->assertEquals('contract_xyz', $decoded['contract_id'] ?? null);
    }

    public function testCheckoutSessionContextContainsCaptureModeFromConfig(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $capturedContext = null;
        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripeCheckoutSessionRequestEvent::class))
            ->willReturnCallback(function ($event) use (&$capturedContext) {
                $capturedContext = $event->getContext();
                $capturedContext->set('checkoutSessionId', 'cs_test_123');
                $capturedContext->set('contractId', 'contract_abc');
                return $event;
            });

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'basketNotEmpty' => true,
            'hasUser' => true,
            'captureMode' => 'manual',
        ]);

        ob_start();
        @$controller->createCheckoutSession();
        ob_get_clean();

        $this->assertNotNull($capturedContext);
        $this->assertEquals('manual', $capturedContext->get('captureMode'));
    }

    public function testCheckoutSessionContextContainsAutomaticCaptureModeByDefault(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $capturedContext = null;
        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripeCheckoutSessionRequestEvent::class))
            ->willReturnCallback(function ($event) use (&$capturedContext) {
                $capturedContext = $event->getContext();
                $capturedContext->set('checkoutSessionId', 'cs_test_456');
                $capturedContext->set('contractId', 'contract_def');
                return $event;
            });

        // captureMode not specified — should use StubControllerRequestHelper default ('automatic')
        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'basketNotEmpty' => true,
            'hasUser' => true,
        ]);

        ob_start();
        @$controller->createCheckoutSession();
        ob_get_clean();

        $this->assertNotNull($capturedContext);
        $this->assertEquals('automatic', $capturedContext->get('captureMode'));
    }

    // ==========================================
    // createCheckoutSession — error paths (F7)
    // ==========================================

    public function testCreateCheckoutSessionReturns403OnExpiredSession(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'basketNotEmpty' => true,
            'hasUser' => true,
            'sessionChallengeResult' => false,
        ]);

        ob_start();
        @$controller->createCheckoutSession();
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertStringContainsString('Session expired', $decoded['error'] ?? '');
    }

    public function testCreateCheckoutSessionReturnsErrorOnInvalidApiKey(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'basketNotEmpty' => true,
            'hasUser' => true,
            'keyValidationError' => 'Invalid API key',
        ]);

        ob_start();
        @$controller->createCheckoutSession();
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
    }

    public function testCreateCheckoutSessionReturnsErrorOnEmptyBasket(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'basketNotEmpty' => false,
            'hasUser' => true,
        ]);

        ob_start();
        @$controller->createCheckoutSession();
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
    }

    public function testCreateCheckoutSessionReturnsErrorOnNoUser(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'basketNotEmpty' => true,
            'hasUser' => false,
        ]);

        ob_start();
        @$controller->createCheckoutSession();
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
    }

    // ==========================================
    // checkoutSuccess — happy path + security validation (F4)
    // ==========================================

    public function testCheckoutSuccessDispatchesEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripeCheckoutReturnEvent::class))
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('redirectTarget', 'thankyou');
                $event->getContext()->set('orderId', 'order_123');
                return $event;
            });

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => 'cs_test_success',
            'contractId' => 'contract_xyz',
            'contractToken' => 'valid_token_123',
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('thankyou', $result);
    }

    public function testCheckoutSuccessReturnsPaymentOnMissingSessionId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => null,
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
    }

    public function testCheckoutSuccessReturnsPaymentOnMissingContractId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => 'cs_test',
            'contractIdFromRequest' => null,
            'contractToken' => 'valid_token',
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
    }

    public function testCheckoutSuccessReturnsPaymentOnMissingContractToken(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => 'cs_test',
            'contractId' => 'contract_123',
            'contractToken' => null,
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
    }

    public function testCheckoutSuccessReturnsPaymentOnInvalidToken(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => 'cs_test',
            'contractId' => 'contract_123',
            'contractToken' => 'bad_token',
            'tokenValidationResult' => false,
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
    }

    public function testCheckoutSuccessReturnsPaymentOnContractIdMismatch(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => 'cs_test',
            'contractIdFromRequest' => 'contract_from_url',
            'contractIdFromSession' => 'contract_from_session',
            'contractToken' => 'valid_token',
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
    }

    // ==========================================
    // stripeReturn — happy path + data verification (F10)
    // ==========================================

    public function testStripeReturnDispatchesEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripePaymentReturnEvent::class))
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('redirectTarget', 'thankyou');
                return $event;
            });

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_return',
            'redirectStatus' => 'succeeded',
        ]);

        $result = $controller->stripeReturn();

        $this->assertEquals('thankyou', $result);
    }

    public function testStripeReturnContextContainsData(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $capturedContext = null;
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$capturedContext) {
                $capturedContext = $event->getContext();
                $capturedContext->set('redirectTarget', 'thankyou');
                return $event;
            });

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_return',
            'redirectStatus' => 'succeeded',
            'contractIdFromSession' => 'contract_123',
        ]);

        $controller->stripeReturn();

        $this->assertNotNull($capturedContext);
        $this->assertEquals('pi_test_return', $capturedContext->get('paymentIntentId'));
        $this->assertEquals('succeeded', $capturedContext->get('redirectStatus'));
        $this->assertEquals('contract_123', $capturedContext->get('contractId'));
    }

    public function testStripeReturnReturnsPaymentOnMissingPaymentIntentId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => null,
            'sessionPaymentIntentId' => null,
        ]);

        $result = $controller->stripeReturn();

        $this->assertEquals('payment', $result);
    }

    // ==========================================
    // Helper infrastructure
    // ==========================================

    /**
     * Create controller with mocks. Returns controller or [controller, helper] tuple.
     *
     * @param array<string, mixed> $options
     * @return StripeOrderController|array{0: StripeOrderController, 1: StubControllerRequestHelper}
     */
    private function createControllerWithMocks(
        EventDispatcherInterface $eventDispatcher,
        array $options = []
    ): StripeOrderController|array {
        $basket = $this->createBasketMock($options);

        $helper = new StubControllerRequestHelper();
        $helper->paymentIntentIdFromRequest = $options['paymentIntentId'] ?? null;
        $helper->sessionPaymentIntentId = $options['sessionPaymentIntentId'] ?? null;
        $helper->checkoutSessionId = $options['sessionId'] ?? null;
        $helper->redirectStatus = $options['redirectStatus'] ?? null;
        $helper->contractIdFromRequest = $options['contractIdFromRequest'] ?? $options['contractId'] ?? null;
        $helper->contractTokenFromRequest = $options['contractToken'] ?? null;
        $helper->contractIdFromSession = $options['contractIdFromSession'] ?? $options['contractId'] ?? null;
        $helper->tokenValidationResult = $options['tokenValidationResult'] ?? true;
        $helper->sessionChallengeResult = $options['sessionChallengeResult'] ?? true;
        if (isset($options['captureMode'])) {
            $helper->captureMode = $options['captureMode'];
        }
        $helper->shopId = 1;
        $helper->shopUrl = 'https://test-shop.example.com/';
        $helper->sessionId = 'test_session_123';
        $helper->basket = $basket;

        $keyValidationError = $options['keyValidationError'] ?? null;
        $hasUser = $options['hasUser'] ?? true;

        $controller = new class (
            $eventDispatcher,
            $helper,
            $hasUser,
            $keyValidationError
        ) extends StripeOrderController {
            private EventDispatcherInterface $mockDispatcher;
            private StubControllerRequestHelper $stubHelper;
            private bool $hasUser;
            private ?string $keyValidationError;
            /** @var array<string, mixed> */
            private array $tplParams = [];

            public function __construct(
                EventDispatcherInterface $dispatcher,
                StubControllerRequestHelper $helper,
                bool $hasUser,
                ?string $keyValidationError
            ) {
                $this->mockDispatcher = $dispatcher;
                $this->stubHelper = $helper;
                $this->hasUser = $hasUser;
                $this->keyValidationError = $keyValidationError;
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
                if (!$this->hasUser) {
                    return null;
                }
                $basketUser = $this->stubHelper->basket?->getBasketUser();
                return $basketUser instanceof User ? $basketUser : null;
            }

            public function addTplParam($name, $value): void
            {
                $this->tplParams[$name] = $value;
            }

            /** @return array<string, mixed> */
            public function getTestTplParams(): array
            {
                return $this->tplParams;
            }

            protected function exitWithJson(): void
            {
                // Don't exit in tests
            }

            protected function getServiceFromContainer(string $serviceName): object
            {
                if ($serviceName === ConfigurationValidatorInterface::class) {
                    $error = $this->keyValidationError;
                    return new class ($error) {
                        public function __construct(private ?string $error)
                        {
                        }

                        public function getKeyValidationError(): ?string
                        {
                            return $this->error;
                        }

                        public function validateKeyPair(): bool
                        {
                            return $this->error === null;
                        }
                    };
                }
                if ($serviceName === RetryCleanupService::class) {
                    return new class {
                        public function cleanupPreviousAttempt(?string $contractId): bool
                        {
                            return false;
                        }

                        public function cleanupForUser(string $userId): bool
                        {
                            return false;
                        }
                    };
                }
                throw new \RuntimeException("Unknown service: $serviceName");
            }
        };

        // Tests that need helper access use array destructuring: [$controller, $helper]
        // Tests that don't can assign directly to $controller
        return [$controller, $helper];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createBasketMock(array $options = []): Basket
    {
        $basketNotEmpty = $options['basketNotEmpty'] ?? false;
        $userId = $options['userId'] ?? 'user_123';
        $paymentId = $options['paymentId'] ?? 'osc_stripe_card';

        $basket = $this->createMock(Basket::class);

        if (!$basketNotEmpty) {
            $basket->method('getProductsCount')->willReturn(0);
            $basket->method('getBasketUser')->willReturn(null);
            $basket->method('getPaymentId')->willReturn(null);
        } else {
            $basket->method('getProductsCount')->willReturn(1);
            $basket->method('getPaymentId')->willReturn($paymentId);

            $user = $this->createMock(User::class);
            $user->method('getId')->willReturn($userId);
            $basket->method('getBasketUser')->willReturn($user);
        }

        return $basket;
    }
}
