<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeCaptureRequestHandler;
use OxidEsales\Payments\Stripe\Service\CaptureServiceInterface;
use OxidEsales\PaymentComponent\Service\RequestLogServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for StripeCaptureRequestHandler.
 *
 * Sprint 9: Tests updated to use CaptureServiceInterface instead of StripeAdapterInterface.
 * Sprint 20: Tests updated to include ShopAdapterInterface mock.
 * Handler now delegates capture execution to CaptureService.
 */
class StripeCaptureRequestHandlerTest extends TestCase
{
    private CaptureServiceInterface&MockObject $captureService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private RequestLogServiceInterface&MockObject $requestLogService;
    private ShopAdapterInterface&MockObject $shopAdapter;
    private StripeCaptureRequestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureService = $this->createMock(CaptureServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->requestLogService = $this->createMock(RequestLogServiceInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');

        $this->handler = new StripeCaptureRequestHandler(
            $this->captureService,
            $this->contractRepository,
            $this->requestLogService,
            $this->shopAdapter,
            new NullLogger()
        );
    }

    public function testHandleReturnsHandledEventClass(): void
    {
        $this->assertEquals(
            StripeCaptureRequestEvent::class,
            StripeCaptureRequestHandler::getHandledEventClass()
        );
    }

    public function testHandleSkipsNonCaptureRequestEvents(): void
    {
        $otherEvent = new \stdClass();

        // Should not throw, just return
        $this->handler->handle($otherEvent);

        // No exception means success
        $this->assertTrue(true);
    }

    public function testHandleSetsErrorWhenPaymentIntentIdMissingInDirectMode(): void
    {
        // When no contractId is provided, handler falls back to direct capture mode
        // which requires paymentIntentId
        $context = new EventContext([]);
        $event = new StripeCaptureRequestEvent($context);

        $this->handler->handle($event);

        $this->assertFalse($context->get('captureSuccess'));
        $this->assertEquals('PaymentIntent ID is missing', $context->get('error'));
    }

    /**
     * Test that empty string PaymentIntent ID also triggers error.
     * This test catches mutations that change === '' to !== ''
     */
    public function testHandleSetsErrorWhenPaymentIntentIdIsEmptyString(): void
    {
        $context = new EventContext([
            'paymentIntentId' => '',  // Empty string, not null
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->handler->handle($event);

        $this->assertFalse($context->get('captureSuccess'));
        $this->assertEquals('PaymentIntent ID is missing', $context->get('error'));
    }

    /**
     * Test that valid PaymentIntent ID in direct mode does NOT set error.
     * This catches mutations that flip the validation logic.
     */
    public function testHandleDoesNotSetErrorWithValidPaymentIntentIdInDirectMode(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_valid_123',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        // Mock the capture service to return success
        $this->captureService
            ->method('processDirectCapture')
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_capture_123',
                amountCaptured: 100.0,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: new \DateTimeImmutable()
            ));

        $this->handler->handle($event);

        $this->assertTrue($context->get('captureSuccess'));
        $this->assertNull($context->get('error'));
    }

    public function testHandleSetsErrorWhenContractNotFound(): void
    {
        $context = new EventContext([
            'contractId' => 'nonexistent_contract',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->contractRepository
            ->method('findById')
            ->with('nonexistent_contract')
            ->willReturn(null);

        $this->handler->handle($event);

        $this->assertFalse($context->get('captureSuccess'));
        $error = $context->get('error');
        // Verify error message contains both the prefix and the contract ID
        // This catches mutations that remove the contract ID from the message
        $this->assertStringContainsString('Contract not found', $error);
        $this->assertStringContainsString('nonexistent_contract', $error);
    }

    public function testHandleSetsErrorWhenContractNotInAuthorizedState(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_pending',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $contract = $this->createContractInState(ContractState::pending());
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->handler->handle($event);

        $this->assertFalse($context->get('captureSuccess'));
        $this->assertStringContainsString('not in AUTHORIZED state', $context->get('error'));
    }

    public function testHandleSetsErrorWhenNoPaymentIntentFound(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_no_pi',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $contract = $this->createContractInState(ContractState::authorized());
        $contract->method('getProviderOrderId')->willReturn(null);
        $contract->method('getMetadata')->with('payment_intent_id')->willReturn(null);

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->handler->handle($event);

        $this->assertFalse($context->get('captureSuccess'));
        $this->assertStringContainsString('No PaymentIntent ID found', $context->get('error'));
    }

    public function testHandleSuccessfulCapture(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_authorized',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $contract = $this->createAuthorizedContractWithPaymentIntent('pi_test_123');

        $this->contractRepository
            ->method('findById')
            ->with('contract_authorized')
            ->willReturn($contract);

        $this->captureService
            ->expects($this->once())
            ->method('processCapture')
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_captured_123',
                amountCaptured: 99.99,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: new DateTimeImmutable()
            ));

        $this->handler->handle($event);

        $this->assertTrue($context->get('captureSuccess'));
        $this->assertEquals('ch_captured_123', $context->get('captureId'));
        $this->assertEquals(99.99, $context->get('capturedAmount'));
        $this->assertEquals('EUR', $context->get('captureCurrency'));
    }

    public function testHandleUsesPaymentIntentIdFromEvent(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_123',
            'paymentIntentId' => 'pi_from_event',
            'initiator' => 'webhook',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        // Contract has no provider order ID, but event has paymentIntentId
        $contract = $this->createContractInState(ContractState::authorized());
        $contract->method('getProviderOrderId')->willReturn(null);
        $contract->method('getMetadata')->with('payment_intent_id')->willReturn(null);

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Sprint 9: Now handler uses CaptureService which receives the contract
        $this->captureService
            ->expects($this->once())
            ->method('processCapture')
            ->with($contract)
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_captured',
                amountCaptured: 50.00,
                currency: 'USD',
                status: 'succeeded',
                capturedAt: new DateTimeImmutable()
            ));

