<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\Result\ReconciliationResult;

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
 * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment.
 *
 * @since Sprint 10
 */
class OxpaidReconciliationService implements OxpaidReconciliationServiceInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly ?FileLoggerInterface $fileLogger = null
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
     * @return array<int, array<string, mixed>>
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
     * Sprint 15: Contract is REQUIRED - no backward compatibility.
     *
     * @param string $orderId OXID of the order
     * @param string $paymentIntentId Stripe PaymentIntent ID
     * @return ReconciliationResult
     */
    public function reconcileOrder(string $orderId, string $paymentIntentId): ReconciliationResult
    {
        try {
            // Sprint 15: Contract is REQUIRED first - check before Stripe API
            $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);

            if ($contract === null) {
                $this->logReconciliation($orderId, $paymentIntentId, 'ERROR', false, 'No contract found');
                return new ReconciliationResult(
                    orderId: $orderId,
                    paymentIntentId: $paymentIntentId,
                    success: false,
                    action: 'no_contract',
                    reason: "No contract found for PaymentIntent: {$paymentIntentId}"
                );
            }

            // Only proceed with Stripe API if contract exists
            $adapter = $this->adapterFactory->createDefaultAdapter();
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

            // Sprint 18: Use ContractFulfillmentService for DRY fulfillment
            $contractUpdated = $this->contractFulfillmentService->fulfill($contract);

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
            $orderId = is_string($order['OXID'] ?? null) ? $order['OXID'] : '';
            $transId = is_string($order['OXTRANSID'] ?? null) ? $order['OXTRANSID'] : '';

            if ($dryRun) {
                $results[] = new ReconciliationResult(
                    orderId: $orderId,
                    paymentIntentId: $transId,
                    success: true,
                    action: 'dry_run',
                    reason: "Would check order #{$order['OXORDERNR']} from {$order['OXORDERDATE']}"
                );
                continue;
            }

            $results[] = $this->reconcileOrder($orderId, $transId);
        }

        return $results;
    }

    /**
     * Update OXPAID timestamp on order.
     */
    private function updateOrderPaidTimestamp(string $orderId, ?\DateTimeInterface $capturedAt): void
    {
        $timestamp = $capturedAt?->format('Y-m-d H:i:s') ?? date('Y-m-d H:i:s');

        $this->connection->update(
            'oxorder',
            ['OXPAID' => $timestamp],
            ['OXID' => $orderId]
        );
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
        if ($this->fileLogger === null) {
            return;
        }

        $contractFlag = $contractUpdated ? 'CONTRACT_FULFILLED' : 'NO_CONTRACT';
        $errorMsg = $error !== null ? " Error: {$error}" : '';

        $message = sprintf(
            '%s: Order=%s PaymentIntent=%s %s%s',
            $status,
            $orderId,
            $paymentIntentId,
            $contractFlag,
            $errorMsg
        );

        $this->fileLogger->log($message);
    }
}
