<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Core\ShopId;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\Result\BackfillReport;
use Throwable;

/**
 * Finds FULFILLED Stripe contracts that have no captured amount and records
 * what Stripe actually collected for each of them.
 *
 * Sprint 137 · Story 2: before Story 1 the payment_intent.succeeded webhook
 * fulfilled contracts without ever setting the captured amount, so refund
 * bounds and the capture audit row were missing for them.
 */
class CapturedAmountBackfillService implements CapturedAmountBackfillServiceInterface
{
    private const CANDIDATE_SQL = "SELECT OXID, OXPROVIDERORDERID
            FROM oe_payments_contract
            WHERE OXSTATE = 'fulfilled'
              AND OXCAPTUREDAMOUNT IS NULL
              AND OXPROVIDER = :provider
              AND OXSHOPID = :shopId
            ORDER BY OXCREATED ASC";

    private const PAYMENT_INTENT_SUCCEEDED = 'succeeded';

    public function __construct(
        private readonly Connection $connection,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ContractCaptureRecorder $captureRecorder,
        private readonly ShopAdapterInterface $shopAdapter
    ) {
    }

    public function backfill(bool $apply): BackfillReport
    {
        $candidates = $this->findCandidates();
        $contractIds = array_map(static fn (array $row): string => self::text($row, 'OXID'), $candidates);
        if (!$apply) {
            return new BackfillReport($contractIds, 0, [], []);
        }

        $recorded = 0;
        $skipped = [];
        $failed = [];
        foreach ($candidates as $row) {
            $contractId = self::text($row, 'OXID');
            try {
                $skipReason = $this->backfillOne($contractId, self::text($row, 'OXPROVIDERORDERID'));
            } catch (Throwable $error) {
                $failed[$contractId] = $error->getMessage();
                continue;
            }
            if ($skipReason === null) {
                $recorded++;
                continue;
            }
            $skipped[$contractId] = $skipReason;
        }

        return new BackfillReport($contractIds, $recorded, $skipped, $failed);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findCandidates(): array
    {
        return array_values($this->connection->fetchAllAssociative(self::CANDIDATE_SQL, [
            'provider' => StripeDefinitions::PROVIDER,
            'shopId' => ShopId::of($this->shopAdapter->getShopId(), 'captured amount backfill'),
        ]));
    }

    /**
     * @return string|null why nothing was recorded; null when the amount was recorded
     */
    private function backfillOne(string $contractId, string $paymentIntentId): ?string
    {
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return 'contract no longer exists';
        }

        $intent = $this->adapterFactory->getStripeAdapter()->retrievePaymentIntent($paymentIntentId);
        if ($intent->status !== self::PAYMENT_INTENT_SUCCEEDED) {
            return "PaymentIntent status is '{$intent->status}'";
        }
        if ($intent->amountReceived <= 0) {
            return 'PaymentIntent reports no amount received';
        }

        $this->captureRecorder->record(
            $contract,
            AmountConverter::toMajorUnits($intent->amountReceived, $intent->currency)
        );

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_string($value) ? $value : '';
    }
}
