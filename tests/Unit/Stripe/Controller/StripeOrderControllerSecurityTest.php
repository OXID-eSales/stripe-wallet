<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Controller\SessionWriterInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\Return\StripeReturnResolver;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use OxidEsales\Payments\Stripe\Service\UserDataValidatorInterface;
use OxidEsales\Payments\Stripe\Service\UserFieldReaderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Security tests for StripeOrderController.
 *
 * Sprint 47: Security tests for controller hardening (STRP-99).
 * Sprint 114.3 (R-1.5): Replaced re-implemented method doubles with a seam-only
 * testable subclass. The REAL createCheckoutSession() and checkoutSuccess() are
 * exercised in every test below.
 *
 * Tests Fix 1 (no debug output), Fix 2 (no capture_mode_override),
 * Fix 10 (contract token validation), Fix 11 (error sanitization).
 *
 * checkoutSuccess() branch coverage (all via readReturnInputs / loadReturnContract):
 *   B1: session_id missing → "Payment information missing" (readReturnInputs line ~312)
 *   B2: contractId or contractToken not string → "Payment verification failed" (line ~319)
 *   B3: validateContractToken() fails → "Payment verification failed" (line ~324)
 *   B4: session contract id mismatches request contract id → "Payment verification failed" (line ~330)
 *   B5: repo->findById() returns null → "Payment verification failed" (loadReturnContract)
 *   B6: dispatchCheckoutReturn returns null → "Payment verification failed" (line ~297)
 *   B7: happy path → 'thankyou'
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\StripeOrderController::class)]
class StripeOrderControllerSecurityTest extends TestCase
{
    // ==========================================
    // Fix 1: No debug/secret exposure — createCheckoutSession()
    // ==========================================

    public function testCreateCheckoutSessionOutputContainsNoDebugInfo(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(function ($event) {
            $ctx = $event->getContext();
            $ctx->set('checkoutSessionId', 'cs_test_123');
            $ctx->set('checkoutUrl', 'https://checkout.stripe.com/test');
            $ctx->set('contractId', 'contract_abc');
            return $event;
        });

        $controller = $this->createCheckoutSessionController($eventDispatcher);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $data = json_decode($output, true);

        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('_debug', $data);
        $this->assertArrayNotHasKey('sk_prefix', $data);
        $this->assertArrayNotHasKey('pk_prefix', $data);
        $this->assertEquals('cs_test_123', $data['id']);
        $this->assertEquals('https://checkout.stripe.com/test', $data['url']);
        $this->assertEquals('contract_abc', $data['contract_id']);
    }

    public function testCreateCheckoutSessionOutputContainsNoSecretKey(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(function ($event) {
            $ctx = $event->getContext();
            $ctx->set('checkoutSessionId', 'cs_test_456');
            $ctx->set('checkoutUrl', 'https://checkout.stripe.com/test2');
            $ctx->set('contractId', 'contract_def');
            return $event;
        });

        $controller = $this->createCheckoutSessionController($eventDispatcher);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('sk_test', $output);
        $this->assertStringNotContainsString('sk_live', $output);
        $this->assertStringNotContainsString('rk_test', $output);
        $this->assertStringNotContainsString('rk_live', $output);
    }

    // ==========================================
    // Fix 2: No capture_mode_override
    // ==========================================

    public function testGetCaptureModeIgnoresRequestParameter(): void
    {
        $moduleConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $moduleConfig->method('getCaptureMode')->willReturn('automatic');

        $tokenService = $this->createMock(\OxidEsales\PaymentBase\Service\TokenServiceInterface::class);

        $helper = new ControllerRequestHelper($tokenService, $moduleConfig);

        // Helper reads from config service, not from request params
        $this->assertEquals('automatic', $helper->getCaptureMode());
    }

    // ==========================================
    // Fix 11: Error message sanitization — createCheckoutSession()
    // ==========================================

    public function testCreateCheckoutSessionReturnsGenericErrorOnException(): void
    {
        // Validator throws with a message containing a secret key
        $controller = $this->createCheckoutSessionController(
            $this->createMock(EventDispatcherInterface::class),
            keyValidationError: 'Stripe API key sk_test_abc123 is invalid'
        );

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $data = json_decode($output, true);

        $this->assertIsArray($data);
        $this->assertEquals('Payment processing failed. Please try again.', $data['error']);
        $this->assertStringNotContainsString('sk_test', $output);
        $this->assertStringNotContainsString('abc123', $output);
    }

    // ==========================================
    // Fix 10 / B1: Session ID missing
    // ==========================================

