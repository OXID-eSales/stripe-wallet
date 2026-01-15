<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeRefundRequestHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\RefundServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for StripeRefundRequestHandler.
 *
 * Sprint 21: Tests updated for refactored handler with RefundService injection.
 *
 * Note: These tests focus on the handler's interface and event handling.
 * Business logic tests are in RefundServiceTest.
 * Full integration tests with OXID Order loading are in the Integration test suite.
 */
class StripeRefundRequestHandlerTest extends TestCase
{
    private RefundServiceInterface&MockObject $refundService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->refundService = $this->createMock(RefundServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createHandler(): StripeRefundRequestHandler
    {
        return new StripeRefundRequestHandler(
            $this->refundService,
            $this->contractRepository,
            $this->logger
        );
    }

    public function testReturnsCorrectHandledEventClass(): void
    {
        $this->assertEquals(
            StripeRefundRequestEvent::class,
            StripeRefundRequestHandler::getHandledEventClass()
        );
    }

    public function testHandlerIgnoresNonStripeRefundRequestEvent(): void
    {
        $handler = $this->createHandler();

        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        // RefundService should never be called for non-matching events
        $this->refundService->expects($this->never())->method('processFullRefund');
        $this->refundService->expects($this->never())->method('processPartialRefund');
        $this->refundService->expects($this->never())->method('processRefundByCharge');

        $handler->handle($otherEvent);
    }

    public function testSetsErrorWhenOrderIdMissing(): void
    {
        $handler = $this->createHandler();

        $context = new EventContext([]);
        $event = new StripeRefundRequestEvent($context);

        $handler->handle($event);

        $this->assertFalse($context->get('refundSuccess'));
        $this->assertEquals('Order ID is missing', $context->get('error'));
    }

    public function testFullRefundUsesNullAmount(): void
    {
        $context = new EventContext([
            'orderId' => 'test_order',
            'amount' => null,
            'reason' => 'duplicate',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertTrue($event->isFullRefund());
        $this->assertNull($event->getAmount());
    }

    public function testPartialRefundUsesSpecifiedAmount(): void
    {
        $context = new EventContext([
            'orderId' => 'test_order',
            'amount' => 25.50,
            'reason' => 'requested_by_customer',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertFalse($event->isFullRefund());
        $this->assertEquals(25.50, $event->getAmount());
    }

    public function testValidatesRefundReason(): void
    {
        // Valid reasons should be passed through
        $validReasons = ['duplicate', 'fraudulent', 'requested_by_customer'];

        foreach ($validReasons as $reason) {
            $context = new EventContext([
                'orderId' => 'test',
                'reason' => $reason,
            ]);
            $event = new StripeRefundRequestEvent($context);
            $this->assertEquals($reason, $event->getReason());
        }
    }

    public function testHandlerProcessesWebhookInitiator(): void
    {
        $context = new EventContext([
            'orderId' => 'test_order',
            'initiator' => 'webhook',
            'amount' => null,
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('webhook', $event->getInitiator());
    }

    public function testHandlerProcessesApiInitiator(): void
    {
        $context = new EventContext([
            'orderId' => 'test_order',
            'initiator' => 'api',
            'amount' => 50.00,
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('api', $event->getInitiator());
    }

    public function testHandlerProcessesMcpInitiator(): void
    {
        $context = new EventContext([
            'orderId' => 'test_order',
            'initiator' => 'mcp',
            'amount' => 75.00,
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('mcp', $event->getInitiator());
    }

    public function testEventContextHasCorrectDataKeys(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'contractId' => 'contract_456',
            'amount' => 100.00,
            'reason' => 'duplicate',
            'description' => 'Test refund description',
            'initiator' => 'admin',
            'chargeId' => 'ch_test_789',
            'paymentIntentId' => 'pi_test_abc',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('order_123', $event->getOrderId());
        $this->assertEquals('contract_456', $event->getContractId());
        $this->assertEquals(100.00, $event->getAmount());
        $this->assertEquals('duplicate', $event->getReason());
        $this->assertEquals('Test refund description', $event->getDescription());
        $this->assertEquals('admin', $event->getInitiator());
        $this->assertEquals('ch_test_789', $event->getChargeId());
        $this->assertEquals('pi_test_abc', $event->getPaymentIntentId());
    }

    public function testDefaultInitiatorIsAdmin(): void
    {
        $context = new EventContext([
            'orderId' => 'test_order',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('admin', $event->getInitiator());
    }

    public function testChargeIdCanBeProvidedDirectly(): void
    {
        $context = new EventContext([
            'orderId' => 'test_order',
            'chargeId' => 'ch_direct_123',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('ch_direct_123', $event->getChargeId());
    }

    public function testPaymentIntentIdCanBeProvidedDirectly(): void
    {
        $context = new EventContext([
            'orderId' => 'test_order',
            'paymentIntentId' => 'pi_direct_456',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('pi_direct_456', $event->getPaymentIntentId());
    }

    public function testAmountConversionToFloat(): void
    {
        // String amount should be converted
        $context = new EventContext([
            'orderId' => 'test_order',
            'amount' => '50.75',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertIsFloat($event->getAmount());
        $this->assertEquals(50.75, $event->getAmount());
    }

    public function testInvalidAmountReturnsNull(): void
    {
        $context = new EventContext([
            'orderId' => 'test_order',
            'amount' => 'invalid_amount',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->assertNull($event->getAmount());
    }
}
