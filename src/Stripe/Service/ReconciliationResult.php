<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

/**
 * Result of a single order reconciliation attempt.
 *
 * @since Sprint 10
 */
final class ReconciliationResult
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $paymentIntentId,
        public readonly bool $success,
        public readonly string $action,
        public readonly string $reason,
        public readonly bool $contractUpdated = false
    ) {
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'payment_intent_id' => $this->paymentIntentId,
            'success' => $this->success,
            'action' => $this->action,
            'reason' => $this->reason,
            'contract_updated' => $this->contractUpdated,
        ];
    }
}
