<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Strategy;

/**
 * LIKE pattern matching strategy (%like%, like%, %like)
 *
 * Case-insensitive pattern matching similar to SQL LIKE.
 * - %like% : Contains
 * - like%  : Starts with
 * - %like  : Ends with
 */
final class LikeOperator implements OperatorStrategyInterface
{
    private const SUPPORTED_OPERATORS = ['%like%', 'like%', '%like'];

    /**
     * @param string $operator The LIKE operator variant
     * @throws \InvalidArgumentException If operator is not supported
     */
    public function __construct(
        private readonly string $operator
    ) {
        if (!in_array($operator, self::SUPPORTED_OPERATORS, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Operator "%s" is not supported by %s',
                    $operator,
                    self::class
                )
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function compare(mixed $actualValue, mixed $expectedValue): bool
    {
        // Convert both to strings for pattern matching
        $actual = (string)$actualValue;
        $expected = (string)$expectedValue;

        // Case-insensitive comparison
        $actual = strtolower($actual);
        $expected = strtolower($expected);

        return match ($this->operator) {
            '%like%' => str_contains($actual, $expected),
            'like%' => str_starts_with($actual, $expected),
            '%like' => str_ends_with($actual, $expected),
            default => throw new \LogicException('Invalid operator state')
        };
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedOperators(): array
    {
        return self::SUPPORTED_OPERATORS;
    }

    /**
     * {@inheritdoc}
     */
    public function getOperator(): string
    {
        return $this->operator;
    }
}
