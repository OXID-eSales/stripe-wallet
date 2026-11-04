<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Return\CheckoutReturnCompletedEvent;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use OxidEsales\Payments\Stripe\Service\Return\StripeReturnResolver;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use OxidEsales\PaymentBase\Validation\User\UserDataValidatorInterface;
use OxidEsales\PaymentBase\Validation\User\UserFieldReaderInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeOrderController.
 *
 * These tests verify that the controller is THIN - it only dispatches events
 * and processes results from the context. No business logic in the controller.
 *
 * Sprint 71: Updated to use StubControllerRequestHelper after accessor extraction.
 * Sprint 74: Moved from Integration/ to Unit/. Added coverage for security validation, error paths
 *            and edge cases.
 * VR-20 R2: the Payment Element routes (executeStripePayment, stripeReturn) and their tests are gone.
 */
class StripeOrderControllerTest extends TestCase
{
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

    public function testCreateCheckoutSessionSetsSkipAddrCheckFlagBeforeDispatch(): void
    {
        // Arrange: snapshot the helper's session state at the moment dispatch fires
        $capturedFlagAtDispatch = null;

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$controller, $helper] = $this->createControllerWithMocks($eventDispatcher, [
            'basketNotEmpty' => true,
            'hasUser' => true,
        ]);

        $helperRef = $helper;
        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(
                function ($event) use ($helperRef, &$capturedFlagAtDispatch) {
                    // Runs synchronously — flag must already be present
                    $capturedFlagAtDispatch =
                        $helperRef->sessionVars[ControllerRequestHelper::SESSION_SKIP_ADDR_CHECK] ?? null;
                    $event->getContext()->set('checkoutSessionId', 'cs_flag_test');
                    $event->getContext()->set('contractId', 'contract_flag_test');
                    return $event;
                }
            );

        // Act
        ob_start();
        @$controller->createCheckoutSession();
        ob_get_clean();

        // Assert: the flag was set BEFORE the dispatch ran
        $this->assertTrue(
            $capturedFlagAtDispatch,
            'SESSION_SKIP_ADDR_CHECK must be set to true BEFORE the checkout-session dispatch'
        );
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

