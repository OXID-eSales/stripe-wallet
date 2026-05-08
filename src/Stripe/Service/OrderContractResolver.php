<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;

/**
 * Resolves payment contracts for admin order views.
 *
 * Sprint 46: Extracted from OrderRefund controller to reduce ECC.
 *
 * @since 2.0.0
 */
final class OrderContractResolver
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository
    ) {
    }

    /**
     * Get contract ID from order.
     */
    public function getContractIdFromOrder(Order $order): ?string
    {
        $contract = $this->getContractForOrder($order);
        return $contract?->getId();
    }

    /**
     * Get contract for the given order.
     */
    public function getContractForOrder(Order $order): ?PaymentContractInterface
    {
        try {
            return $this->contractRepository->findByOrderId($order->getId());
        } catch (\Exception $e) {
            Registry::getLogger()->warning('Failed to load contract for order', [
                'orderId' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
