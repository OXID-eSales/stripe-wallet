<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

/**
 * Immutable value object representing an assumption verification result
 *
 * This object encapsulates the result of a database field value check,
 * including performance metrics and comparison details.
 *
 * @example
 * ```php
 * $response = new AssumptionResponse(
 *     isMatch: true,
 *     queryTimeMs: 12.5,
 *     matchedRows: 1,
 *     actualValue: 'committed',
 *     expectedValue: 'committed'
 * );
 * ```
 */
final class AssumptionResponse
{
    /**
     * @param bool $isMatch Whether the actual value matches the expected value
     * @param float $queryTimeMs Query execution time in milliseconds
     * @param int $matchedRows Number of rows matched by the query
     * @param mixed $actualValue The actual value found in the database (optional)
     * @param mixed $expectedValue The expected value that was compared against (optional)
     *
     * @throws \InvalidArgumentException If parameters are invalid
     */
    public function __construct(
        private readonly bool $isMatch,
        private readonly float $queryTimeMs,
        private readonly int $matchedRows,
        private readonly mixed $actualValue = null,
        private readonly mixed $expectedValue = null
    ) {
        $this->validate();
    }

    /**
     * Check if the assumption matched
     */
    public function isMatch(): bool
    {
        return $this->isMatch;
    }

    /**
     * Get the query execution time in milliseconds
     */
    public function getQueryTimeMs(): float
    {
        return $this->queryTimeMs;
    }

    /**
     * Get the number of rows matched
     */
    public function getMatchedRows(): int
    {
        return $this->matchedRows;
    }

    /**
     * Get the actual value found in the database
     */
    public function getActualValue(): mixed
    {
        return $this->actualValue;
    }

    /**
     * Get the expected value that was compared against
     */
    public function getExpectedValue(): mixed
    {
        return $this->expectedValue;
    }

    /**
     * Convert response to array format for JSON API
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'assumption' => $this->isMatch,
            'query_time_ms' => $this->queryTimeMs,
            'matched_rows' => $this->matchedRows,
        ];

        // Only include actual/expected values if they are not null
        if ($this->actualValue !== null) {
            $data['actual_value'] = $this->actualValue;
        }

        if ($this->expectedValue !== null) {
            $data['expected_value'] = $this->expectedValue;
        }

        return $data;
    }

    /**
     * Convert response to JSON string
     *
     * @return string JSON representation
     * @throws \JsonException If JSON encoding fails
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Validate response parameters
     *
     * @throws \InvalidArgumentException If validation fails
     */
    private function validate(): void
    {
        if ($this->queryTimeMs < 0) {
            throw new \InvalidArgumentException('Query time cannot be negative');
        }

        if ($this->matchedRows < 0) {
            throw new \InvalidArgumentException('Matched rows cannot be negative');
        }
    }
}
