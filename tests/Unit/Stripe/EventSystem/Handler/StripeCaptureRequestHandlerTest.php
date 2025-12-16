<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use DateTimeImmutable;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\CaptureResponse;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCaptureRequestHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for StripeCaptureRequestHandler.
 */
class StripeCaptureRequestHandlerTest extends TestCase
{
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private StripeCaptureRequestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);

        $this->handler = new StripeCaptureRequestHandler(
            $this->stripeAdapter,
            $this->contractRepository,
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
        $this->assertStringContainsString('Contract not found', $context->get('error'));
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

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $captureResponse = new CaptureResponse(
            providerPaymentId: 'pi_test_123',
            captureId: 'ch_captured_123',
            amountCaptured: 99.99,
            currency: 'EUR',
            status: 'succeeded',
            capturedAt: new DateTimeImmutable()
        );

        $this->stripeAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willReturn($captureResponse);

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

        $captureResponse = new CaptureResponse(
            providerPaymentId: 'pi_from_event',
            captureId: 'ch_captured',
            amountCaptured: 50.00,
            currency: 'USD',
            status: 'succeeded',
            capturedAt: new DateTimeImmutable()
        );

        $this->stripeAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function ($request) {
                return $request->providerPaymentId === 'pi_from_event';
            }))
            ->willReturn($captureResponse);

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

        $captureResponse = new CaptureResponse(
            providerPaymentId: 'pi_partial',
            captureId: 'ch_partial',
            amountCaptured: 25.50,
            currency: 'EUR',
            status: 'succeeded',
            capturedAt: new DateTimeImmutable()
        );

        $this->stripeAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function ($request) {
                return $request->amount === 25.50;
            }))
            ->willReturn($captureResponse);

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

        $this->stripeAdapter
            ->method('capturePayment')
            ->willThrowException(new \RuntimeException('Stripe API error'));

        $this->handler->handle($event);

        $this->assertFalse($context->get('captureSuccess'));
        $this->assertStringContainsString('Stripe API error', $context->get('error'));
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
