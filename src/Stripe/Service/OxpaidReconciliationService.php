<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * OXPAID Reconciliation Service
 *
 * Checks orders with unpaid status (OXPAID = '0000-00-00 00:00:00') that have
 * a Stripe PaymentIntent ID (OXTRANSID starts with 'pi_') and verifies
 * their actual payment status via Stripe API.
 *
 * If the payment is actually succeeded in Stripe, updates:
 * - OXPAID timestamp on the order
 * - Contract state to FULFILLED (if contract exists)
 *
 * This handles cases where webhooks were missed or delayed.
 *
 * @since Sprint 10
 */
class OxpaidReconciliationService
{
    private const LOG_FILE = 'log/osc/stripe_reconciliation.log';

    public function __construct(
        private readonly Connection $connection,
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Find all orders that need reconciliation.
     *
     * Criteria:
     * - OXPAID = '0000-00-00 00:00:00' (not paid)
     * - OXTRANSID starts with 'pi_' (Stripe PaymentIntent)
     * - Order created within specified days (default 7)
     *
     * @param int $maxAgeDays Maximum age of orders to check (default 7 days)
     * @return array<array{OXID: string, OXTRANSID: string, OXORDERNR: int, OXORDERDATE: string}>
     */
    public function findUnpaidOrders(int $maxAgeDays = 7): array
    {
        $sql = "SELECT OXID, OXTRANSID, OXORDERNR, OXORDERDATE
                FROM oxorder
                WHERE OXPAID = '0000-00-00 00:00:00'
                  AND OXTRANSID LIKE 'pi_%'
                  AND OXORDERDATE >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY OXORDERDATE DESC";

        return $this->connection->fetchAllAssociative($sql, [$maxAgeDays]);
    }

    /**
     * Reconcile a single order.
     *
     * @param string $orderId OXID of the order
     * @param string $paymentIntentId Stripe PaymentIntent ID
     * @return ReconciliationResult
     */
    public function reconcileOrder(string $orderId, string $paymentIntentId): ReconciliationResult
    {
        try {
            // Get Stripe adapter
            $adapter = $this->adapterFactory->createDefaultAdapter();

            // Query Stripe for payment details
            $paymentDetails = $adapter->getPaymentDetails($paymentIntentId);

            // Check if payment is actually captured/succeeded
            if (!$paymentDetails->isCaptured) {
                return new ReconciliationResult(
                    orderId: $orderId,
                    paymentIntentId: $paymentIntentId,
                    success: false,
                    action: 'skipped',
                    reason: "Payment not captured. Status: {$paymentDetails->status}"
                );
            }

            // Payment is captured - update OXPAID
            $this->updateOrderPaidTimestamp($orderId, $paymentDetails->capturedAt);

            // Find and update related contract if exists
            $contractUpdated = $this->fulfillRelatedContract($paymentIntentId);

            $this->logReconciliation($orderId, $paymentIntentId, 'SUCCESS', $contractUpdated);

            return new ReconciliationResult(
                orderId: $orderId,
                paymentIntentId: $paymentIntentId,
                success: true,
                action: 'updated',
                reason: 'OXPAID updated from Stripe payment status',
                contractUpdated: $contractUpdated
            );
        } catch (\Throwable $e) {
            $this->logReconciliation($orderId, $paymentIntentId, 'ERROR', false, $e->getMessage());

            return new ReconciliationResult(
                orderId: $orderId,
                paymentIntentId: $paymentIntentId,
                success: false,
                action: 'error',
                reason: $e->getMessage()
            );
        }
    }

    /**
     * Reconcile all unpaid orders.
     *
     * @param int $maxAgeDays Maximum age of orders to check
     * @param bool $dryRun If true, don't make changes, just report
     * @return array<ReconciliationResult>
     */
    public function reconcileAll(int $maxAgeDays = 7, bool $dryRun = false): array
    {
        $unpaidOrders = $this->findUnpaidOrders($maxAgeDays);
        $results = [];

        foreach ($unpaidOrders as $order) {
            if ($dryRun) {
                $results[] = new ReconciliationResult(
                    orderId: $order['OXID'],
                    paymentIntentId: $order['OXTRANSID'],
                    success: true,
                    action: 'dry_run',
                    reason: "Would check order #{$order['OXORDERNR']} from {$order['OXORDERDATE']}"
                );
                continue;
            }

            $results[] = $this->reconcileOrder($order['OXID'], $order['OXTRANSID']);
        }

        return $results;
    }

    /**
     * Update OXPAID timestamp on order.
     */
    private function updateOrderPaidTimestamp(string $orderId, ?\DateTimeImmutable $capturedAt): void
    {
        $timestamp = $capturedAt?->format('Y-m-d H:i:s') ?? date('Y-m-d H:i:s');

        $this->connection->update(
            'oxorder',
            ['OXPAID' => $timestamp],
            ['OXID' => $orderId]
        );
    }

    /**
     * Find and fulfill the related contract.
     *
     * @return bool True if contract was found and updated
     */
    private function fulfillRelatedContract(string $paymentIntentId): bool
    {
        try {
            $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);

            if ($contract === null) {
                return false;
            }

            // Only fulfill if in committed state
            if ($contract->getState() !== 'committed') {
                return false;
            }

            // Fulfill the contract
            $contract->fulfill();
            $this->contractRepository->save($contract);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to fulfill contract during reconciliation', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Log reconciliation action to file.
     */
    private function logReconciliation(
        string $orderId,
        string $paymentIntentId,
        string $status,
        bool $contractUpdated,
        ?string $error = null
    ): void {
        try {
            $shopDir = \OxidEsales\Eshop\Core\Registry::getConfig()->getConfigParam('sShopDir');
            $logFile = rtrim($shopDir, '/') . '/' . self::LOG_FILE;

            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $timestamp = date('Y-m-d H:i:s');
            $contractFlag = $contractUpdated ? 'CONTRACT_FULFILLED' : 'NO_CONTRACT';
            $errorMsg = $error ? " Error: {$error}" : '';

            $logEntry = sprintf(
                "[%s] RECONCILE %s: Order=%s PaymentIntent=%s %s%s\n",
                $timestamp,
                $status,
                $orderId,
                $paymentIntentId,
                $contractFlag,
                $errorMsg
            );

            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Silent fail for logging
        }
    }
}
