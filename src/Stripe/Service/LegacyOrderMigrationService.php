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
 * The contract is walked through the regular domain lifecycle to FULFILLED;
 * captured amount = the order total, refunded amount = the legacy module's
 * refund bookkeeping (order-level STRIPE*REFUNDED columns + per-article
 * STRIPEAMOUNTREFUNDED), read only when those columns exist.
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

    private const LEGACY_ORDER_REFUND_COLUMNS = [
        'STRIPEDELCOSTREFUNDED',
        'STRIPEPAYCOSTREFUNDED',
        'STRIPEWRAPCOSTREFUNDED',
        'STRIPEGIFTCARDREFUNDED',
        'STRIPEVOUCHERDISCOUNTREFUNDED',
        'STRIPEDISCOUNTREFUNDED',
    ];

    private const NEVER_PAID = '0000-00-00 00:00:00';

    /** @var array<string, list<string>> */
    private array $columnCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ShopAdapterInterface $shopAdapter
    ) {
    }

    public function migrate(bool $apply): LegacyOrderMigrationReport
    {
        $candidates = $this->findCandidates();
        $orderIds = array_map(static fn (array $row): string => self::text($row, 'OXID'), $candidates);
        if (!$apply) {
            return new LegacyOrderMigrationReport($orderIds, 0, [], []);
        }

        $migrated = 0;
        $skipped = [];
        $failed = [];
        foreach ($candidates as $row) {
            $label = $this->orderLabel($row);
            try {
                $skipReason = $this->migrateOne($row);
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

        return new LegacyOrderMigrationReport($orderIds, $migrated, $skipped, $failed);
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
     * @return string|null why no contract was created; null when one was
     */
    private function migrateOne(array $row): ?string
    {
        $transactionId = self::text($row, 'OXTRANSID');
        if (!str_starts_with($transactionId, 'pi_')) {
            return $transactionId === ''
                ? 'no transaction id on the order'
                : "transaction id '{$transactionId}' is not a PaymentIntent — the new module cannot manage it";
        }
        if (self::text($row, 'OXSTORNO') === '1') {
            return 'order is cancelled (storno)';
        }
        $paidAt = $this->paidAt($row);
        if ($paidAt === null) {
            return 'order was never paid — nothing for the new module to manage';
        }

        $orderId = self::text($row, 'OXID');
        $total = (float) ($row['OXTOTALORDERSUM'] ?? 0.0);
        if ($total <= 0.0) {
            return 'order total is zero — nothing to capture or refund';
        }
        $refunded = $this->legacyRefundedTotal($orderId);
        if ($refunded > $total) {
            return sprintf(
                'legacy refund bookkeeping (%.2f) exceeds the order total (%.2f) — resolve manually',
                $refunded,
                $total
            );
        }

        $contract = $this->buildContract($row, $orderId, $transactionId, $total, $refunded, $paidAt);
        $this->contractRepository->save($contract);

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildContract(
        array $row,
        string $orderId,
        string $transactionId,
        float $total,
        float $refunded,
        DateTimeImmutable $paidAt
    ): PaymentContract {
        $contract = new PaymentContract(
            $this->shopId(),
            self::text($row, 'OXUSERID'),
            $this->basketSnapshot($row, $orderId, $total)
        );

        // The regular lifecycle, replayed: the money already moved at Stripe,
        // the walk only brings the contract's state machine up to that fact.
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->setProvider(StripeDefinitions::PROVIDER, $transactionId);
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'source' => 'legacy-order-migration',
        ]);
        $contract->commitToOrder($orderId);
        $contract->fulfill();

        $contract->setCapturedAmount($total);
        $contract->setCapturedAt($paidAt);
        if ($refunded > 0.0) {
            $contract->addRefundedAmount($refunded);
            $contract->setRefundedAt($paidAt);
        }

        $contract->setMetadata('legacyMigration', [
            'migratedFrom' => 'oxid-esales/stripe-module',
            'legacyPaymentType' => self::text($row, 'OXPAYMENTTYPE'),
            'orderNumber' => self::text($row, 'OXORDERNR'),
        ]);

        return $contract;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function basketSnapshot(array $row, string $orderId, float $total): BasketSnapshot
    {
        $net = (float) ($row['OXTOTALNETSUM'] ?? 0.0);

        return BasketSnapshot::fromArray([
            'items' => $this->articleItems($orderId),
            'discounts' => [],
            'totalGross' => $total,
            'totalNet' => $net,
            'totalVat' => max(0.0, round($total - $net, 2)),
            'currency' => self::text($row, 'OXCURRENCY'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function articleItems(string $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative(self::ARTICLES_SQL, ['orderId' => $orderId]);

        return array_map(static fn (array $row): array => [
            'artnum' => self::text($row, 'OXARTNUM'),
            'title' => self::text($row, 'OXTITLE'),
            'quantity' => (int) ($row['OXAMOUNT'] ?? 0),
            'price' => (float) ($row['OXBPRICE'] ?? 0.0),
            'vat' => (float) ($row['OXVAT'] ?? 0.0),
        ], array_values($rows));
    }

    /**
     * The legacy module's refund bookkeeping, or 0.0 when its columns were
     * never installed (nothing was ever refunded through it then either).
     */
    private function legacyRefundedTotal(string $orderId): float
    {
        $orderColumns = $this->existingLegacyColumns('oxorder', self::LEGACY_ORDER_REFUND_COLUMNS);
        $total = 0.0;

        if ($orderColumns !== []) {
            $sql = 'SELECT ' . implode(' + ', $orderColumns) . ' FROM oxorder WHERE OXID = :orderId';
            $total += (float) $this->connection->fetchOne($sql, ['orderId' => $orderId]);
        }

        if ($this->existingLegacyColumns('oxorderarticles', ['STRIPEAMOUNTREFUNDED']) !== []) {
            $total += (float) $this->connection->fetchOne(
                'SELECT COALESCE(SUM(STRIPEAMOUNTREFUNDED), 0) FROM oxorderarticles WHERE OXORDERID = :orderId',
                ['orderId' => $orderId]
            );
        }

        return round($total, 2);
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string> the subset of $columns that exists on $table
     */
    private function existingLegacyColumns(string $table, array $columns): array
    {
        if (!isset($this->columnCache[$table])) {
            $rows = $this->connection->fetchFirstColumn(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME LIKE 'STRIPE%'",
                ['table' => $table]
            );
            $this->columnCache[$table] = array_map('strtoupper', array_map('strval', $rows));
        }

        return array_values(array_intersect($columns, $this->columnCache[$table]));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function paidAt(array $row): ?DateTimeImmutable
    {
        $paid = self::text($row, 'OXPAID');
        if ($paid === '' || $paid === self::NEVER_PAID) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $paid);

        return $date === false ? null : $date;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function orderLabel(array $row): string
    {
        $orderNumber = self::text($row, 'OXORDERNR');

        return $orderNumber !== '' && $orderNumber !== '0'
            ? 'order ' . $orderNumber
            : self::text($row, 'OXID');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
