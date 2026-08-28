<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\PaymentBase\Repository\VoucherReleaseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * OXID-side {@see ContractLinkedOrderUpdaterInterface} implementation.
 *
 * Uses `oxNew(Order::class)` for the model lookup so OXID's class extension
 * chain is honoured (the Stripe module extends Order).
 *
 * STRP-168: these methods used to set OXTRANSSTATUS alone. That left the order
 * un-stornoed with its vouchers still spent, and — because the cleanup command
 * only collects orders still at NOT_FINISHED — moving the status was precisely
 * what put the row beyond its reach, so nothing ever handed those vouchers
 * back. A mirrored ending now leaves the order in the same shape the cleanup
 * command would have: status, storno, vouchers released. Stock is deliberately
 * left alone, matching that command.
 *
 * @since Sprint 112
 */
class OxidContractLinkedOrderUpdater implements ContractLinkedOrderUpdaterInterface
{
    private const TRANSSTATUS_CANCELLED = 'CANCELLED';
    private const TRANSSTATUS_FAILED = 'FAILED';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly VoucherReleaseInterface $voucherReleaser,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function markCancelled(string $orderId): void
    {
        $this->endOrder($orderId, self::TRANSSTATUS_CANCELLED);
    }

    public function markFailed(string $orderId, string $reason): void
    {
        $this->endOrder($orderId, self::TRANSSTATUS_FAILED);
    }

    /**
     * Record the ending on the order, then hand the customer their vouchers
     * back.
     */
    private function endOrder(string $orderId, string $status): void
    {
        $order = $this->loadOrder($orderId);
        if ($order === null) {
            return;
        }

        $order->oxorder__oxtransstatus = new Field($status, Field::T_RAW);
        $order->oxorder__oxstorno = new Field(1, Field::T_RAW);
        $order->save();

        $this->releaseVouchers($orderId);
    }

    /**
     * Returning the vouchers is a courtesy on top of recording the ending, so
     * a failure here must not cost us the cancellation itself — that would put
     * the order back in the state this whole change exists to prevent.
     */
    private function releaseVouchers(string $orderId): void
    {
        try {
            $this->voucherReleaser->releaseVouchers($orderId);
        } catch (Throwable $e) {
            $this->logger->error('Could not release the vouchers of an ended order', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load an Order by ID.
     *
     * Protected so testable subclasses can inject a fake Order without
     * requiring a full OXID framework bootstrap (oxNew seam).
     */
    protected function loadOrder(string $orderId): ?Order
    {
        if ($orderId === '') {
            return null;
        }

        /** @var Order $order */
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            return null;
        }

        return $order;
    }
}
