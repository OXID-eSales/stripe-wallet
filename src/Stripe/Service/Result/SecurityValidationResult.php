<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Result;

use OxidEsales\PaymentBase\Contract\SecurityValidationResultInterface;

/**
 * Value object representing the result of a security validation check
 * for returning users from payment provider redirects.
 *
 * Used to assess fraud risk based on IP, timing, user agent, and other factors.
 *
 * Implements SecurityValidationResultInterface for LSP compliance -
 * can be substituted anywhere the interface is expected.
 */
final class SecurityValidationResult implements SecurityValidationResultInterface
{
    private int $score;

    /**
     * @param int $score Risk score (0-100, where 100 = fully trusted)
     * @param array<int, string> $warnings List of warning codes
     * @param bool $allowed Whether the return should be allowed
     */
    public function __construct(
        int $score,
        private readonly array $warnings,
        private readonly bool $allowed
    ) {
        // Bound score between 0 and 100
        $this->score = max(0, min(100, $score));
    }

    /**
     * Get the security score (0-100).
     * 100 = fully trusted, 0 = highly suspicious
     */
    public function getScore(): int
    {
        return $this->score;
    }

    /**
     * Get list of warning codes.
     *
     * @return array<int, string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if a specific warning exists.
     */
    public function hasWarning(string $warning): bool
    {
        return in_array($warning, $this->warnings, true);
    }

    /**
     * Get the number of warnings.
     */
    public function getWarningCount(): int
    {
        return count($this->warnings);
    }

    /**
     * Whether the return should be allowed based on security checks.
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /**
     * Convert to array for logging/serialization.
     *
     * @return array{score: int, warnings: array<int, string>, allowed: bool}
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'warnings' => $this->warnings,
            'allowed' => $this->allowed,
        ];
    }
}
