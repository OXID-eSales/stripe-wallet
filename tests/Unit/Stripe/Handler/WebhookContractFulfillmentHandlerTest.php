<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedgerInterface;
use OxidEsales\PaymentBase\Refund\RefundBooking;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefund;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\TestCase;

/**
 * TDD Tests for WebhookContractFulfillmentHandler
 *
 * Sprint 6: Contract-Aware Webhook Processing
 * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
 *
 * This handler bridges Stripe webhooks to the contract state machine by:
 * 1. Looking up contract by providerOrderId (payment intent ID)
 * 2. Delegating fulfillment to ContractFulfillmentService
 * 3. Handling capture/refund amounts
 */
#[\PHPUnit\Framework\Attributes\Group('sprint-6')]
#[\PHPUnit\Framework\Attributes\Group('sprint-18')]
#[\PHPUnit\Framework\Attributes\Group('contract-aware')]
class WebhookContractFulfillmentHandlerTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private ContractFulfillmentServiceInterface $contractFulfillmentService;
    private ContractLinkedOrderUpdaterInterface $orderUpdater;
    private TransactionRepositoryInterface $transactionRepository;
    private ShopAdapterInterface $shopAdapter;
    private ProviderRefundLedgerInterface&\PHPUnit\Framework\MockObject\MockObject $refundLedger;

    protected function setUp(): void
    {
        $this->refundLedger = $this->createMock(ProviderRefundLedgerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->orderUpdater = $this->createMock(ContractLinkedOrderUpdaterInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');
    }

    private function makeHandler(): WebhookContractFulfillmentHandler
    {
        return new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService,
            $this->orderUpdater,
            $this->transactionRepository,
            $this->shopAdapter,
            new ContractCaptureRecorder(
                $this->contractRepository,
                $this->transactionRepository,
                $this->shopAdapter
            ),
            $this->refundLedger
        );
    }

    // =========================================================================
    // Test 1: Handler finds contract by provider order ID
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-6')]
    #[\PHPUnit\Framework\Attributes\Group('sprint-18')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerFindsContractByProviderOrderId(): void
    {
        $providerOrderId = 'pi_test_123';

        // Sprint 18: Delegates to ContractFulfillmentService
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(true);

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Test 2: Handler skips already fulfilled contracts (idempotency)
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-6')]
    #[\PHPUnit\Framework\Attributes\Group('sprint-18')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerSkipsAlreadyFulfilledContract(): void
    {
        $providerOrderId = 'pi_already_fulfilled';

        // Sprint 18: Delegates to ContractFulfillmentService which returns false
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(false);

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Test 3: Handler validates contract is COMMITTED before fulfillment
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-6')]
    #[\PHPUnit\Framework\Attributes\Group('sprint-18')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerRejectsNonCommittedContract(): void
    {
        $providerOrderId = 'pi_pending_contract';

        // Sprint 18: Delegates to ContractFulfillmentService which returns false for non-committed
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(false);

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Test 4: Handler transitions contract to FULFILLED
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-6')]
    #[\PHPUnit\Framework\Attributes\Group('sprint-18')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerTransitionsContractToFulfilled(): void
    {
        $providerOrderId = 'pi_to_fulfill';

        // Sprint 18: Delegates to ContractFulfillmentService
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(true);

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Test 5: Handler dispatches ContractFulfilledEvent
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-6')]
    #[\PHPUnit\Framework\Attributes\Group('sprint-18')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerDelegatesEventDispatchToService(): void
    {
        $providerOrderId = 'pi_dispatch_event';

        // Sprint 18: Event dispatch is handled by ContractFulfillmentService
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(true);

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Test 6: Handler returns order ID from contract
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-6')]
    #[\PHPUnit\Framework\Attributes\Group('sprint-18')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsResultFromService(): void
    {
        $providerOrderId = 'pi_get_order_id';

        // Sprint 18: Return value comes from service
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(true);

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Test 7: Handler handles contract not found gracefully
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-6')]
    #[\PHPUnit\Framework\Attributes\Group('sprint-18')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsNullWhenContractNotFound(): void
    {
        $providerOrderId = 'pi_no_contract';

        // Sprint 18: Service returns null when contract not found
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(null);

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertNull($result);
    }

    // =========================================================================
    // Sprint 112 / G5: charge.captured handler removed — payment_intent.succeeded
    // always wins the race. Tests for it were dead-pinning behavior that the
    // production code path no longer reaches.
    // =========================================================================
    // =========================================================================
    // Test 10: Handler handles charge.refunded event
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-6')]
    #[\PHPUnit\Framework\Attributes\Group('sprint-18')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerHandlesChargeRefundedEvent(): void
    {
        $providerOrderId = 'pi_refunded';

        // For refund, contract must be FULFILLED (already captured)
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $contract->method('getId')->willReturn('contract_refund');
        $contract->method('getOrderId')->willReturn('order_refund');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $this->refundLedger->expects($this->once())->method('book')
            ->with('contract_refund', 're_50', 50.0, 'EUR', 'webhook')
            ->willReturn(RefundBooking::booked(50.0));

        $result = $this->makeHandler()->handleChargeRefunded($providerOrderId, [new ChargeRefund('re_50', 50.0, 'EUR')]);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function everyListedRefundIsBookedByItsOwnIdAndAmount(): void
    {
        $contract = $this->fulfilledContractMock('contract_two');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $booked = [];
        $this->refundLedger->method('book')->willReturnCallback(
            static function (string $contractId, string $refundId, float $amount, string $currency, string $source) use (&$booked): RefundBooking {
                $booked[] = [$contractId, $refundId, $amount, $currency, $source];

                return RefundBooking::booked($amount);
            }
        );

        $this->makeHandler()->handleChargeRefunded('pi_two', [
            new ChargeRefund('re_a', 10.0, 'EUR'),
            new ChargeRefund('re_b', 10.0, 'EUR'),
        ]);

        $this->assertSame([
            ['contract_two', 're_a', 10.0, 'EUR', 'webhook'],
            ['contract_two', 're_b', 10.0, 'EUR', 'webhook'],
        ], $booked);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aRefundThatMovedNoMoneyIsNotBooked(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn($this->fulfilledContractMock('contract_zero'));
        $this->refundLedger->expects($this->never())->method('book');

        $result = $this->makeHandler()->handleChargeRefunded('pi_zero', [new ChargeRefund('re_zero', 0.0, 'EUR')]);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aRefundOnAContractThatIsNotFulfilledIsSkippedNotBooked(): void
    {
        $this->contractRepository->method('findByProviderOrderId')
            ->willReturn($this->createCommittedContractMock('pi_committed', 'order-1'));
        $this->refundLedger->expects($this->never())->method('book');

        $result = $this->makeHandler()->handleChargeRefunded('pi_committed', [new ChargeRefund('re_1', 5.0, 'EUR')]);

        $this->assertFalse($result);
    }

    private function fulfilledContractMock(string $id): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $contract->method('getId')->willReturn($id);

        return $contract;
    }

    // =========================================================================
    // Test 10: Handler handles payment_intent.payment_failed event
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-6')]
    #[\PHPUnit\Framework\Attributes\Group('sprint-18')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerHandlesPaymentFailed(): void
    {
        $providerOrderId = 'pi_failed';
        $failureReason = 'card_declined';

        // Use concrete PaymentContract since fail() is not on interface
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_failed');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['authorizationId' => 'auth_123']);
        $contract->setProvider('stripe', $providerOrderId);
        $contract->commitToOrder('order_failed');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save');

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentFailed($providerOrderId, $failureReason);

        $this->assertTrue($result);
        // Contract should be in FAILED state after processing
        $this->assertTrue($contract->getState()->isFailed());
    }

    // =========================================================================
    // Test 11: Handler handles payment_intent.canceled event
    // Sprint 1: Bug fix - contract cancellation was not being handled
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-1')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Group('bug-fix')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerHandlesPaymentCanceled(): void
    {
        $providerOrderId = 'pi_canceled';
        $cancellationReason = 'user_requested';

        // Use concrete PaymentContract since cancel() is not on interface
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_canceled');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $providerOrderId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save');

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentCanceled($providerOrderId, $cancellationReason);

        $this->assertTrue($result);
        // Contract should be in CANCELLED state after processing
        $this->assertTrue($contract->getState()->isCancelled());
    }

    #[\PHPUnit\Framework\Attributes\Group('sprint-1')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Group('bug-fix')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsNullWhenContractNotFoundOnCancel(): void
    {
        $providerOrderId = 'pi_no_contract_cancel';

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(null);

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentCanceled($providerOrderId, 'user_requested');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Group('sprint-1')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Group('bug-fix')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsFalseForAlreadyTerminalContractOnCancel(): void
    {
        $providerOrderId = 'pi_already_failed';
        $cancellationReason = 'user_requested';

        // Create already failed contract (terminal state)
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_failed');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $providerOrderId);
        $contract->fail('previous_failure');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        $handler = $this->makeHandler();

        $result = $handler->handlePaymentCanceled($providerOrderId, $cancellationReason);

        // Should return false (already in terminal state - idempotent)
        $this->assertFalse($result);
    }

    // =========================================================================
    // Test 12: Handler handles checkout.session.expired event
    // Sprint 1: Bug fix - expired sessions were not updating contract state
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-1')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Group('bug-fix')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerHandlesSessionExpired(): void
    {
        $contractId = 'contract_expired_123';

        // Use concrete PaymentContract since expire() is not on all interfaces
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_expired');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'pi_expired_test');

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save');

        $handler = $this->makeHandler();

        $result = $handler->handleSessionExpired($contractId);

        $this->assertTrue($result);
        // Contract should be in EXPIRED state after processing
        $this->assertTrue($contract->getState()->isExpired());
    }

    #[\PHPUnit\Framework\Attributes\Group('sprint-1')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Group('bug-fix')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsNullWhenContractNotFoundOnExpired(): void
    {
        $contractId = 'contract_not_found';

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn(null);

        $handler = $this->makeHandler();

        $result = $handler->handleSessionExpired($contractId);

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Group('sprint-1')]
    #[\PHPUnit\Framework\Attributes\Group('contract-aware')]
    #[\PHPUnit\Framework\Attributes\Group('bug-fix')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsFalseForAlreadyTerminalContractOnExpired(): void
    {
        $contractId = 'contract_already_fulfilled';

        // Create already fulfilled contract (terminal state)
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_fulfilled');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'pi_fulfilled_test');
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['authId' => 'auth_123']);
        $contract->commitToOrder('order_fulfilled');
        $contract->fulfill();

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $handler = $this->makeHandler();

        $result = $handler->handleSessionExpired($contractId);

        // Should return false (already in terminal state - idempotent)
        $this->assertFalse($result);
    }

    // =========================================================================
    // Sprint 114.10a (L3): Parity tests — behaviour must work via interface, not downcast
    // RED: these fail with the current instanceof-guarded code.
    // GREEN: they pass after removing the 4 instanceof guards.
    // =========================================================================
    #[\PHPUnit\Framework\Attributes\Group('sprint-114-10a')]
    #[\PHPUnit\Framework\Attributes\Group('l3-downcast')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlePaymentFailedWorksWithPaymentContractInterface(): void
    {
        $providerOrderId = 'pi_fail_interface';

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::pending());
        $contract->method('getId')->willReturn('c_fail_iface');
        $contract->method('getOrderId')->willReturn('o_fail_iface');
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->expects($this->once())->method('fail')->with('card_declined');

        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->contractRepository->expects($this->once())->method('save');

        $result = $this->makeHandler()->handlePaymentFailed($providerOrderId, 'card_declined');

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Group('sprint-114-10a')]
    #[\PHPUnit\Framework\Attributes\Group('l3-downcast')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlePaymentCanceledWorksWithPaymentContractInterface(): void
    {
        $providerOrderId = 'pi_cancel_interface';

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::pending());
        $contract->method('getId')->willReturn('c_cancel_iface');
        $contract->method('getOrderId')->willReturn('o_cancel_iface');
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->expects($this->once())->method('cancel')->with('user_requested');

        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->contractRepository->expects($this->once())->method('save');

        $result = $this->makeHandler()->handlePaymentCanceled($providerOrderId, 'user_requested');

        $this->assertTrue($result);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a mock PaymentContractInterface in COMMITTED state
     */
    private function createCommittedContractMock(
        string $providerOrderId,
        string $orderId
    ): PaymentContractInterface {
        $contract = $this->createMock(PaymentContractInterface::class);

        $contract->method('getState')->willReturn(ContractState::committed());
        $contract->method('getId')->willReturn('contract_' . md5($providerOrderId));
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getStateValue')->willReturn('committed');

        return $contract;
    }
}