        $this->handler->handle($event);

        $this->assertTrue($context->get('captureSuccess'));
    }

    public function testHandlePassesPartialAmount(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_partial',
            'amount' => 25.50,
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $contract = $this->createAuthorizedContractWithPaymentIntent('pi_partial');

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Sprint 9: Verify amount is passed to CaptureService
        $this->captureService
            ->expects($this->once())
            ->method('processCapture')
            ->with($contract, 25.50, $this->anything())
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_partial',
                amountCaptured: 25.50,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: new DateTimeImmutable()
            ));

        $this->handler->handle($event);

        $this->assertEquals(25.50, $context->get('capturedAmount'));
    }

    public function testHandleSetsErrorOnException(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_error',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $contract = $this->createAuthorizedContractWithPaymentIntent('pi_error');

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Sprint 9: CaptureService returns failure result instead of throwing
        $this->captureService
            ->method('processCapture')
            ->willReturn(CaptureResponse::failure('Stripe API error'));

        $this->handler->handle($event);

        $this->assertFalse($context->get('captureSuccess'));
        $this->assertStringContainsString('Stripe API error', $context->get('error'));
    }

    /**
     * Test that contract is set in context after successful lookup.
     * This catches mutation #13 that removes $context->set('contract', $contract).
     */
    public function testHandleSetsContractInContext(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_context_test',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $contract = $this->createAuthorizedContractWithPaymentIntent('pi_context');

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->captureService
            ->method('processCapture')
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_context',
                amountCaptured: 50.00,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: new DateTimeImmutable()
            ));

        $this->handler->handle($event);

        // Verify contract is set in context (catches mutation #13)
        $this->assertSame($contract, $context->get('contract'));
    }

    /**
     * Test that CaptureService.processCapture is called with the contract.
     * Sprint 9: CaptureService now handles captureAuthorization internally.
     */
    public function testHandleCallsCaptureServiceWithContract(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_auth_test',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $contract = $this->createMock(PaymentContract::class);
        $contract->method('getState')->willReturn(ContractState::authorized());
        $contract->method('getProviderOrderId')->willReturn('pi_auth_test');

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Sprint 9: Verify CaptureService is called with the contract
        // CaptureService internally calls captureAuthorization
        $this->captureService
            ->expects($this->once())
            ->method('processCapture')
            ->with($contract)
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_auth',
                amountCaptured: 100.00,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: new DateTimeImmutable()
            ));

        $this->handler->handle($event);
    }

    /**
     * Test that capturedAt is set in context on successful capture.
     * This catches mutation #32 that removes $context->set('capturedAt', ...).
     */
    public function testHandleSetsCapturedAtInContext(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_time_test',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $contract = $this->createAuthorizedContractWithPaymentIntent('pi_time');

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $capturedTime = new DateTimeImmutable('2025-01-15 10:30:00');

        $this->captureService
            ->method('processCapture')
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_time',
                amountCaptured: 75.00,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: $capturedTime
            ));

        $this->handler->handle($event);

        // Verify capturedAt is set in context (catches mutation #32)
        $this->assertEquals('2025-01-15 10:30:00', $context->get('capturedAt'));
    }

    /**
     * Test that reason is included in metadata when provided.
     * Sprint 9: Handler now passes metadata to CaptureService.
     */
    public function testHandlePassesReasonInMetadataWhenProvided(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_reason',
            'reason' => 'manual_capture_by_admin',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $contract = $this->createAuthorizedContractWithPaymentIntent('pi_reason');

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Verify that reason is included in the metadata passed to CaptureService
        $this->captureService
            ->expects($this->once())
            ->method('processCapture')
            ->with(
                $contract,
                $this->anything(),
                $this->callback(function ($metadata) {
                    return isset($metadata['reason'])
                        && $metadata['reason'] === 'manual_capture_by_admin';
                })
            )
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_reason',
                amountCaptured: 100.00,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: new DateTimeImmutable()
            ));

        $this->handler->handle($event);
    }

    /**
     * Test that reason is NOT included in metadata when null.
     * This catches mutations that incorrectly add reason when it should not be added.
     */
    public function testHandleDoesNotIncludeReasonWhenNull(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_no_reason',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $contract = $this->createAuthorizedContractWithPaymentIntent('pi_no_reason');

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Verify that reason is NOT in metadata when not provided
        $this->captureService
            ->expects($this->once())
            ->method('processCapture')
            ->with(
                $contract,
                $this->anything(),
                $this->callback(function ($metadata) {
                    return !isset($metadata['reason']);
                })
            )
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_no_reason',
                amountCaptured: 100.00,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: new DateTimeImmutable()
            ));

        $this->handler->handle($event);
    }

    /**
     * Test direct capture also sets capturedAt in context.
     * This catches mutation #50 that removes context.set('capturedAt') in direct capture.
     */
    public function testDirectCaptureSetsCapturedAtInContext(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_direct_time',
            'orderId' => 'order_123',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $capturedTime = new DateTimeImmutable('2025-01-15 11:00:00');

        $this->captureService
            ->method('processDirectCapture')
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_direct_time',
                amountCaptured: 50.00,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: $capturedTime
            ));

        $this->handler->handle($event);

        // Verify capturedAt is set in direct capture mode too
        $this->assertEquals('2025-01-15 11:00:00', $context->get('capturedAt'));
    }

    /**
     * Test direct capture sets captureId in context.
     * This catches mutation #47 that removes context.set('captureId').
     */
    public function testDirectCaptureSetsAllContextValues(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_direct_full',
            'orderId' => 'order_456',
            'initiator' => 'webhook',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->captureService
            ->method('processDirectCapture')
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_direct_full_123',
                amountCaptured: 199.99,
                currency: 'USD',
                status: 'succeeded',
                capturedAt: new DateTimeImmutable()
            ));

        $this->handler->handle($event);

        // Verify all context values are set (catches mutations #47-50)
        $this->assertTrue($context->get('captureSuccess'));
        $this->assertEquals('ch_direct_full_123', $context->get('captureId'));
        $this->assertEquals(199.99, $context->get('capturedAmount'));
        $this->assertEquals('USD', $context->get('captureCurrency'));
        $this->assertNotNull($context->get('capturedAt'));
    }

    /**
     * Test that PaymentIntent ID from contract metadata is used when providerOrderId is empty.
     * Sprint 9: Handler still resolves PaymentIntent ID, then delegates to CaptureService.
     */
    public function testHandleUsesPaymentIntentFromMetadataWhenProviderOrderIdEmpty(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_metadata',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        // Contract with empty providerOrderId but PaymentIntent in metadata
        $contract = $this->createMock(PaymentContract::class);
        $contract->method('getState')->willReturn(ContractState::authorized());
        $contract->method('getProviderOrderId')->willReturn('');  // Empty string
        $contract->method('getMetadata')->willReturnCallback(function (string $key) {
            if ($key === 'payment_intent_id') {
                return 'pi_from_metadata_123';
            }
            return null;
        });

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Sprint 9: CaptureService receives the contract and handles the PI lookup internally
        $this->captureService
            ->expects($this->once())
            ->method('processCapture')
            ->with($contract)
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_meta',
                amountCaptured: 100.00,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: new DateTimeImmutable()
            ));

        $this->handler->handle($event);

        $this->assertTrue($context->get('captureSuccess'));
    }

    /**
     * Test that empty string PaymentIntent in metadata triggers error.
     * This catches mutations that flip the !== '' check to === ''.
     */
    public function testHandleSetsErrorWhenMetadataPaymentIntentIsEmptyString(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_empty_meta',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        // Contract with no providerOrderId and empty metadata payment_intent_id
        $contract = $this->createMock(PaymentContract::class);
        $contract->method('getState')->willReturn(ContractState::authorized());
        $contract->method('getProviderOrderId')->willReturn(null);
        $contract->method('getMetadata')->willReturnCallback(function (string $key) {
            if ($key === 'payment_intent_id') {
                return '';  // Empty string - should fail validation
            }
            return null;
        });

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->captureService->expects($this->never())->method('processCapture');

        $this->handler->handle($event);

        $this->assertFalse($context->get('captureSuccess'));
        $this->assertStringContainsString('No PaymentIntent ID found', $context->get('error'));
    }

    /**
     * Test direct capture with reason in metadata.
     * Sprint 9: Verifies metadata is passed to CaptureService.processDirectCapture.
     */
    public function testDirectCapturePassesReasonInMetadata(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_direct_reason',
            'orderId' => 'order_789',
            'reason' => 'ship_order',
            'initiator' => 'admin',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        // Verify reason is included in direct capture metadata
        $this->captureService
            ->expects($this->once())
            ->method('processDirectCapture')
            ->with(
                'pi_direct_reason',
                $this->anything(),
                $this->callback(function ($metadata) {
                    return isset($metadata['reason'])
                        && $metadata['reason'] === 'ship_order';
                })
            )
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_test',
                captureId: 'ch_direct_reason',
                amountCaptured: 100.00,
                currency: 'EUR',
                status: 'succeeded',
                capturedAt: new DateTimeImmutable()
            ));

        $this->handler->handle($event);
    }

    // --- Helper methods ---

    /**
     * @return PaymentContract&MockObject
     */
    private function createContractInState(ContractState $state): PaymentContract&MockObject
    {
        $contract = $this->createMock(PaymentContract::class);
        $contract->method('getState')->willReturn($state);
        return $contract;
    }

    /**
     * @return PaymentContract&MockObject
     */
    private function createAuthorizedContractWithPaymentIntent(string $paymentIntentId): PaymentContract&MockObject
    {
        $contract = $this->createMock(PaymentContract::class);
        $contract->method('getState')->willReturn(ContractState::authorized());
        $contract->method('getProviderOrderId')->willReturn($paymentIntentId);
        $contract->method('getMetadata')->willReturnCallback(function (string $key) {
            return null; // No metadata values needed since providerOrderId is set
        });
        return $contract;
    }
}
