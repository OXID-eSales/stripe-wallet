<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Response\CancellationResponse;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeCancelAuthorizationRequestHandler;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface;
use OxidEsales\PaymentBase\Service\RequestLogServiceInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeCancelAuthorizationRequestHandler.
 *
 * Sprint 11: Tests updated for refactored handler with CancelAuthorizationService injection.
 * Sprint 20: Tests updated to include ShopAdapterInterface mock.
 * Sprint 31: Tests updated to use CancellationResponse instead of CancellationResult.
 *
 * Note: These tests focus on the handler's interface and event handling.
 * Business logic tests are in CancelAuthorizationServiceTest.
 */
class StripeCancelAuthorizationRequestHandlerTest extends TestCase
{
    private CancelAuthorizationServiceInterface&MockObject $cancelService;
    private RequestLogServiceInterface&MockObject $requestLogService;
    private ShopAdapterInterface&MockObject $shopAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;

    protected function setUp(): void
    {
        $this->cancelService = $this->createMock(CancelAuthorizationServiceInterface::class);
        $this->requestLogService = $this->createMock(RequestLogServiceInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
    }

    private function createHandler(): StripeCancelAuthorizationRequestHandler
    {
        return new StripeCancelAuthorizationRequestHandler(
            $this->cancelService,
            $this->requestLogService,
            $this->shopAdapter,
            $this->contractRepository
        );
    }

    private function createSuccessResponse(string $paymentIntentId, string $status = 'canceled'): CancellationResponse
    {
        return CancellationResponse::success(
            providerPaymentId: $paymentIntentId,
            authorizationId: $paymentIntentId,
            status: $status,
            cancelledAt: new DateTimeImmutable()
        );
    }

    public function testHandlerIgnoresNonCancelAuthorizationEvent(): void
    {
        $handler = $this->createHandler();

        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        $this->cancelService->expects($this->never())->method('cancelAuthorization');

        $handler->handle($otherEvent);
    }

    public function testHandlerRejectsEmptyPaymentIntentId(): void
    {
        $context = new EventContext([
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $handler = $this->createHandler();

        $this->cancelService->expects($this->never())->method('cancelAuthorization');

        $handler->handle($event);

        $this->assertFalse($context->get('cancelSuccess'));
        $this->assertEquals('PaymentIntent ID is missing', $context->get('error'));
    }

    public function testHandlerRejectsEmptyStringPaymentIntentId(): void
    {
        $context = new EventContext([
            'paymentIntentId' => '',
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $handler = $this->createHandler();

        $this->cancelService->expects($this->never())->method('cancelAuthorization');

        $handler->handle($event);

        $this->assertFalse($context->get('cancelSuccess'));
        $this->assertEquals('PaymentIntent ID is missing', $context->get('error'));
    }

    public function testHandlerDelegatesToCancelService(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_cancel_123',
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->cancelService
            ->expects($this->once())
            ->method('cancelAuthorization')
            ->with('pi_test_cancel_123', 'requested_by_customer')
            ->willReturn($this->createSuccessResponse('pi_test_cancel_123'));

        $handler = $this->createHandler();

        $handler->handle($event);

        $this->assertTrue($context->get('cancelSuccess'));
        $this->assertEquals('pi_test_cancel_123', $context->get('cancelledPaymentIntentId'));
    }

    public function testHandlerSetsSuccessInContext(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_success',
            'cancellationReason' => 'duplicate',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->cancelService
            ->method('cancelAuthorization')
            ->willReturn($this->createSuccessResponse('pi_test_success'));

        $handler = $this->createHandler();

        $handler->handle($event);

        $this->assertTrue($context->get('cancelSuccess'));
        $this->assertEquals('canceled', $context->get('cancelledStatus'));
    }

    public function testHandlerSetsErrorOnServiceFailure(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_fail',
            'cancellationReason' => 'fraudulent',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->cancelService
            ->method('cancelAuthorization')
            ->willReturn(CancellationResponse::failure('Stripe API error: Cannot cancel'));

        $handler = $this->createHandler();

        $handler->handle($event);

        $this->assertFalse($context->get('cancelSuccess'));
        $this->assertStringContainsString('Cannot cancel', $context->get('error'));
    }

    public function testHandlerPassesCancellationReasonToService(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_reason',
            'cancellationReason' => 'abandoned',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->cancelService
            ->expects($this->once())
            ->method('cancelAuthorization')
            ->with('pi_test_reason', 'abandoned')
            ->willReturn($this->createSuccessResponse('pi_test_reason'));

        $handler = $this->createHandler();

        $handler->handle($event);
    }

    public function testReturnsCorrectHandledEventClass(): void
    {
        $this->assertEquals(
            StripeCancelAuthorizationRequestEvent::class,
            StripeCancelAuthorizationRequestHandler::getHandledEventClass()
        );
    }

    /**
     * STRP-AUTOCAP-REFUND Sprint 05 — opalreturns dispatches cancel-auth
     * with only `contractId` in the context (it is provider-agnostic and
     * does not know about "PaymentIntent" terminology). The handler must
     * resolve the PI ID from the contract's getProviderOrderId().
     */
    public function testResolvesPaymentIntentIdFromContractWhenEventContextHasNone(): void
    {
        $context = new EventContext([
            'contractId'         => 'contract-abc',
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_resolved_from_contract');
        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with('contract-abc')
            ->willReturn($contract);

        $this->cancelService
            ->expects($this->once())
            ->method('cancelAuthorization')
            ->with('pi_resolved_from_contract', 'requested_by_customer')
            ->willReturn($this->createSuccessResponse('pi_resolved_from_contract'));

        $this->createHandler()->handle($event);

        $this->assertTrue($context->get('cancelSuccess'));
        $this->assertEquals('pi_resolved_from_contract', $context->get('cancelledPaymentIntentId'));
    }

    public function testReportsErrorWhenContractIdInEventButContractNotFound(): void
    {
        $context = new EventContext([
            'contractId'         => 'contract-missing',
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with('contract-missing')
            ->willReturn(null);

        $this->cancelService->expects($this->never())->method('cancelAuthorization');

        $this->createHandler()->handle($event);

        $this->assertFalse($context->get('cancelSuccess'));
        $this->assertStringContainsString('Contract not found', (string) $context->get('error'));
    }

    public function testReportsErrorWhenContractFoundButHasNoProviderOrderId(): void
    {
        $context = new EventContext([
            'contractId'         => 'contract-no-pi',
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);
        $this->contractRepository->method('findById')->willReturn($contract);

        $this->cancelService->expects($this->never())->method('cancelAuthorization');

        $this->createHandler()->handle($event);

        $this->assertFalse($context->get('cancelSuccess'));
        $this->assertStringContainsString('No PaymentIntent ID', (string) $context->get('error'));
    }

    public function testEventPaymentIntentIdWinsOverContractWhenBothPresent(): void
    {
        // Admin Stripe-tab path: OrderActionDispatcher sets paymentIntentId
        // explicitly. Even if a contractId is also present, the explicit
        // value wins — handler does not need to consult the repository.
        $context = new EventContext([
            'paymentIntentId'    => 'pi_from_event',
            'contractId'         => 'contract-also-set',
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->contractRepository->expects($this->never())->method('findById');

        $this->cancelService
            ->expects($this->once())
            ->method('cancelAuthorization')
            ->with('pi_from_event', 'requested_by_customer')
            ->willReturn($this->createSuccessResponse('pi_from_event'));

        $this->createHandler()->handle($event);

        $this->assertTrue($context->get('cancelSuccess'));
    }

    public function testHandlerWorksWithNullCancellationReason(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_no_reason',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->cancelService
            ->expects($this->once())
            ->method('cancelAuthorization')
            ->with('pi_test_no_reason', null)
            ->willReturn($this->createSuccessResponse('pi_test_no_reason'));

        $handler = $this->createHandler();

        $handler->handle($event);

        $this->assertTrue($context->get('cancelSuccess'));
    }
}
