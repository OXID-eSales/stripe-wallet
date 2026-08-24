<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use DateTimeImmutable;
use DateTimeInterface;
use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeRefundRequestHandler;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Service\RefundServiceInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Service\RequestLogServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for StripeRefundRequestHandler.
 *
 * Sprint 21: Tests updated for refactored handler with RefundService injection.
 * Sprint 22: Removed OrderRefundUpdateService (dead code), full refund only.
 *
 * Note: These tests focus on the handler's interface and event handling.
 * Business logic tests are in RefundServiceTest.
 * Full integration tests with OXID Order loading are in the Integration test suite.
 */
class StripeRefundRequestHandlerTest extends TestCase
{
    private RefundServiceInterface&MockObject $refundService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private RequestLogServiceInterface&MockObject $requestLogService;
    private ShopAdapterInterface&MockObject $shopAdapter;
    private LoggerInterface&MockObject $logger;
    private FileLoggerInterface&MockObject $eventLogger;

    protected function setUp(): void
    {
        $this->refundService = $this->createMock(RefundServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->requestLogService = $this->createMock(RequestLogServiceInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventLogger = $this->createMock(FileLoggerInterface::class);
    }

    private function createHandler(): StripeRefundRequestHandler
    {
        return new StripeRefundRequestHandler(
            $this->refundService,
            $this->contractRepository,
            $this->requestLogService,
            $this->shopAdapter,
            $this->logger,
            $this->eventLogger
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
        $this->refundService->expects($this->never())->method('processRefund');
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

    // =========================================================================
    // Sprint 114.10a (A3): PI resolution via agnostic resolver — RED tests
    // These assert the handler resolves the PI id via ContractRepository, not oxNew(Order).
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-114-10a')]
    #[\PHPUnit\Framework\Attributes\Group('a3-agnostic-pi')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function processRefundResolvesPaymentIntentIdViaContractRepository(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_from_contract');
        $contract->method('getMetadata')->willReturn(null);

        $this->contractRepository
            ->method('findById')
            ->with('contract_abc')
            ->willReturn($contract);

        $this->refundService
            ->expects($this->once())
            ->method('processRefund')
            ->with(
                'order_xyz',
                'pi_from_contract',
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($this->createSuccessfulRefundResponse());

        $context = new EventContext([
            'orderId' => 'order_xyz',
            'contractId' => 'contract_abc',
            'reason' => 'requested_by_customer',
            'initiator' => 'admin',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->createHandler()->handle($event);

        $this->assertTrue($context->get('refundSuccess'));
    }

    #[\PHPUnit\Framework\Attributes\Group('sprint-114-10a')]
    #[\PHPUnit\Framework\Attributes\Group('a3-agnostic-pi')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function processRefundUsesExplicitPaymentIntentIdWhenProvided(): void
    {
        $this->refundService
            ->expects($this->once())
            ->method('processRefund')
            ->with(
                'order_xyz',
                'pi_explicit_123',
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($this->createSuccessfulRefundResponse());

        $context = new EventContext([
            'orderId' => 'order_xyz',
            'paymentIntentId' => 'pi_explicit_123',
            'reason' => 'duplicate',
            'initiator' => 'admin',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->createHandler()->handle($event);

        $this->assertTrue($context->get('refundSuccess'));
    }

    #[\PHPUnit\Framework\Attributes\Group('sprint-114-10a')]
    #[\PHPUnit\Framework\Attributes\Group('a3-agnostic-pi')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function processRefundSetsErrorWhenPaymentIntentIdCannotBeResolved(): void
    {
        $context = new EventContext([
            'orderId' => 'order_xyz',
            // No paymentIntentId, no contractId => resolver throws
            'reason' => 'duplicate',
            'initiator' => 'admin',
        ]);
        $event = new StripeRefundRequestEvent($context);

        $this->createHandler()->handle($event);

        $this->assertFalse($context->get('refundSuccess'));
        $this->assertStringContainsString('PaymentIntent ID is missing', (string) $context->get('error'));
    }

    private function createSuccessfulRefundResponse(): RefundResponse
    {
        return RefundResponse::success(
            providerPaymentId: 'pi_test',
            refundId: 're_test',
            amountRefunded: 100.0,
            currency: 'EUR',
            status: 'succeeded',
            refundedAt: new DateTimeImmutable()
        );
    }

    /**
     * Arranges a refund that reaches the success path with an explicit PaymentIntent id.
     */
    private function arrangeSuccessfulRefund(): EventContext
    {
        $this->refundService
            ->method('processRefund')
            ->willReturn($this->createSuccessfulRefundResponse());

        return new EventContext([
            'orderId' => 'order_xyz',
            'paymentIntentId' => 'pi_explicit_123',
            'reason' => 'requested_by_customer',
            'initiator' => 'admin',
        ]);
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

    // =========================================================================
    // Sprint 44: updateContractState tests (Liskov-safe, interface-based)
    // =========================================================================

    private function createTestableHandler(): TestableStripeRefundRequestHandler
    {
        return new TestableStripeRefundRequestHandler(
            $this->refundService,
            $this->contractRepository,
            $this->requestLogService,
            $this->shopAdapter,
            $this->logger
        );
    }

    public function testSuccessfulRefundUpdatesContractRefundTracking(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $contract->method('getAmount')->willReturn(99.99);
        $contract->method('getRefundedAmount')->willReturn(null);

        $contract->expects($this->once())
            ->method('addRefundedAmount')
            ->with(99.99);

        $contract->expects($this->once())
            ->method('setRefundedAt')
            ->with($this->isInstanceOf(DateTimeInterface::class));

        $this->contractRepository->method('findById')
            ->with('contract_123')
            ->willReturn($contract);

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        $context = new EventContext([
            'orderId' => 'order_abc',
            'contractId' => 'contract_123',
            'amount' => null,
        ]);
        $event = new StripeRefundRequestEvent($context);

        $handler = $this->createTestableHandler();
        $handler->callUpdateContractState($event);
    }

    public function testSkipsContractUpdateWhenContractIdIsNull(): void
    {
        $this->contractRepository->expects($this->never())->method('findById');

        $context = new EventContext([
            'orderId' => 'order_abc',
            'amount' => null,
        ]);
        $event = new StripeRefundRequestEvent($context);

        $handler = $this->createTestableHandler();
        $handler->callUpdateContractState($event);
    }

    public function testPartialRefundUpdatesContractWithPartialAmount(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $contract->method('getAmount')->willReturn(99.99);

        $contract->expects($this->once())
            ->method('addRefundedAmount')
            ->with(25.50);

        $contract->expects($this->once())
            ->method('setRefundedAt')
            ->with($this->isInstanceOf(DateTimeInterface::class));

        $this->contractRepository->method('findById')
            ->with('contract_123')
            ->willReturn($contract);

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        $context = new EventContext([
            'orderId' => 'order_abc',
            'contractId' => 'contract_123',
            'amount' => 25.50,
        ]);
        $event = new StripeRefundRequestEvent($context);

        $handler = $this->createTestableHandler();
        $handler->callUpdateContractState($event);
    }

    public function testSkipsContractUpdateWhenContractNotFound(): void
    {
        $this->contractRepository->method('findById')
            ->with('contract_missing')
            ->willReturn(null);

        $this->contractRepository->expects($this->never())->method('save');

        $context = new EventContext([
            'orderId' => 'order_abc',
            'contractId' => 'contract_missing',
            'amount' => null,
        ]);
        $event = new StripeRefundRequestEvent($context);

        $handler = $this->createTestableHandler();
        $handler->callUpdateContractState($event);
    }

    public function testSkipsRefundAmountWhenContractNotInFulfilledState(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::committed());
        $contract->method('getRefundedAmount')->willReturn(null);

        $contract->expects($this->never())->method('addRefundedAmount');

        $this->contractRepository->method('findById')
            ->with('contract_123')
            ->willReturn($contract);

        $this->contractRepository->expects($this->never())->method('save');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Cannot record refund on contract: not in FULFILLED state',
                $this->callback(fn(array $ctx) => $ctx['contractId'] === 'contract_123' && $ctx['state'] === 'committed')
            );

        $context = new EventContext([
            'orderId' => 'order_abc',
            'contractId' => 'contract_123',
            'amount' => null,
        ]);
        $event = new StripeRefundRequestEvent($context);

        $handler = $this->createTestableHandler();
        $handler->callUpdateContractState($event);
    }

    // ---------------------------------------------------------------------
    // Sprint 135 (S1) — mutation-hardening.
    // Each test below kills a mutant that previously escaped: the call it
    // covers could be deleted from the handler with the suite still green.
    // See docs/oe_payments_docs/daniil_dev_log/20260824/reports/01-mutation-testing-baseline.md
    // ---------------------------------------------------------------------

    /**
     * Kills MethodCallRemoval at StripeRefundRequestHandler:227-230.
     *
     * Every success key the caller reads must reach the context. Previously only
     * `refundSuccess` and `refundId` were asserted, so the three remaining
     * `$context->set()` calls could be deleted undetected.
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function successfulRefundPublishesEveryResultKeyToContext(): void
    {
        $context = $this->arrangeSuccessfulRefund();

        $this->createHandler()->handle(new StripeRefundRequestEvent($context));

        $this->assertTrue($context->get('refundSuccess'));
        $this->assertSame('re_test', $context->get('refundId'));
        $this->assertSame(100.0, $context->get('refundedAmount'));
        $this->assertSame('succeeded', $context->get('refundStatus'));
        $this->assertSame('EUR', $context->get('refundCurrency'));
    }

    /**
     * Kills MethodCallRemoval at :211 and ArrayItem/ArrayItemRemoval at :213-217.
     *
     * The audit row is the money trail: asserting the call happened is not enough,
     * the payload has to carry the provider's actual refund id, status, amount and
     * currency, and the caller's order id and shop id.
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function successfulRefundIsWrittenToTheRequestLogWithTheProviderPayload(): void
    {
        $context = $this->arrangeSuccessfulRefund();

        $this->requestLogService
            ->expects($this->once())
            ->method('logRequest')
            ->with(
                'refund',
                ['refund_id' => 're_test'],
                [
                    'status' => 'succeeded',
                    'amount' => 100.0,
                    'currency' => 'EUR',
                ],
                'order_xyz',
                1
            );

        $this->createHandler()->handle(new StripeRefundRequestEvent($context));
    }

    /**
     * Kills MethodCallRemoval at :182 (`logRefundRequest`) for the failure path and
     * proves the audit trail is not written when the provider rejects the refund.
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function failedRefundIsNotWrittenToTheRequestLogAsSuccess(): void
    {
        $this->refundService
            ->method('processRefund')
            ->willReturn(RefundResponse::failure(
                errorMessage: 'card_declined',
                errorCode: 'card_error'
            ));

        $this->requestLogService
            ->expects($this->never())
            ->method('logRequest');

        $context = new EventContext([
            'orderId' => 'order_xyz',
            'paymentIntentId' => 'pi_explicit_123',
            'reason' => 'requested_by_customer',
        ]);

        $this->createHandler()->handle(new StripeRefundRequestEvent($context));

        $this->assertFalse($context->get('refundSuccess'));
        $this->assertSame('card_declined', $context->get('error'));
    }

    /**
     * Kills MethodCallRemoval at :62, :65, :72 and :78.
     *
     * The handler's phase breadcrumbs are the only forensic record when a refund
     * goes wrong in production. Deleting any of them left the suite green.
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function refundPhasesAreWrittenToTheEventLog(): void
    {
        $context = $this->arrangeSuccessfulRefund();
        $messages = [];

        $this->eventLogger
            ->method('log')
            ->willReturnCallback(static function (string $message) use (&$messages): void {
                $messages[] = $message;
            });

        $this->createHandler()->handle(new StripeRefundRequestEvent($context));

        $this->assertContains('StripeRefundRequestHandler::handle() START', $messages);
        $this->assertContains('StripeRefundRequestHandler: Processing refund', $messages);
        $this->assertContains('StripeRefundRequestHandler::handle() END - SUCCESS', $messages);
    }

    /**
     * Kills MethodCallRemoval at :65 specifically: the wrong-event-type guard must
     * announce itself, otherwise a misrouted event is silently discarded.
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function wrongEventTypeIsAnnouncedToTheEventLogAndSkipped(): void
    {
        $messages = [];
        $this->eventLogger
            ->method('log')
            ->willReturnCallback(static function (string $message) use (&$messages): void {
                $messages[] = $message;
            });

        $this->refundService->expects($this->never())->method('processRefund');

        $this->createHandler()->handle(new \stdClass());

        $this->assertContains('StripeRefundRequestHandler: Wrong event type, skipping', $messages);
    }

    /**
     * Kills MethodCallRemoval at :232 and ArrayItem at :233-234.
     *
     * The success log line carries the refund id, amount and order id used for
     * support triage; each field must be asserted, not just the call.
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function successfulRefundIsLoggedWithRefundAmountAndOrderId(): void
    {
        $context = $this->arrangeSuccessfulRefund();

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                'Refund processed successfully',
                [
                    'refund_id' => 're_test',
                    'amount' => 100.0,
                    'order_id' => 'order_xyz',
                ]
            );

        $this->createHandler()->handle(new StripeRefundRequestEvent($context));
    }
}

/**
 * Testable subclass exposing updateContractState for isolated unit testing.
 *
 * The production method is protected (OCP), enabling this test-only
 * subclass without modifying the production class's public API.
 *
 * Sprint 44: Added to enable testing without OXID's oxNew() dependency.
 */
class TestableStripeRefundRequestHandler extends StripeRefundRequestHandler
{
    public function callUpdateContractState(StripeRefundRequestEvent $event): void
    {
        $this->updateContractState($event);
    }

}
