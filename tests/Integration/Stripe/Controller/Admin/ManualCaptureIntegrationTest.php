<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Controller\Admin;

use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentBase\Service\RequestLogServiceInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeCaptureRequestHandler;
use OxidEsales\Payments\Stripe\Service\CaptureService;
use OxidEsales\Payments\Stripe\Service\CaptureServiceInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration test for Sprint 82: Manual capture on committed contracts.
 *
 * Tests the full handler -> service -> contract state transition flow
 * when a contract is in COMMITTED state (manual capture order that skipped AUTHORIZED).
 *
 * @covers \OxidEsales\Payments\Stripe\EventSystem\Handler\StripeCaptureRequestHandler
 * @covers \OxidEsales\Payments\Stripe\Service\CaptureService
 * @group sprint-82
 * @group manual-capture
 */
final class ManualCaptureIntegrationTest extends TestCase
{
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractFulfillmentServiceInterface&MockObject $fulfillmentService;
    private RequestLogServiceInterface&MockObject $requestLogService;
    private ShopAdapterInterface&MockObject $shopAdapter;
    private BasketSnapshot $basketSnapshot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->fulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->requestLogService = $this->createMock(RequestLogServiceInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');

        $this->basketSnapshot = BasketSnapshot::fromArray([
            'items' => [['id' => 'item1', 'qty' => 1, 'price' => 130.39]],
            'discounts' => [],
            'totalGross' => 130.39,
            'totalNet' => 109.57,
            'totalVat' => 20.82,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Sprint 82: Full integration test — handler dispatches capture on COMMITTED contract,
     * CaptureService captures via Stripe API, contract transitions to FULFILLED.
     *
     * This tests the exact scenario from STRP-118: manual capture order with
     * contract in COMMITTED state (skipped AUTHORIZED during checkout return).
     */
    public function testFullCaptureFlowOnCommittedContract(): void
    {
        $paymentIntentId = 'pi_manual_capture_committed';

        // Given: A real PaymentContract in COMMITTED state
        $contract = $this->createCommittedContract($paymentIntentId);
        $this->assertEquals('committed', $contract->getStateValue());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Stripe API returns success
        $capturedAt = new \DateTimeImmutable();
        $this->stripeAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willReturn(CaptureResponse::success(
                providerPaymentId: $paymentIntentId,
                captureId: 'ch_captured_committed',
                amountCaptured: 130.39,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: $capturedAt
            ));

        // Sprint 82: ContractFulfillmentService must be called for COMMITTED contracts
        // It handles: fulfill() + save + dispatch ContractFulfilledEvent (→ OXPAID update)
        $this->fulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturnCallback(function (PaymentContract $c): bool {
                $c->fulfill(); // Simulate the service fulfilling the contract
                return true;
            });

        // Create the real CaptureService (not mocked)
        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $adapterFactory->method('getStripeAdapter')->willReturn($this->stripeAdapter);

        $captureService = new CaptureService(
            $adapterFactory,
            $this->contractRepository,
            $this->fulfillmentService,
            $this->createMock(\OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface::class),
            $this->shopAdapter,
            new NullLogger()
        );

        // Create handler with real CaptureService
        $handler = new StripeCaptureRequestHandler(
            $captureService,
            $this->contractRepository,
            $this->requestLogService,
            $this->shopAdapter,
            new NullLogger()
        );

        // When: Admin triggers capture
        $context = new EventContext([
            'contractId' => $contract->getId(),
            'initiator' => 'admin',
            'reason' => 'Order ready to ship',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $handler->handle($event);

        // Then: Capture succeeds
        $this->assertTrue(
            $context->get('captureSuccess'),
            'Capture must succeed on COMMITTED contract. Error: ' . ($context->get('error') ?? 'none')
        );
        $this->assertEquals('ch_captured_committed', $context->get('captureId'));
        $this->assertEquals(130.39, $context->get('capturedAmount'));

        // And: Contract transitions to FULFILLED
        $this->assertTrue(
            $contract->getState()->isFulfilled(),
            'Contract must transition from COMMITTED to FULFILLED after capture'
        );
    }

    /**
     * Sprint 82: Authorized contracts still work with the existing flow.
     * Regression test to ensure we didn't break the AUTHORIZED path.
     */
    public function testCaptureFlowOnAuthorizedContractStillWorks(): void
    {
        $paymentIntentId = 'pi_authorized_regression';

        // Given: A real PaymentContract in AUTHORIZED state
        $contract = $this->createAuthorizedContract($paymentIntentId);
        $this->assertEquals('authorized', $contract->getStateValue());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willReturn(CaptureResponse::success(
                providerPaymentId: $paymentIntentId,
                captureId: 'ch_authorized_regression',
                amountCaptured: 50.00,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: new \DateTimeImmutable()
            ));

        $this->contractRepository->expects($this->once())->method('save');

        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $adapterFactory->method('getStripeAdapter')->willReturn($this->stripeAdapter);

        $captureService = new CaptureService(
            $adapterFactory,
            $this->contractRepository,
            $this->fulfillmentService,
            $this->createMock(\OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface::class),
            $this->shopAdapter,
            new NullLogger()
        );

        $handler = new StripeCaptureRequestHandler(
            $captureService,
            $this->contractRepository,
            $this->requestLogService,
            $this->shopAdapter,
            new NullLogger()
        );

        $context = new EventContext([
            'contractId' => $contract->getId(),
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $handler->handle($event);

        // Then: Capture succeeds and contract transitions to READY_TO_COMMIT
        $this->assertTrue($context->get('captureSuccess'));
        $this->assertTrue(
            $contract->getState()->isReadyToCommit(),
            'Authorized contract must transition to READY_TO_COMMIT after capture'
        );
    }

    /**
     * Sprint 82: Capture must still reject non-capturable states.
     */
    public function testCaptureRejectsNonCapturableStates(): void
    {
        $contract = new PaymentContract(1, 'user1', $this->basketSnapshot);
        $contract->addCondition(new ContractCondition('payment_authorized'));
        $contract->transitionToNotFinished('order_test');
        $contract->transitionToPending();
        // State is PENDING — not capturable

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $adapterFactory->method('getStripeAdapter')->willReturn($this->stripeAdapter);

        $captureService = new CaptureService(
            $adapterFactory,
            $this->contractRepository,
            $this->fulfillmentService,
            $this->createMock(\OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface::class),
            $this->shopAdapter,
            new NullLogger()
        );

        $handler = new StripeCaptureRequestHandler(
            $captureService,
            $this->contractRepository,
            $this->requestLogService,
            $this->shopAdapter,
            new NullLogger()
        );

        $context = new EventContext([
            'contractId' => $contract->getId(),
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $handler->handle($event);

        $this->assertFalse($context->get('captureSuccess'));
        $this->assertStringContainsString('Cannot capture', $context->get('error'));
        $this->assertTrue($contract->getState()->isPending(), 'State must remain PENDING');
    }

    // --- Helpers ---

    private function createCommittedContract(string $paymentIntentId): PaymentContract
    {
        $contract = new PaymentContract(1, 'user1', $this->basketSnapshot);
        $contract->addCondition(new ContractCondition('payment_authorized'));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->fulfillCondition('payment_authorized', [
            'authorizationId' => $paymentIntentId,
            'providerOrderId' => $paymentIntentId,
        ]);
        // fulfillCondition auto-transitions to READY_TO_COMMIT when all conditions met
        $contract->commitToOrder('order_123');
        $contract->setProvider('stripe', $paymentIntentId);

        return $contract;
    }

    private function createAuthorizedContract(string $paymentIntentId): PaymentContract
    {
        $contract = new PaymentContract(1, 'user1', $this->basketSnapshot);
        $contract->addCondition(new ContractCondition('payment_authorized'));
        $contract->transitionToNotFinished('order_456');
        $contract->transitionToPending();
        $contract->authorize();
        $contract->setProvider('stripe', $paymentIntentId);

        return $contract;
    }
}
