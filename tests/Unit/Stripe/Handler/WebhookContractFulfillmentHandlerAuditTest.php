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
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 112 / G3: every provider-driven action (capture, refund, cancel, fail)
 * that successfully mutates contract state must also write an audit row to
 * oe_payments_transaction, mirroring what admin-UI flows do via
 * CaptureService/RefundService.
 *
 * @covers \OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler
 */
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

    public function testHandlePaymentSucceededWritesCaptureAuditRowOnSuccessfulFulfillment(): void
    {
        $contract = $this->nonTerminalContractWithOrderId('order-cap');
        $this->contractFulfillmentService
            ->method('fulfillByProviderOrderId')
            ->willReturn(true);
        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentSucceeded('pi_capture');

        $this->assertTrue($result);
        $this->assertCount(1, $this->transactionRepository->saved);
        $tx = $this->transactionRepository->saved[0];
        $this->assertSame('capture', $tx->getType());
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

    private function makeHandler(): WebhookContractFulfillmentHandler
    {
        return new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService,
            $this->orderUpdater,
            $this->transactionRepository,
            $this->shopAdapter
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
        return [];
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
