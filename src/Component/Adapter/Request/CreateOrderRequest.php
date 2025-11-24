<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Adapter\Request;

/**
 * Request DTO for creating an order.
 *
 * Contains all necessary information to create and finalize an order
 * from the current basket/cart.
 *
 * @since 1.0.0
 */
final readonly class CreateOrderRequest
{
    /**
     * @param string $basketId Basket/Cart identifier
     * @param string $userId User/Customer identifier
     * @param string $paymentId Payment method identifier
     * @param string|null $paymentTransactionId External payment transaction ID (e.g., PaymentIntent ID)
     * @param string|null $orderRemark Customer's order remark/comment
     * @param array<string, mixed> $metadata Additional metadata to store with order
     */
    public function __construct(
        public string $basketId,
        public string $userId,
        public string $paymentId,
        public ?string $paymentTransactionId = null,
        public ?string $orderRemark = null,
        public array $metadata = []
    ) {
    }
}
