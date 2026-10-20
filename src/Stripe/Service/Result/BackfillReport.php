<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Result;

/**
 * Outcome of one captured-amount backfill run.
 *
 * Sprint 137 · Story 2.
 */
final readonly class BackfillReport
{
    /**
     * @param list<string>          $candidateContractIds Contracts fulfilled without a captured amount
     * @param int                   $recorded             Contracts whose amount was recorded (0 in a dry run)
     * @param array<string, string> $skipped              contract id => why nothing was recorded
     * @param array<string, string> $failed               contract id => error that stopped the attempt
     */
    public function __construct(
        public array $candidateContractIds,
        public int $recorded,
        public array $skipped,
        public array $failed
    ) {
    }
}
