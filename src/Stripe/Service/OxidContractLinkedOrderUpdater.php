<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\LinkedOrderEndingInterface;

/**
 * OXID-side {@see ContractLinkedOrderUpdaterInterface} implementation.
 *
 * STRP-168: these methods used to set OXTRANSSTATUS alone, so the order kept its
 * vouchers and the cleanup command (NOT_FINISHED only) never saw it again.
 * OPC-175 / S4: the order ends the shared payment-base way
 * ({@see LinkedOrderEndingInterface}), as for Mollie: an unpaid order is
 * stornoed, its stock and its vouchers come back.
 *
 * @since Sprint 112
 */
class OxidContractLinkedOrderUpdater implements ContractLinkedOrderUpdaterInterface
{
    public function __construct(
        private readonly LinkedOrderEndingInterface $ending
    ) {
    }

    public function markCancelled(string $orderId): void
    {
        $this->ending->end($orderId, LinkedOrderEndingInterface::STATUS_CANCELLED);
    }

    public function markFailed(string $orderId, string $reason): void
    {
        $this->ending->end($orderId, LinkedOrderEndingInterface::STATUS_FAILED);
    }
}
