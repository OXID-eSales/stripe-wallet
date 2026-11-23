<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Result;

use DateTimeImmutable;

/**
 * What the migration does with one legacy order: skip it for a reason, or create a contract with the
 * money Stripe holds for it.
 *
 * STRP-181 / sprint 144 (AW).
 */
final readonly class LegacyOrderVerdict
{
    private function __construct(
        public ?string $skipReason,
        public float $capturedAmount,
        public float $refundedAmount,
        public ?DateTimeImmutable $paidAt
    ) {
    }

    public static function skip(string $reason): self
    {
        return new self($reason, 0.0, 0.0, null);
    }

    public static function migrate(float $capturedAmount, float $refundedAmount, DateTimeImmutable $paidAt): self
    {
        return new self(null, $capturedAmount, $refundedAmount, $paidAt);
    }
}
