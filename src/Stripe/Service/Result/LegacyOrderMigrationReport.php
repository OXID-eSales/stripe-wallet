<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Result;

/**
 * Outcome of one legacy-order migration run.
 *
 * STRP-181.
 */
final readonly class LegacyOrderMigrationReport
{
    /**
     * @param list<string>          $candidateOrderIds Legacy Stripe orders without a payment contract
     * @param int                   $migrated          Orders a contract was created for (0 in a dry run)
     * @param array<string, string> $skipped           order label => why no contract was created
     * @param array<string, string> $failed            order label => error that stopped the attempt
     */
    public function __construct(
        public array $candidateOrderIds,
        public int $migrated,
        public array $skipped,
        public array $failed
    ) {
    }
}