    public function testCheckoutSuccessDispatchesReturnAndAuthorizedEvents(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $dispatched = [];
        $eventDispatcher
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$dispatched) {
                $dispatched[] = $event;
                if ($event instanceof PaymentAuthorizedEvent) {
                    $event->getContext()->set('orderId', 'order_123');
                }
                return $event;
            });

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => 'cs_test_success',
            'contractId' => 'contract_xyz',
            'contractToken' => 'valid_token_123',
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('thankyou', $result);
        $this->assertInstanceOf(CheckoutReturnCompletedEvent::class, $dispatched[0]);
        $this->assertInstanceOf(PaymentAuthorizedEvent::class, $dispatched[1]);
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
            // VR-20 R5: no token - payment-base's guard lets only the contract's shopper in the initiating session in
            'tokenValidationResult' => false,
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

    /**
     * A checkout can end up with several contracts — the OPC payment-handler path
     * creates its own, and each embedded sheet carries the one it was opened with,
     * while the session pointer only ever names the last one the order page made.
     * Returning with any of the others must still work: the customer has already
     * been charged at this point, and refusing here loses the payment.
     */
    public function testCheckoutSuccessProceedsWhenTheSessionPointsAtAnotherContract(): void
    {
        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$dispatched) {
                $dispatched[] = $event;
                return $event;
            });

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => 'cs_test',
            'contractIdFromRequest' => 'contract_from_url',
            'contractIdFromSession' => 'contract_from_session',
            'contractToken' => 'valid_token',
            'basketNotEmpty' => true,
            // the stub repository hands back a contract owned by user_1
            'userId' => 'user_1',
        ]);

        $controller->checkoutSuccess();

        $this->assertNotEmpty($dispatched, 'the return must reach the handler chain');
    }

    /**
     * What the discarded session-pointer check was actually reaching for: a
     * contract that is not this shopper's is not theirs to complete.
     */
    public function testCheckoutSuccessReturnsPaymentForAnotherShoppersContract(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller] = $this->createControllerWithMocks($eventDispatcher, [
            'sessionId' => 'cs_test',
            'contractIdFromRequest' => 'contract_from_url',
            'contractToken' => 'valid_token',
            'basketNotEmpty' => true,
            // the stub repository's contract belongs to user_1, not to this shopper: payment-base's guard refuses
            'tokenValidationResult' => false,
            'userId' => 'someone_else',
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
    }

    // ==========================================
    // VR-24 H5 - render() is a GET without side effects
    // ==========================================

    public function testRenderDoesNotRotateSessChallenge(): void
    {
        [$controller, $helper] = $this->createControllerWithMocks(
            $this->createMock(EventDispatcherInterface::class),
            ['contractIdFromSession' => 'contract_in_session']
        );
        $helper->sessionVars['sess_challenge'] = 'challenge_of_the_page';

        $controller->render();

        $this->assertSame('challenge_of_the_page', $helper->sessionVars['sess_challenge']);
    }

    public function testRenderKeepsTheStripeSessionKeys(): void
    {
        [$controller, $helper] = $this->createControllerWithMocks(
            $this->createMock(EventDispatcherInterface::class),
            ['contractIdFromSession' => 'contract_in_session']
        );
        $helper->sessionVars['stripe_contract_id'] = 'contract_in_session';

        $controller->render();

        $this->assertSame('contract_in_session', $helper->sessionVars['stripe_contract_id']);
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
        $helper->checkoutSessionId = $options['sessionId'] ?? null;
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

            protected function getUserDataValidator(): UserDataValidatorInterface
            {
                return new class implements UserDataValidatorInterface {
                    public function validateForUser(string $pluginModuleId, UserFieldReaderInterface $reader): array
                    {
                        return [];
                    }

                    /** @param array<string, string> $fields */
                    public function validateFieldMap(string $pluginModuleId, array $fields, string $addressKind = 'billing'): array
                    {
                        return [];
                    }
                };
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
                    };
                }
                if ($serviceName === RetryCleanupService::class) {
                    return new class {
                        public function cleanupStaleContracts(int $minutesOld, ?int $limit = null): int
                        {
                            return 0;
                        }
                    };
                }
                if ($serviceName === ContractRepositoryInterface::class) {
                    return new class implements ContractRepositoryInterface {
                        public function save(
                            \OxidEsales\PaymentBase\Contract\PaymentContractInterface $contract
                        ): void {
                        }
                        public function findById(
                            string $id
                        ): ?\OxidEsales\PaymentBase\Contract\PaymentContractInterface {
                            $contract = new \OxidEsales\PaymentBase\Contract\PaymentContract(
                                1,
                                'user_1',
                                \OxidEsales\PaymentBase\Contract\BasketSnapshot::fromArray([
                                    'items' => [],
                                    'totalGross' => 1.0,
                                    'totalNet' => 1.0,
                                    'totalVat' => 0.0,
                                    'currency' => 'EUR',
                                ]),
                                $id,
                            );
                            return $contract;
                        }
                        public function findByUserId(string $userId): array
                        {
                            return [];
                        }
                        public function findActiveByUserId(
                            string $userId
                        ): ?\OxidEsales\PaymentBase\Contract\PaymentContractInterface {
                            return null;
                        }
                        public function findByOrderId(
                            string $orderId
                        ): ?\OxidEsales\PaymentBase\Contract\PaymentContractInterface {
                            return null;
                        }
                        public function findByProviderOrderId(
                            string $providerOrderId
                        ): ?\OxidEsales\PaymentBase\Contract\PaymentContractInterface {
                            return null;
                        }
                        public function findExpired(): array
                        {
                            return [];
                        }
                        public function findStaleNotFinished(int $minutesOld, ?int $limit = null): array
                        {
                            return [];
                        }
                    };
                }
                if ($serviceName === StripeReturnResolver::class) {
                    return new class extends StripeReturnResolver {
                        public function __construct()
                        {
                        }
                        public function resolve(
                            \OxidEsales\PaymentBase\Contract\PaymentContractInterface $contract,
                            \OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface $context,
                        ): ReturnResolution {
                            return ReturnResolution::readyToCommit('pi_stub', 'pi_stub', 1.0, 'EUR');
                        }
                    };
                }
                if ($serviceName === \OxidEsales\PaymentBase\Controller\CheckoutReturnResponder::class) {
                    // Use the test's dispatcher mock so the test's expectations
                    // (dispatch count, captured events) flow through the responder.
                    $writer = new class implements \OxidEsales\PaymentBase\Controller\SessionWriterInterface {
                        public function writeSessChallenge(string $orderId): void {}
                    };
                    return \OxidEsales\Payments\Stripe\Tests\Support\CheckoutReturnResponderFactory::create($this->mockDispatcher, $writer);
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
