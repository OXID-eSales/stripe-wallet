<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use LogicException;

final readonly class StripeWebhookResult implements StripeAdminOutcomeInterface
{
    private function __construct(
        private ?StripeWebhookStatus $status,
        private ?int $deleted,
        private ?StripeAdminRefusal $refusal,
    ) {
    }

    /**
     * @param int|null $deleted how many endpoints a clear-all removed; null for a registration
     */
    public static function done(StripeWebhookStatus $status, ?int $deleted): self
    {
        return new self($status, $deleted, null);
    }

    public static function refused(StripeAdminRefusal $refusal): self
    {
        return new self(null, null, $refusal);
    }

    public function refusal(): ?StripeAdminRefusal
    {
        return $this->refusal;
    }

    public function status(): StripeWebhookStatus
    {
        return $this->status ?? throw new LogicException('A refused operation has no status');
    }

    public function deleted(): ?int
    {
        return $this->deleted;
    }
}
