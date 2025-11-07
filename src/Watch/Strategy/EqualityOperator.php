<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Strategy;

/**
 * Equality comparison strategy (==, !=)
 *
 * Uses loose comparison (==) which allows type coercion.
 * Example: '100' == 100 returns true
 */
final class EqualityOperator implements OperatorStrategyInterface
{
    private const SUPPORTED_OPERATORS = ['==', '!='];

    /**
     * @param string $operator The operator to use ('==' or '!=')
     * @throws \InvalidArgumentException If operator is not supported
     */
    public function __construct(
        private readonly string $operator
    ) {
        if (!in_array($operator, self::SUPPORTED_OPERATORS, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Operator "%s" is not supported by %s. Supported: %s',
                    $operator,
                    self::class,
                    implode(', ', self::SUPPORTED_OPERATORS)
                )
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function compare(mixed $actualValue, mixed $expectedValue): bool
    {
        return match ($this->operator) {
            '==' => $actualValue == $expectedValue,
            '!=' => $actualValue != $expectedValue,
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
