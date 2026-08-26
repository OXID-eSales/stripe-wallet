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
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use OxidEsales\Payments\Stripe\Service\Return\StripeReturnResolver;
use OxidEsales\Payments\Stripe\Service\UserDataValidatorInterface;
use OxidEsales\Payments\Stripe\Service\UserFieldReaderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 128: Tests for AGB consent persistence and restoration in StripeOrderController.
 *
 * Covers:
 *  C1 — ensureAgbAccepted() persists consent when ord_agb=1 AND AGB required.
 *  C2 — ensureAgbAccepted() does NOT persist consent when ord_agb absent.
 *  C3 — isPriorAgbConsent() reflects the helper flag (true / false).
 *  C4 — Consent survives clearStripeSessionVariables() (the survival sentinel).
 *  C5 — checkoutSuccess() clears the consent flag.
 *  C6 — checkoutCancel() clears the consent flag.
 *  C7 — checkoutCancel() clears consent even without a contractId in session.
 *
 * Uses the testable-subclass pattern established in StripeOrderControllerAgbConfirmationTest.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\StripeOrderController::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper::class)]
class StripeOrderControllerAgbConsentTest extends TestCase
{
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private RetryCleanupService&MockObject $cleanupService;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->cleanupService  = $this->createMock(RetryCleanupService::class);
    }

    // ==========================================
    // C1 — ensureAgbAccepted() persists consent when ord_agb=1 and AGB required
    // ==========================================

    public function testCreateCheckoutSessionPersistsAgbConsentWhenOrdAgbTruthy(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult  = true;
        $helper->agbConfirmationRequired = true;
        $helper->agbAcceptedFromRequest  = true;
        $helper->basket                  = $this->createBasketMock();

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) {
                $event->getContext()->set('checkoutSessionId', 'cs_test_abc');
                $event->getContext()->set('checkoutUrl', 'https://checkout.stripe.com/pay/cs_test_abc');
                $event->getContext()->set('contractId', 'contract_xyz');
                return $event;
            });

        $controller = $this->createController($helper);

        ob_start();
        $controller->createCheckoutSession();
        ob_get_clean();

        $this->assertTrue(
            $helper->hasPersistedAgbConsent(),
            'Consent flag must be set in session when ord_agb=1 and AGB is required'
        );
    }

    // ==========================================
    // C2 — ensureAgbAccepted() does NOT persist when ord_agb absent
    // ==========================================

    public function testCreateCheckoutSessionDoesNotPersistConsentWhenOrdAgbAbsent(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult  = true;
        $helper->agbConfirmationRequired = true;
        $helper->agbAcceptedFromRequest  = false;
        $helper->basket                  = $this->createBasketMock();

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $controller = $this->createController($helper);

        ob_start();
        $controller->createCheckoutSession();
        ob_get_clean();

        $this->assertFalse(
            $helper->hasPersistedAgbConsent(),
            'Consent flag must NOT be set when ord_agb is absent/false'
        );
    }

    // ==========================================
    // C3 — isPriorAgbConsent() reflects the helper flag
    // ==========================================

    public function testIsPriorAgbConsentReflectsSessionFlagWhenTrue(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->persistAgbConsent();

        $controller = $this->createController($helper);

        $this->assertTrue(
            $controller->isPriorAgbConsent(),
            'isPriorAgbConsent() must return true when session flag is set'
        );
    }

    public function testIsPriorAgbConsentReflectsSessionFlagWhenFalse(): void
    {
        $helper = new StubControllerRequestHelper();
        // Flag not set — expect false

        $controller = $this->createController($helper);

        $this->assertFalse(
            $controller->isPriorAgbConsent(),
            'isPriorAgbConsent() must return false when session flag is absent'
        );
    }

    // ==========================================
    // C4 — Consent survives clearStripeSessionVariables() (survival sentinel)
    //
    // This guards the core invariant: the AGB consent key is deliberately
    // OUTSIDE the clearStripeSessionVariables() set so it survives
    // cleanupStaleCheckoutOnRender() on the fresh-load return. If this test
    // fails, someone added the key to the cleared set — the fix breaks.
    // ==========================================

    public function testConsentSurvivesClearStripeSessionVariables(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->persistAgbConsent();

        $this->assertTrue($helper->hasPersistedAgbConsent(), 'Pre-condition: flag must be set');

        $helper->clearStripeSessionVariables();

        $this->assertTrue(
            $helper->hasPersistedAgbConsent(),
            'Consent flag must survive clearStripeSessionVariables() — it is deliberately outside the cleared set'
        );
    }

    // ==========================================
    // C5 — checkoutSuccess() clears the consent flag on order success
    // ==========================================

    public function testCheckoutSuccessClearsConsent(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->persistAgbConsent();
        $this->assertTrue($helper->hasPersistedAgbConsent(), 'Pre-condition: flag must be set');

        // Wire checkoutSuccess() inputs for a successful flow
        $helper->checkoutSessionId        = 'cs_test_success';
        $helper->contractIdFromRequest    = 'contract_abc';
        $helper->contractTokenFromRequest = 'token_abc';
        $helper->tokenValidationResult    = true;

        // The dispatcher must set orderId on PaymentAuthorizedEvent for checkoutSuccess
        // to reach the clearStripeSessionVariables() + clearAgbConsent() lines.
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) {
                if ($event instanceof PaymentAuthorizedEvent) {
                    $event->getContext()->set('orderId', 'order_123');
                }
                return $event;
            });

        $controller = $this->createController($helper, withCheckoutReturnServices: true);

        $result = $controller->checkoutSuccess();

        $this->assertSame('thankyou', $result, 'Pre-condition: must reach success path');
        $this->assertFalse(
            $helper->hasPersistedAgbConsent(),
            'checkoutSuccess() must clear the consent flag on order success'
        );
    }

    // ==========================================
    // C6 — checkoutCancel() clears the consent flag
    // ==========================================

    public function testCheckoutCancelClearsConsent(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->persistAgbConsent();
        $helper->contractIdFromSession = 'contract_abc';

        $this->assertTrue($helper->hasPersistedAgbConsent(), 'Pre-condition: flag must be set');

        $controller = $this->createController($helper);

        $controller->checkoutCancel();

        $this->assertFalse(
            $helper->hasPersistedAgbConsent(),
            'checkoutCancel() must clear the consent flag'
        );
    }

    // ==========================================
    // C7 — checkoutCancel() clears consent even when no contractId in session
    // ==========================================

    public function testCheckoutCancelClearsConsentWhenNoContractId(): void
    {
        $helper = new StubControllerRequestHelper();
        $helper->persistAgbConsent();
        $helper->contractIdFromSession = null; // no contract in session

        $controller = $this->createController($helper);

        $controller->checkoutCancel();

        $this->assertFalse(
            $helper->hasPersistedAgbConsent(),
            'checkoutCancel() must clear consent even without a contractId in session'
        );
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

    private function createController(
        StubControllerRequestHelper $helper,
        bool $withCheckoutReturnServices = false
    ): StripeOrderController {
        $eventDispatcher = $this->eventDispatcher;
        $cleanupService  = $this->cleanupService;

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_123');

        return new class (
            $eventDispatcher,
            $helper,
            $cleanupService,
            $user,
            $withCheckoutReturnServices
        ) extends StripeOrderController {
            private EventDispatcherInterface $mockDispatcher;
            private StubControllerRequestHelper $stubHelper;
            private RetryCleanupService $mockCleanupService;
            private User $mockUser;
            private bool $withCheckoutReturnServices;

            public function __construct(
                EventDispatcherInterface $dispatcher,
                StubControllerRequestHelper $helper,
                RetryCleanupService $cleanupService,
                User $user,
                bool $withCheckoutReturnServices
            ) {
                $this->mockDispatcher              = $dispatcher;
                $this->stubHelper                  = $helper;
                $this->mockCleanupService          = $cleanupService;
                $this->mockUser                    = $user;
                $this->withCheckoutReturnServices  = $withCheckoutReturnServices;
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
                // Don't exit in tests
            }

            protected function generateNewSessChallenge(): string
            {
                return 'new_uid_generated';
            }

            protected function resolveCheckoutReturnResponder(): CheckoutReturnResponder
            {
                $dispatcher = $this->mockDispatcher;
                $writer = new class implements SessionWriterInterface {
                    public function writeSessChallenge(string $orderId): void {}
                };
                return new CheckoutReturnResponder($dispatcher, $writer);
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
                if ($this->withCheckoutReturnServices
                    && $serviceName === ContractRepositoryInterface::class
                ) {
                    return new class implements ContractRepositoryInterface {
                        public function save(PaymentContractInterface $contract): void {}

                        public function findById(string $id): ?PaymentContractInterface
                        {
                            return new PaymentContract(
                                1,
                                // must match the shopper getUser() returns — the
                                // return path checks the contract is theirs
                                'user_123',
                                BasketSnapshot::fromArray([
                                    'items'      => [],
                                    'totalGross' => 1.0,
                                    'totalNet'   => 1.0,
                                    'totalVat'   => 0.0,
                                    'currency'   => 'EUR',
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

                        public function findStaleNotFinished(int $minutesOld): array { return []; }
                    };
                }
                if ($this->withCheckoutReturnServices
                    && $serviceName === StripeReturnResolver::class
                ) {
                    return new class extends StripeReturnResolver {
                        public function __construct() {}

                        public function resolve(
                            PaymentContractInterface $contract,
                            \OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface $context,
                        ): ReturnResolution {
                            return ReturnResolution::readyToCommit('pi_stub', 'pi_stub', 1.0, 'EUR');
                        }
                    };
                }
                throw new \RuntimeException("Unknown service: $serviceName");
            }
        };
    }
}
