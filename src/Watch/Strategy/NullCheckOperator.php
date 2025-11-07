<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Strategy;

/**
 * NULL check strategy (IS NULL, IS NOT NULL)
 *
 * Performs strict null checks using === operator.
 * Note: Empty strings, zero, and false are NOT considered null.
 */
final class NullCheckOperator implements OperatorStrategyInterface
{
    private const SUPPORTED_OPERATORS = ['IS NULL', 'IS NOT NULL'];

    /**
     * @param string $operator The NULL check operator
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
        // For NULL checks, expectedValue is ignored
        return match ($this->operator) {
            'IS NULL' => $actualValue === null,
            'IS NOT NULL' => $actualValue !== null,
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
