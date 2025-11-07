<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Strategy;

/**
 * Strategy interface for comparison operators
 *
 * This interface follows the Strategy Pattern (Gang of Four) and Open/Closed Principle (SOLID).
 * New operators can be added by creating new strategy implementations without modifying existing code.
 *
 * @example
 * ```php
 * $strategy = new EqualityOperator('==');
 * $isMatch = $strategy->compare(actualValue: 'committed', expectedValue: 'committed');
 * ```
 */
interface OperatorStrategyInterface
{
    /**
     * Compare actual value against expected value using the operator
     *
     * @param mixed $actualValue The value retrieved from the database
     * @param mixed $expectedValue The value to compare against
     * @return bool True if comparison matches, false otherwise
     */
    public function compare(mixed $actualValue, mixed $expectedValue): bool;

    /**
     * Get the list of operators supported by this strategy
     *
     * @return array<string> Array of operator strings (e.g., ['==', '!='])
     */
    public function getSupportedOperators(): array;

    /**
     * Get the current operator being used
     *
     * @return string The operator string
     */
    public function getOperator(): string;
}
