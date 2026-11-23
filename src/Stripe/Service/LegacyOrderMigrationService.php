<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Core\ShopId;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\Result\LegacyOrderMigrationReport;
use OxidEsales\Payments\Stripe\Service\Result\LegacyOrderVerdict;
use Throwable;

/**
 * Creates payment-base contracts for orders paid through the legacy
 * oxid-esales/stripe-module (payment ids `stripe*`, PaymentIntent id in
 * oxorder.OXTRANSID).
 *
 * A contract with OXPROVIDER = 'stripe' is all the new module's admin panel
 * needs to adopt an order ({@see \OxidEsales\Payments\Stripe\Admin\StripePaymentPanelProvider::supports()}
 * matches on the contract's provider), so the order's oxpaymenttype is left
 * untouched — history and reporting keep the original method id.
 *
 * {@see LegacyOrderAssessment} decides per order; the dry run asks it too, so it
 * lists what `--apply` will skip. The contract is walked through the regular
 * domain lifecycle to FULFILLED with the amounts Stripe holds for the payment.
 *
 * STRP-181.
 */
class LegacyOrderMigrationService implements LegacyOrderMigrationServiceInterface
{
    /**
     * Legacy orders: old-module payment id, no contract yet. The legacy ids
     * (`stripecreditcard`, `stripeideal`, …) all start with `stripe`; the new
     * module's ids start with `oe_payments_stripe_` and never match.
     */
    private const CANDIDATE_SQL = "SELECT o.OXID, o.OXUSERID, o.OXORDERNR, o.OXTRANSID, o.OXPAYMENTTYPE,
               o.OXTOTALORDERSUM, o.OXTOTALNETSUM, o.OXCURRENCY, o.OXPAID, o.OXSTORNO
            FROM oxorder o
            LEFT JOIN oe_payments_contract c ON c.OXORDERID = o.OXID
            WHERE o.OXPAYMENTTYPE LIKE 'stripe%'
              AND o.OXSHOPID = :shopId
              AND c.OXID IS NULL
            ORDER BY o.OXORDERDATE ASC";

    private const ARTICLES_SQL = "SELECT OXARTNUM, OXTITLE, OXAMOUNT, OXBPRICE, OXVAT
            FROM oxorderarticles WHERE OXORDERID = :orderId";

    public function __construct(
        private readonly Connection $connection,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly LegacyOrderAssessment $assessment
    ) {
    }

    public function migrate(bool $apply): LegacyOrderMigrationReport
    {
        $candidates = $this->findCandidates();
        $migrated = 0;
        $skipped = [];
        $failed = [];
        foreach ($candidates as $row) {
            $label = $this->orderLabel($row);
            try {
                $skipReason = $this->migrateOne($row, $apply);
            } catch (Throwable $error) {
                $failed[$label] = $error->getMessage();
                continue;
            }
            if ($skipReason === null) {
                $migrated++;
                continue;
            }
            $skipped[$label] = $skipReason;
        }

        return new LegacyOrderMigrationReport(
            array_map(static fn (array $row): string => LegacyOrderRow::text($row, 'OXID'), $candidates),
            $migrated,
            $skipped,
            $failed
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findCandidates(): array
    {
        return array_values($this->connection->fetchAllAssociative(self::CANDIDATE_SQL, [
            'shopId' => $this->shopId(),
        ]));
    }

    private function shopId(): int
    {
        return ShopId::of($this->shopAdapter->getShopId(), 'legacy order migration');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return string|null why the order gets no contract; null when it gets one (dry run: would get one)
     */
    private function migrateOne(array $row, bool $apply): ?string
    {
        $verdict = $this->assessment->assess($row);
        $paidAt = $verdict->paidAt;
        if ($verdict->skipReason !== null || $paidAt === null) {
            return $verdict->skipReason;
        }
        if ($apply) {
            $this->contractRepository->save($this->buildContract($row, $verdict, $paidAt));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildContract(array $row, LegacyOrderVerdict $verdict, DateTimeImmutable $paidAt): PaymentContract
    {
        $orderId = LegacyOrderRow::text($row, 'OXID');
        $contract = new PaymentContract(
            $this->shopId(),
            LegacyOrderRow::text($row, 'OXUSERID'),
            $this->basketSnapshot($row, $orderId)
        );

        $this->walkToFulfilled($contract, $orderId, LegacyOrderRow::text($row, 'OXTRANSID'));
        $this->bookMoney($contract, $verdict, $paidAt);

        // The key the regular checkout writes (EarlyOrderCreationHandler) and the module reads.
        $contract->setMetadata('order_number', LegacyOrderRow::text($row, 'OXORDERNR'));
        $contract->setMetadata('legacyMigration', [
            'migratedFrom' => 'oxid-esales/stripe-module',
            'legacyPaymentType' => LegacyOrderRow::text($row, 'OXPAYMENTTYPE'),
        ]);

        return $contract;
    }

    /**
     * The regular lifecycle, replayed: the money already moved at Stripe, the walk only brings the
     * contract's state machine up to that fact.
     */
    private function walkToFulfilled(PaymentContract $contract, string $orderId, string $transactionId): void
    {
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->setProvider(StripeDefinitions::PROVIDER, $transactionId);
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'source' => 'legacy-order-migration',
        ]);
        $contract->commitToOrder($orderId);
        $contract->fulfill();
    }

    private function bookMoney(PaymentContract $contract, LegacyOrderVerdict $verdict, DateTimeImmutable $paidAt): void
    {
        $contract->setCapturedAmount($verdict->capturedAmount);
        $contract->setCapturedAt($paidAt);
        if ($verdict->refundedAmount <= 0.0) {
            return;
        }

        // Stripe's charge carries no single refund date; the legacy data has none either.
        $contract->addRefundedAmount($verdict->refundedAmount);
        $contract->setRefundedAt($paidAt);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function basketSnapshot(array $row, string $orderId): BasketSnapshot
    {
        $total = LegacyOrderRow::number($row, 'OXTOTALORDERSUM');
        $net = LegacyOrderRow::number($row, 'OXTOTALNETSUM');

        return BasketSnapshot::fromArray([
            'items' => $this->articleItems($orderId),
            'discounts' => [],
            'totalGross' => $total,
            'totalNet' => $net,
            'totalVat' => max(0.0, round($total - $net, 2)),
            'currency' => LegacyOrderRow::text($row, 'OXCURRENCY'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function articleItems(string $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative(self::ARTICLES_SQL, ['orderId' => $orderId]);

        return array_map(static fn (array $row): array => [
            'artnum' => LegacyOrderRow::text($row, 'OXARTNUM'),
            'title' => LegacyOrderRow::text($row, 'OXTITLE'),
            'quantity' => LegacyOrderRow::number($row, 'OXAMOUNT'),
            'price' => LegacyOrderRow::number($row, 'OXBPRICE'),
            'vat' => LegacyOrderRow::number($row, 'OXVAT'),
        ], array_values($rows));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function orderLabel(array $row): string
    {
        $orderNumber = LegacyOrderRow::text($row, 'OXORDERNR');

        return $orderNumber !== '' && $orderNumber !== '0'
            ? 'order ' . $orderNumber
            : LegacyOrderRow::text($row, 'OXID');
    }
}
