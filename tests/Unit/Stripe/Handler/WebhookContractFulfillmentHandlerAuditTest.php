<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler;
use OxidEsales\Payments\Stripe\Core\ShopId;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sprint 112 / G3: every provider-driven action (capture, refund, cancel, fail)
 * that successfully mutates contract state must also write an audit row to
 * oe_payments_transaction, mirroring what admin-UI flows do via
 * CaptureService/RefundService.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler::class)]
class WebhookContractFulfillmentHandlerAuditTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private ContractFulfillmentServiceInterface $contractFulfillmentService;
    private ContractLinkedOrderUpdaterInterface $orderUpdater;
    private RecordingTransactionRepository $transactionRepository;
    private ShopAdapterInterface $shopAdapter;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->orderUpdater = $this->createMock(ContractLinkedOrderUpdaterInterface::class);
        $this->transactionRepository = new RecordingTransactionRepository();
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');
    }

    public function testHandleChargeRefundedWritesRefundAuditRow(): void
    {
        $contract = $this->fulfilledContractWithOrderId('order-90');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handleChargeRefunded('pi_refund', 50.00);

        $this->assertTrue($result);
        $this->assertCount(1, $this->transactionRepository->saved);
        $tx = $this->transactionRepository->saved[0];
        $this->assertSame('refund', $tx->getType());
        $this->assertSame(50.00, $tx->getAmount());
        $this->assertSame($contract->getId(), $tx->getContractId());
        $this->assertSame('order-90', $tx->getOrderId());
    }

    public function testHandleChargeRefundedDoesNotWriteAuditRowOnStateGuardSkip(): void
    {
        $contract = $this->nonTerminalContractWithOrderId('order-89');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handleChargeRefunded('pi_committed', 50.00);

        $this->assertFalse($result);
        $this->assertSame([], $this->transactionRepository->saved);
    }

    public function testHandlePaymentCanceledWritesCancellationAuditRow(): void
    {
        $contract = $this->nonTerminalContractWithOrderId('order-91');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentCanceled('pi_cancel', 'requested_by_customer');

        $this->assertTrue($result);
        $this->assertCount(1, $this->transactionRepository->saved);
        $tx = $this->transactionRepository->saved[0];
        $this->assertSame('cancellation', $tx->getType());
        $this->assertSame($contract->getId(), $tx->getContractId());
    }

    public function testHandlePaymentFailedWritesFailureAuditRow(): void
    {
        $contract = $this->nonTerminalContractWithOrderId('order-fail');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentFailed('pi_fail', 'card_declined');

        $this->assertTrue($result);
        $this->assertCount(1, $this->transactionRepository->saved);
        $tx = $this->transactionRepository->saved[0];
        $this->assertSame('failure', $tx->getType());
    }

    /**
     * Sprint 133 · Story 9 (F9): this asserted only the row's *type*, so it
     * silently accepted the amount 0.00 that `getCapturedAmount() ?? 0.0`
     * produced for every capture whose amount was not recorded. It now asserts
     * the recorded amount too.
     */
    public function testHandlePaymentSucceededWritesCaptureAuditRowOnSuccessfulFulfillment(): void
    {
        // setCapturedAmount is state-guarded (rejects draft/not_finished), so
        // use a contract that has actually been through checkout.
        $contract = $this->fulfilledContractWithOrderId('order-cap');
        $this->contractFulfillmentService
            ->method('fulfillByProviderOrderId')
            ->willReturn(true);
        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentSucceeded('pi_capture', 42.50);

        $this->assertTrue($result);
        $this->assertCount(1, $this->transactionRepository->saved);
        $tx = $this->transactionRepository->saved[0];
        $this->assertSame('capture', $tx->getType());
        $this->assertSame(42.50, $tx->getAmount());
    }

    public function testHandlePaymentSucceededDoesNotWriteAZeroAuditRowWhenTheAmountIsUnknown(): void
    {
        // No captured amount on the contract: an audit trail carrying 0.00 for a
        // real capture is worse than a missing row plus a loud log line.
        $contract = $this->nonTerminalContractWithOrderId('order-noamount');
        $this->contractFulfillmentService
            ->method('fulfillByProviderOrderId')
            ->willReturn(true);
        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentSucceeded('pi_noamount');

        $this->assertTrue($result, 'Fulfillment itself still succeeded.');
        $this->assertSame([], $this->transactionRepository->saved);
    }

    public function testHandlePaymentSucceededDoesNotWriteAuditRowWhenFulfillmentReturnsFalseOrNull(): void
    {
        $this->contractFulfillmentService
            ->method('fulfillByProviderOrderId')
            ->willReturn(false);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentSucceeded('pi_already_fulfilled');

        $this->assertFalse($result);
        $this->assertSame([], $this->transactionRepository->saved);
    }

    public function testHandlePaymentSucceeded_WithAmountReceived_SetsCapturedAmountAndWritesOneCaptureRow(): void
    {
        $contract = $this->fulfilledContractWithOrderId('order-recv');
        $this->contractFulfillmentService->method('fulfillByProviderOrderId')->willReturn(true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->contractRepository->expects($this->once())->method('save')->with($contract);

        $result = $this->makeHandler()->handlePaymentSucceeded('pi_recv', 19.99);

        $this->assertTrue($result);
        $this->assertSame(19.99, $contract->getCapturedAmount());
        $this->assertCount(1, $this->transactionRepository->saved);
        $this->assertSame('capture', $this->transactionRepository->saved[0]->getType());
        $this->assertSame(19.99, $this->transactionRepository->saved[0]->getAmount());
    }

    public function testHandlePaymentSucceeded_WhenCaptureRowAlreadyExists_SetsCapturedAmountWithoutSecondRow(): void
    {
        $contract = $this->fulfilledContractWithOrderId('order-manual');
        $this->transactionRepository->existing[] = $this->transactionOfType($contract, 'capture');
        $this->contractFulfillmentService->method('fulfillByProviderOrderId')->willReturn(true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->makeHandler()->handlePaymentSucceeded('pi_manual', 30.00);

        $this->assertSame(30.00, $contract->getCapturedAmount());
        $this->assertSame([], $this->transactionRepository->saved);
    }

    public function testHandlePaymentSucceeded_WhenContractAlreadyHasCapturedAmount_KeepsItAndWritesNoRow(): void
    {
        $contract = $this->fulfilledContractWithOrderId('order-has-amount');
        $contract->setCapturedAmount(42.50);
        $this->contractFulfillmentService->method('fulfillByProviderOrderId')->willReturn(true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->contractRepository->expects($this->never())->method('save');

        $this->makeHandler()->handlePaymentSucceeded('pi_has_amount', 99.00);

        $this->assertSame(42.50, $contract->getCapturedAmount());
        $this->assertSame([], $this->transactionRepository->saved);
    }

    /**
     * @return iterable<string, array{0: ?float}>
     */
    public static function unusableAmounts(): iterable
    {
        yield 'missing' => [null];
        yield 'zero' => [0.0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableAmounts')]
    public function testHandlePaymentSucceeded_WithZeroOrMissingAmount_LogsErrorAndWritesNoRow(?float $amount): void
    {
        $contract = $this->fulfilledContractWithOrderId('order-zero');
        $this->contractFulfillmentService->method('fulfillByProviderOrderId')->willReturn(true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);
        $this->contractRepository->expects($this->never())->method('save');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('Capture audit row skipped'));

        $result = $this->makeHandler($logger)->handlePaymentSucceeded('pi_zero', $amount);

        $this->assertTrue($result);
        $this->assertNull($contract->getCapturedAmount());
        $this->assertSame([], $this->transactionRepository->saved);
    }

    public function testHandlePaymentSucceeded_WhenFulfillmentReturnsFalse_ChangesNothing(): void
    {
        $this->contractFulfillmentService->method('fulfillByProviderOrderId')->willReturn(false);
        $this->contractRepository->expects($this->never())->method('save');

        $result = $this->makeHandler()->handlePaymentSucceeded('pi_replay', 19.99);

        $this->assertFalse($result);
        $this->assertSame([], $this->transactionRepository->saved);
    }

    private function makeHandler(?LoggerInterface $logger = null): WebhookContractFulfillmentHandler
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
                $this->shopAdapter,
                $logger
            )
        );
    }

    private function transactionOfType(PaymentContract $contract, string $type): Transaction
    {
        return new Transaction(
            id: 'cap_manual',
            shopId: ShopId::of(1, 'test'),
            orderId: $contract->getOrderId() ?? '',
            contractId: $contract->getId(),
            provider: 'stripe',
            type: $type,
            status: 'completed',
            amount: 30.00,
            currency: 'EUR'
        );
    }

    private function fulfilledContractWithOrderId(string $orderId): PaymentContract
    {
        $contract = $this->nonTerminalContractWithOrderId($orderId);
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder($orderId);
        $contract->fulfill();
        return $contract;
    }

    private function nonTerminalContractWithOrderId(string $orderId): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        return $contract;
    }
}

/**
 * @internal
 */
final class RecordingTransactionRepository implements TransactionRepositoryInterface
{
    /** @var list<Transaction> */
    public array $saved = [];

    /** @var list<Transaction> rows that exist before the code under test runs */
    public array $existing = [];

    public function save(Transaction $transaction): void
    {
        $this->saved[] = $transaction;
    }

    public function findById(string $id): ?Transaction
    {
        return null;
    }

    public function findByOrderId(string $orderId): array
    {
        return [];
    }

    public function findByContractId(string $contractId): array
    {
        return array_values(array_filter(
            [...$this->existing, ...$this->saved],
            static fn (Transaction $transaction): bool => $transaction->getContractId() === $contractId
        ));
    }

    public function findByProviderTransactionId(string $transactionId): ?Transaction
    {
        return null;
    }

    public function findByTypeAndStatus(string $type, string $status): array
    {
        return [];
    }

    public function findChildTransactions(string $parentTransactionId): array
    {
        return [];
    }

    public function exists(string $id): bool
    {
        return false;
    }

    public function getTotalRefundedForContract(string $contractId): float
    {
        return 0.0;
    }

    public function logRefund(string $contractId, float $amount, string $refundId, string $reason): void
    {
        // unused in these tests
    }
}
