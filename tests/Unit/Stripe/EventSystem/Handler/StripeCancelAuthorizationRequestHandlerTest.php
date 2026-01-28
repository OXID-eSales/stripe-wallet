<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeCancelAuthorizationRequestHandler;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidEsales\PaymentComponent\Service\Result\CancellationResult;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface;
use OxidEsales\Payments\Stripe\Service\RequestLogServiceInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeCancelAuthorizationRequestHandler.
 *
 * Sprint 11: Tests updated for refactored handler with CancelAuthorizationService injection.
 * Sprint 20: Tests updated to include ShopAdapterInterface mock.
 *
 * Note: These tests focus on the handler's interface and event handling.
 * Business logic tests are in CancelAuthorizationServiceTest.
 */
class StripeCancelAuthorizationRequestHandlerTest extends TestCase
{
    private CancelAuthorizationServiceInterface&MockObject $cancelService;
    private RequestLogServiceInterface&MockObject $requestLogService;
    private ShopAdapterInterface&MockObject $shopAdapter;

    protected function setUp(): void
    {
        $this->cancelService = $this->createMock(CancelAuthorizationServiceInterface::class);
        $this->requestLogService = $this->createMock(RequestLogServiceInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');
    }

    private function createHandler(): StripeCancelAuthorizationRequestHandler
    {
        return new StripeCancelAuthorizationRequestHandler(
            $this->cancelService,
            $this->requestLogService,
            $this->shopAdapter
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
            ->willReturn(CancellationResult::success('pi_test_cancel_123', 'canceled'));

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
            ->willReturn(CancellationResult::success('pi_test_success', 'canceled'));

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
            ->willReturn(CancellationResult::failure('Stripe API error: Cannot cancel'));

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
            ->willReturn(CancellationResult::success('pi_test_reason', 'canceled'));

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
            ->willReturn(CancellationResult::success('pi_test_no_reason', 'canceled'));

        $handler = $this->createHandler();

        $handler->handle($event);

        $this->assertTrue($context->get('cancelSuccess'));
    }
}
