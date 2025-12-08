<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Event handler that updates OXPAID on orders when contracts are fulfilled.
 *
 * Sprint 14: Properly implements event-driven architecture.
 * Listens for ContractFulfilledEvent and updates the order's payment timestamp.
 *
 * @since 1.0.0
 */
class OrderPaymentCompletedHandler implements HandlerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return ContractFulfilledEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractFulfilledEvent) {
            return;
        }

        $contract = $event->getContract();
        $orderId = $contract->getOrderId();
        $providerOrderId = $contract->getProviderOrderId();

        if ($orderId === null || $orderId === '') {
            $this->logger->warning('ContractFulfilledEvent received without order ID', [
                'contract_id' => $contract->getId(),
            ]);
            return;
        }

        // Update OXPAID timestamp
        $this->updateOrderPaidTimestamp($orderId);

        // Also update OXTRANSSTATUS to OK and OXTRANSID if not already set
        $this->updateOrderTransactionFields($orderId, $providerOrderId);

        $this->logger->info('Order payment completed via ContractFulfilledEvent', [
            'order_id' => $orderId,
            'contract_id' => $contract->getId(),
            'provider_order_id' => $providerOrderId,
        ]);
    }

    /**
     * Update OXPAID timestamp on the order.
     */
    private function updateOrderPaidTimestamp(string $orderId): void
    {
        try {
            $sql = "UPDATE oxorder SET OXPAID = NOW() WHERE OXID = :orderId AND OXPAID = '0000-00-00 00:00:00'";
            $affected = $this->connection->executeStatement($sql, ['orderId' => $orderId]);

            if ($affected > 0) {
                $this->logger->debug('OXPAID timestamp updated', ['order_id' => $orderId]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to update OXPAID', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update transaction fields on the order.
     */
    private function updateOrderTransactionFields(string $orderId, ?string $providerOrderId): void
    {
        try {
            $sql = "UPDATE oxorder SET OXTRANSSTATUS = 'OK' WHERE OXID = :orderId";
            $this->connection->executeStatement($sql, ['orderId' => $orderId]);

            if ($providerOrderId !== null && $providerOrderId !== '') {
                $sql = "UPDATE oxorder SET OXTRANSID = :transId WHERE OXID = :orderId AND (OXTRANSID IS NULL OR OXTRANSID = '')";
                $this->connection->executeStatement($sql, [
                    'orderId' => $orderId,
                    'transId' => $providerOrderId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to update transaction fields', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
