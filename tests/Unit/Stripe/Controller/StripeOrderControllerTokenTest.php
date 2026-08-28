<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
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
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\StripeOrderController::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-67a')]
#[\PHPUnit\Framework\Attributes\Group('security')]
final class StripeOrderControllerTokenTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    protected function getServiceFromContainer(string $serviceName): object
    {
        if ($serviceName === \OxidEsales\PaymentBase\Repository\ContractRepositoryInterface::class) {
            return new class implements \OxidEsales\PaymentBase\Repository\ContractRepositoryInterface {
                public function save(
                    \OxidEsales\PaymentBase\Contract\PaymentContractInterface $contract
                ): void {
                }
                public function findById(
                    string $id
                ): ?\OxidEsales\PaymentBase\Contract\PaymentContractInterface {
                    return new \OxidEsales\PaymentBase\Contract\PaymentContract(
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
        if ($serviceName === \OxidEsales\Payments\Stripe\Service\Return\StripeReturnResolver::class) {
            return new class extends \OxidEsales\Payments\Stripe\Service\Return\StripeReturnResolver {
                public function __construct()
                {
                }
                public function resolve(
                    \OxidEsales\PaymentBase\Contract\PaymentContractInterface $contract,
                    \OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface $context,
                ): \OxidEsales\PaymentBase\Return\ReturnResolution {
                    return \OxidEsales\PaymentBase\Return\ReturnResolution::readyToCommit(
                        'pi_stub',
                        'pi_stub',
                        1.0,
                        'EUR',
                    );
                }
            };
        }
        if ($serviceName === \OxidEsales\PaymentBase\Controller\CheckoutReturnResponder::class) {
            // Reuse the overridden getEventDispatcher() so the test's
            // eventDispatched flag still flips when the responder dispatches.
            $writer = new class implements \OxidEsales\PaymentBase\Controller\SessionWriterInterface {
                public function writeSessChallenge(string $orderId): void {}
            };
            return new \OxidEsales\PaymentBase\Controller\CheckoutReturnResponder(
                $this->getEventDispatcher(),
                $writer,
            );
        }
        throw new \RuntimeException("Unknown service: $serviceName");
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
