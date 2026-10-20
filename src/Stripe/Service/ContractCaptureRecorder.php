<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\Payments\Stripe\Core\ShopId;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Records what Stripe captured for a contract: the captured amount on the
 * contract and, at most, one `capture` audit row.
 *
 * Captures confirmed by webhook (or found later by the backfill command) never
 * pass through CaptureService, so nothing else sets the amount. A contract that
 * already carries an amount was recorded by someone else (an admin capture
 * writes its own row) and is left alone. A `capture` row that already exists is
 * never duplicated.
 *
 * Sprint 137 · Story 2: extracted from WebhookContractFulfillmentHandler once
 * the backfill command became the second caller.
 */
final class ContractCaptureRecorder
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly ShopAdapterInterface $shopAdapter,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param float|null $amountReceived What Stripe collected, in currency units; null when unknown
     */
    public function record(PaymentContractInterface $contract, ?float $amountReceived): void
    {
        if ($contract->getCapturedAmount() !== null) {
            return;
        }

        if ($amountReceived === null || $amountReceived <= 0.0) {
            // Sprint 133 · Story 9 (F9): never write a fictitious 0.00 capture
            // row; a missing row plus a loud log line is the honest outcome.
            $this->logger->error('Capture audit row skipped: contract has no captured amount', [
                'contract_id' => $contract->getId(),
                'provider_order_id' => $contract->getProviderOrderId(),
            ]);
            return;
        }

        $contract->setCapturedAmount($amountReceived);
        $this->contractRepository->save($contract);

        if ($this->hasCaptureRow($contract)) {
            return;
        }

        $this->writeCaptureRow($contract, $amountReceived);
    }

    private function hasCaptureRow(PaymentContractInterface $contract): bool
    {
        $contractId = $contract->getId();
        if ($contractId === null) {
            return false;
        }

        foreach ($this->transactionRepository->findByContractId($contractId) as $transaction) {
            if ($transaction->getType() === StripeDefinitions::TRANSACTION_TYPE_CAPTURE) {
                return true;
            }
        }

        return false;
    }

    private function writeCaptureRow(PaymentContractInterface $contract, float $amount): void
    {
        $transaction = new Transaction(
            id: StripeDefinitions::TRANSACTION_TYPE_CAPTURE . '_' . bin2hex(random_bytes(16)),
            shopId: ShopId::of($this->shopAdapter->getShopId(), 'capture audit row'),
            orderId: $contract->getOrderId() ?? '',
            contractId: $contract->getId(),
            provider: StripeDefinitions::PROVIDER,
            type: StripeDefinitions::TRANSACTION_TYPE_CAPTURE,
            status: StripeDefinitions::TRANSACTION_STATUS_COMPLETED,
            amount: $amount,
            currency: $contract->getCurrency()
        );
        $transaction->setProviderOrderId($contract->getProviderOrderId());

        $this->transactionRepository->save($transaction);
    }
}
