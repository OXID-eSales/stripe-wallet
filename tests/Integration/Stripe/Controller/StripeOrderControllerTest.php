<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Controller;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentReturnEvent;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeOrderController.
 *
 * These tests verify that the controller is THIN - it only dispatches events
 * and processes results from the context. No business logic in the controller.
 */
class StripeOrderControllerTest extends TestCase
{
    public function testExecuteDispatchesStripePaymentExecuteEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripePaymentExecuteEvent::class))
            ->willReturnCallback(function ($event) {
                // Simulate handler setting redirect target
                $event->getContext()->set('redirectTarget', 'thankyou');
                return $event;
            });

        $controller = $this->createControllerWithMocks($eventDispatcher, [
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

        $controller = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_failed',
            'basketNotEmpty' => true,
        ]);

        $result = $controller->executeStripePayment();

        $this->assertEquals('payment', $result);
    }

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

        $controller = $this->createControllerWithMocks($eventDispatcher, [
            'basketNotEmpty' => true,
            'hasUser' => true,
        ]);

        // Capture output (suppress headers warning in CLI)
        ob_start();
        @$controller->createCheckoutSession();
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Output should be valid JSON: ' . $output);
        $this->assertEquals('cs_test_abc', $decoded['id'] ?? null, 'Output: ' . $output);
        $this->assertEquals('contract_xyz', $decoded['contract_id'] ?? null);
    }

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

        $controller = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => 'cs_test_success',
            'contractId' => 'contract_xyz',
            'contractToken' => 'valid_token_123',
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('thankyou', $result);
    }

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

        $controller = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_return',
            'redirectStatus' => 'succeeded',
        ]);

        $result = $controller->stripeReturn();

        $this->assertEquals('thankyou', $result);
    }

    public function testControllerReturnsPaymentOnMissingPaymentIntentId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        // Should NOT dispatch event if payment intent is missing
        $eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => null,
            'basketNotEmpty' => true,
        ]);

        $result = $controller->executeStripePayment();

        $this->assertEquals('payment', $result);
    }

    public function testControllerReturnsBasketOnEmptyBasket(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        // Should NOT dispatch event if basket is empty
        $eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => 'pi_test_123',
            'basketNotEmpty' => false,
        ]);

        $result = $controller->executeStripePayment();

        $this->assertEquals('basket', $result);
    }

    public function testCheckoutSuccessReturnsPaymentOnMissingSessionId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => null,
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
    }

    public function testStripeReturnReturnsPaymentOnMissingPaymentIntentId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createControllerWithMocks($eventDispatcher, [
            'paymentIntentId' => null,
            'sessionPaymentIntentId' => null,
        ]);

        $result = $controller->stripeReturn();

        $this->assertEquals('payment', $result);
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

        $controller = $this->createControllerWithMocks($eventDispatcher, [
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

        // Test with manual capture mode
        $controller = $this->createControllerWithMocks($eventDispatcher, [
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

        // Test with default (automatic) capture mode
        $controller = $this->createControllerWithMocks($eventDispatcher, [
            'basketNotEmpty' => true,
            'hasUser' => true,
            // captureMode not specified, should default to 'automatic'
        ]);

        ob_start();
        @$controller->createCheckoutSession();
        ob_get_clean();

        $this->assertNotNull($capturedContext);
        $this->assertEquals('automatic', $capturedContext->get('captureMode'));
    }

    // --- Helper methods ---

    /**
     * @param array<string, mixed> $options
     */
    private function createControllerWithMocks(
        EventDispatcherInterface $eventDispatcher,
        array $options = []
    ): StripeOrderController {
        // Create basket mock if not provided
        if (!isset($options['basket'])) {
            $options['basket'] = $this->createBasketMock($options);
        }

        // Create user mock if not provided (and hasUser is true)
        if (!isset($options['user']) && ($options['hasUser'] ?? true)) {
            $options['user'] = $this->createUserMock($options['userId'] ?? 'user_123');
        }

        return new class($eventDispatcher, $options) extends StripeOrderController {
            private EventDispatcherInterface $mockDispatcher;
            /** @var array<string, mixed> */
            private array $options;
            /** @var array<string, mixed> */
            private array $sessionVars = [];
            /** @var array<string, mixed> */
            private array $tplParams = [];
            /** @var string[] */
            private array $errors = [];

            /**
             * @param array<string, mixed> $options
             */
            public function __construct(EventDispatcherInterface $dispatcher, array $options)
            {
                $this->mockDispatcher = $dispatcher;
                $this->options = $options;
                // Don't call parent constructor - it requires OXID framework
            }

            protected function getEventDispatcher(): EventDispatcherInterface
            {
                return $this->mockDispatcher;
            }

            protected function getBasketFromSession(): Basket
            {
                return $this->options['basket'];
            }

            protected function getPaymentIntentIdFromRequest(): ?string
            {
                return $this->options['paymentIntentId'] ?? null;
            }

            protected function getSessionPaymentIntentId(): ?string
            {
                return $this->options['sessionPaymentIntentId'] ?? null;
            }

            protected function getCheckoutSessionIdFromRequest(): ?string
            {
                return $this->options['sessionId'] ?? null;
            }

            protected function getRedirectStatusFromRequest(): ?string
            {
                return $this->options['redirectStatus'] ?? null;
            }

            protected function getContractIdFromRequest(): ?string
            {
                return $this->options['contractId'] ?? null;
            }

            protected function getContractTokenFromRequest(): ?string
            {
                return $this->options['contractToken'] ?? null;
            }

            protected function validateContractToken(?string $contractId, ?string $contractToken): bool
            {
                if ($contractId === null || $contractToken === null) {
                    return false;
                }
                return true;
            }

            protected function getContractIdFromSession(): ?string
            {
                return $this->options['contractId'] ?? null;
            }

            protected function getSessionId(): string
            {
                return 'test_session_123';
            }

            protected function getShopId(): int
            {
                return 1;
            }

            protected function getShopUrl(): string
            {
                return 'https://test-shop.example.com/';
            }

            protected function getCaptureMode(): string
            {
                return $this->options['captureMode'] ?? 'automatic';
            }

            public function getUser(): ?User
            {
                if (!($this->options['hasUser'] ?? true)) {
                    return null;
                }

                return $this->options['user'] ?? null;
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
                $this->tplParams[$name] = $value;
            }

            protected function addErrorToDisplay(string $message): void
            {
                $this->errors[] = $message;
            }

            protected function validateSessionChallenge(): bool
            {
                return true; // Skip CSRF in integration tests
            }

            protected function exitWithJson(): void
            {
                // Don't exit in tests
            }

            protected function logError(string $message, \Throwable $e): void
            {
                // Don't log in tests
            }

            protected function getServiceFromContainer(string $serviceName): object
            {
                if ($serviceName === ModuleConfigurationServiceInterface::class) {
                    return new class {
                        public function getPublishableKey(): string
                        {
                            return 'pk_test_51ABC12345DEF456GHI789';
                        }
                        public function getToken(): string
                        {
                            return 'sk_test_51ABC12345XYZ000111222';
                        }
                        public function isTestMode(): bool
                        {
                            return true;
                        }
                        public function getCaptureMode(): string
                        {
                            return 'automatic';
                        }
                    };
                }
                if ($serviceName === ConfigurationValidatorInterface::class) {
                    return new class {
                        public function getKeyValidationError(): ?string
                        {
                            return null;
                        }
                        public function validateKeyPair(): bool
                        {
                            return true;
                        }
                    };
                }
                throw new \RuntimeException("Unknown service: $serviceName");
            }
        };
    }

    /**
     * Create a Basket mock with configurable behavior
     *
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

    /**
     * Create a User mock
     */
    private function createUserMock(string $userId = 'user_123'): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($userId);
        return $user;
    }
}