    /**
     * Branch B1: session_id not present in request → "Payment information missing".
     */
    public function testCheckoutSuccessB1RejectsOnMissingSessionId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller, $helper] = $this->createCheckoutSuccessController($eventDispatcher, [
            'sessionId' => null,
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
        $this->assertEquals('Payment information missing', $helper->lastError);
    }

    // ==========================================
    // B2: contractId or contractToken not a string
    // ==========================================

    /**
     * Branch B2: contractId missing from request → "Payment verification failed".
     */
    public function testCheckoutSuccessB2RejectsOnMissingContractId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller, $helper] = $this->createCheckoutSuccessController($eventDispatcher, [
            'sessionId' => 'cs_test_b2',
            'contractIdFromRequest' => null,
            'contractToken' => 'some_token',
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
        $this->assertEquals('Payment verification failed', $helper->lastError);
    }

    /**
     * Branch B2 (token missing): contractToken missing → "Payment verification failed".
     */
    public function testCheckoutSuccessB2RejectsOnMissingContractToken(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller, $helper] = $this->createCheckoutSuccessController($eventDispatcher, [
            'sessionId' => 'cs_test_b2token',
            'contractIdFromRequest' => 'contract_123',
            'contractToken' => null,
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
        $this->assertEquals('Payment verification failed', $helper->lastError);
    }

    // ==========================================
    // B3: validateContractToken() returns false
    // ==========================================

    /**
     * Branch B3: HMAC token verification fails → "Payment verification failed".
     * No secret is leaked into the error output.
     */
    public function testCheckoutSuccessB3RejectsOnInvalidToken(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller, $helper] = $this->createCheckoutSuccessController($eventDispatcher, [
            'sessionId' => 'cs_test_b3',
            'contractIdFromRequest' => 'contract_b3',
            'contractToken' => 'tampered_token_sk_test_secret',
            'tokenValidationResult' => false,
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
        $this->assertEquals('Payment verification failed', $helper->lastError);
        // Ensure no secret leaks into the error text
        $this->assertStringNotContainsString('sk_test', $helper->lastError ?? '');
    }

    // ==========================================
    // B4: the returned contract is not this shopper's
    // ==========================================

    /**
     * Branch B4: the contract named in the return belongs to someone else.
     *
     * This used to be a comparison against the session's stripe_contract_id, which
     * refused *any* contract but the last one the order page created — including
     * the customer's own, after Stripe had charged them. Ownership is what that
     * check was reaching for, and it is what is enforced here: the contract id is
     * already authenticated by its token, so the remaining question is whose
     * contract it is.
     */
    public function testCheckoutSuccessB4RejectsAnotherShoppersContract(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller, $helper] = $this->createCheckoutSuccessController($eventDispatcher, [
            'sessionId' => 'cs_test_b4',
            'contractIdFromRequest' => 'contract_from_url',
            'contractToken' => 'valid_token',
            'tokenValidationResult' => true,
            // the repository hands back a contract owned by user_1
            'currentUserId' => 'a_different_shopper',
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
        $this->assertEquals('Payment verification failed', $helper->lastError);
    }

    /**
     * The other half of B4, and the regression this replaced: the shopper's own
     * contract goes through even when the session points at a different one —
     * which it routinely does, because a checkout creates several.
     */
    public function testCheckoutSuccessB4AcceptsOwnContractWhenTheSessionPointsElsewhere(): void
    {
        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$dispatched) {
                $dispatched[] = $event;
                return $event;
            });

        [$controller] = $this->createCheckoutSuccessController($eventDispatcher, [
            'sessionId' => 'cs_test_b4b',
            'contractIdFromRequest' => 'contract_from_url',
            'contractIdFromSession' => 'contract_from_session',
            'contractToken' => 'valid_token',
            'tokenValidationResult' => true,
            'currentUserId' => 'user_1',
        ]);

        $controller->checkoutSuccess();

        $this->assertNotEmpty($dispatched, 'the return must reach the handler chain');
    }

    // ==========================================
    // B5: repo->findById() returns null (contract not found)
    // ==========================================

    /**
     * Branch B5: contract not found in repository → "Payment verification failed".
     */
    public function testCheckoutSuccessB5RejectsWhenContractNotFound(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        [$controller, $helper] = $this->createCheckoutSuccessController($eventDispatcher, [
            'sessionId' => 'cs_test_b5',
            'contractIdFromRequest' => 'contract_b5',
            'contractIdFromSession' => 'contract_b5',
            'contractToken' => 'valid_token',
            'tokenValidationResult' => true,
            'contractExistsInRepo' => false,
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
        $this->assertEquals('Payment verification failed', $helper->lastError);
    }

    // ==========================================
    // B6: dispatchCheckoutReturn returns null
    // ==========================================

    /**
     * Branch B6: checkout return responder returns null → "Payment verification failed".
     */
    public function testCheckoutSuccessB6RejectsWhenDispatchReturnsFailed(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        // The responder dispatches events but returns no orderId (null)
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        [$controller, $helper] = $this->createCheckoutSuccessController($eventDispatcher, [
            'sessionId' => 'cs_test_b6',
            'contractIdFromRequest' => 'contract_b6',
            'contractIdFromSession' => 'contract_b6',
            'contractToken' => 'valid_token',
            'tokenValidationResult' => true,
            'contractExistsInRepo' => true,
            'dispatchOrderId' => null,
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('payment', $result);
        $this->assertEquals('Payment verification failed', $helper->lastError);
    }

    // ==========================================
    // B7: Happy path
    // ==========================================

    /**
     * Branch B7: all checks pass → 'thankyou' returned, no errors displayed.
     */
    public function testCheckoutSuccessB7HappyPathReturnsThankyou(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(function ($event) {
            // Simulate responder setting orderId on success
            $event->getContext()->set('orderId', 'order_success_123');
            return $event;
        });

        [$controller, $helper] = $this->createCheckoutSuccessController($eventDispatcher, [
            'sessionId' => 'cs_test_b7',
            'contractIdFromRequest' => 'contract_b7',
            'contractIdFromSession' => 'contract_b7',
            'contractToken' => 'valid_token',
            'tokenValidationResult' => true,
            'contractExistsInRepo' => true,
            'dispatchOrderId' => 'order_success_123',
        ]);

        $result = $controller->checkoutSuccess();

        $this->assertEquals('thankyou', $result);
        $this->assertNull($helper->lastError);
    }

    // ==========================================
    // Factory methods
    // ==========================================

    /**
     * Create a controller for createCheckoutSession() tests.
     *
     * @param array<string, mixed> $options
     * @return StripeOrderController
     */
    private function createCheckoutSessionController(
        EventDispatcherInterface $eventDispatcher,
        ?string $keyValidationError = null,
        array $options = []
    ): StripeOrderController {
        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult = true;
        $helper->agbConfirmationRequired = false;
        $helper->basket = $this->createBasketWithUser($options['userId'] ?? 'user_test_1');

        return new class (
            $eventDispatcher,
            $helper,
            $keyValidationError
        ) extends StripeOrderController {
            private EventDispatcherInterface $mockDispatcher;
            private StubControllerRequestHelper $stubHelper;
            private ?string $keyValidationError;

            public function __construct(
                EventDispatcherInterface $dispatcher,
                StubControllerRequestHelper $helper,
                ?string $keyValidationError
            ) {
                $this->mockDispatcher = $dispatcher;
                $this->stubHelper = $helper;
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
                $basketUser = $this->stubHelper->basket?->getBasketUser();
                return $basketUser instanceof User ? $basketUser : null;
            }

            protected function getUserDataValidator(): UserDataValidatorInterface
            {
                return new class implements UserDataValidatorInterface {
                    public function validateForUser(UserFieldReaderInterface $reader): array
                    {
                        return [];
                    }

                    /** @param array<string, string> $fields */
                    public function validateFieldMap(array $fields, string $addressKind = 'billing'): array
                    {
                        return [];
                    }
                };
            }

            protected function exitWithJson(): void
            {
                // No-op in tests
            }

            protected function setHttpResponseCode(int $code): void
            {
                // No-op in tests
            }

            protected function getServiceFromContainer(string $serviceName): object
            {
                if ($serviceName === ConfigurationValidatorInterface::class) {
                    $error = $this->keyValidationError;
                    return new class ($error) implements ConfigurationValidatorInterface {
                        public function __construct(private readonly ?string $error)
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
                throw new \RuntimeException("Unknown service in createCheckoutSession test: $serviceName");
            }
        };
    }

    /**
     * Create a controller for checkoutSuccess() tests.
     *
     * @param array<string, mixed> $options
     * @return array{0: StripeOrderController, 1: StubControllerRequestHelper}
     */
    private function createCheckoutSuccessController(
        EventDispatcherInterface $eventDispatcher,
        array $options = []
    ): array {
        $helper = new StubControllerRequestHelper();
        $helper->checkoutSessionId = $options['sessionId'] ?? null;
        $helper->contractIdFromRequest = array_key_exists('contractIdFromRequest', $options)
            ? $options['contractIdFromRequest']
            : ($options['contractId'] ?? 'contract_default');
        $helper->contractTokenFromRequest = array_key_exists('contractToken', $options)
            ? $options['contractToken']
            : null;
        $helper->contractIdFromSession = array_key_exists('contractIdFromSession', $options)
            ? $options['contractIdFromSession']
            : ($options['contractId'] ?? 'contract_default');
        $helper->tokenValidationResult = $options['tokenValidationResult'] ?? true;

        // The shopper this request belongs to. getUser() reads it through the
        // basket, the same way production does; absent means "no user in session".
        if (isset($options['currentUserId'])) {
            $user = $this->createMock(User::class);
            $user->method('getId')->willReturn($options['currentUserId']);
            $basket = $this->createMock(Basket::class);
            $basket->method('getBasketUser')->willReturn($user);
            $helper->basket = $basket;
        }

        $contractExistsInRepo = $options['contractExistsInRepo'] ?? true;
        $dispatchOrderId = $options['dispatchOrderId'] ?? 'order_123';

        $controller = new class (
            $eventDispatcher,
            $helper,
            $contractExistsInRepo,
            $dispatchOrderId
        ) extends StripeOrderController {
            private EventDispatcherInterface $mockDispatcher;
            private StubControllerRequestHelper $stubHelper;
            private bool $contractExistsInRepo;
            private ?string $dispatchOrderId;

            public function __construct(
                EventDispatcherInterface $dispatcher,
                StubControllerRequestHelper $helper,
                bool $contractExistsInRepo,
                ?string $dispatchOrderId
            ) {
                $this->mockDispatcher = $dispatcher;
                $this->stubHelper = $helper;
                $this->contractExistsInRepo = $contractExistsInRepo;
                $this->dispatchOrderId = $dispatchOrderId;
            }

            protected function getRequestHelper(): ControllerRequestHelper
            {
                return $this->stubHelper;
            }

            protected function getEventDispatcher(): EventDispatcherInterface
            {
                return $this->mockDispatcher;
            }

            protected function resolveCheckoutReturnResponder(): CheckoutReturnResponder
            {
                $orderId = $this->dispatchOrderId;
                $writer = new class implements SessionWriterInterface {
                    public function writeSessChallenge(string $orderId): void {}
                };
                return new CheckoutReturnResponder($this->mockDispatcher, $writer);
            }

            protected function getServiceFromContainer(string $serviceName): object
            {
                if ($serviceName === ContractRepositoryInterface::class) {
                    $exists = $this->contractExistsInRepo;
                    return new class ($exists) implements ContractRepositoryInterface {
                        public function __construct(private readonly bool $exists)
                        {
                        }

                        public function save(PaymentContractInterface $contract): void {}

                        public function findById(string $id): ?PaymentContractInterface
                        {
                            if (!$this->exists) {
                                return null;
                            }
                            return new PaymentContract(
                                1,
                                'user_1',
                                BasketSnapshot::fromArray([
                                    'items' => [],
                                    'totalGross' => 1.0,
                                    'totalNet' => 1.0,
                                    'totalVat' => 0.0,
                                    'currency' => 'EUR',
                                ]),
                                $id,
                            );
                        }

                        public function findByUserId(string $userId): array { return []; }

                        public function findActiveByUserId(string $userId): ?PaymentContractInterface
                        {
                            return null;
                        }

                        public function findByOrderId(string $orderId): ?PaymentContractInterface
                        {
                            return null;
                        }

                        public function findByProviderOrderId(string $providerOrderId): ?PaymentContractInterface
                        {
                            return null;
                        }

                        public function findExpired(): array { return []; }

                        public function findStaleNotFinished(int $minutesOld, ?int $limit = null): array { return []; }
                    };
                }
                if ($serviceName === StripeReturnResolver::class) {
                    return new class extends StripeReturnResolver {
                        public function __construct()
                        {
                        }

                        public function resolve(
                            PaymentContractInterface $contract,
                            \OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface $context,
                        ): ReturnResolution {
                            return ReturnResolution::readyToCommit('pi_stub', 'pi_stub', 1.0, 'EUR');
                        }
                    };
                }
                throw new \RuntimeException("Unknown service in checkoutSuccess test: $serviceName");
            }
        };

        return [$controller, $helper];
    }

    private function createBasketWithUser(string $userId): Basket
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($userId);

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn(1);
        $basket->method('getBasketUser')->willReturn($user);
        $basket->method('getPaymentId')->willReturn('oe_payments_stripe_wallet');

        return $basket;
    }
}
