<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Strategy;

/**
 * Factory for creating operator strategy instances
 *
 * This factory implements lazy loading and supports extensibility
 * by allowing registration of custom operator strategies.
 */
final class OperatorStrategyFactory
{
    /** @var array<string, class-string<OperatorStrategyInterface>> */
    private array $operatorMap = [];

    /**
     * Initialize with default operator mappings
     */
    public function __construct()
    {
        $this->registerDefaultOperators();
    }

    /**
     * Create an operator strategy for the given operator
     *
     * @param string $operator The operator string (e.g., '==', '>', '%like%')
     * @return OperatorStrategyInterface The strategy instance
     * @throws \InvalidArgumentException If operator is not registered
     */
    public function create(string $operator): OperatorStrategyInterface
    {
        if (!isset($this->operatorMap[$operator])) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unknown operator "%s". Supported operators: %s',
                    $operator,
                    implode(', ', array_keys($this->operatorMap))
                )
            );
        }

        $strategyClass = $this->operatorMap[$operator];

        return new $strategyClass($operator);
    }

    /**
     * Register a custom operator strategy
     *
     * @param string $operator The operator string
     * @param class-string<OperatorStrategyInterface> $strategyClass The strategy class name
     */
    public function register(string $operator, string $strategyClass): void
    {
        if (!is_subclass_of($strategyClass, OperatorStrategyInterface::class)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Strategy class "%s" must implement %s',
                    $strategyClass,
                    OperatorStrategyInterface::class
                )
            );
        }

        $this->operatorMap[$operator] = $strategyClass;
    }

    /**
     * Check if an operator is registered
     *
     * @param string $operator The operator to check
     * @return bool True if operator is registered
     */
    public function hasOperator(string $operator): bool
    {
        return isset($this->operatorMap[$operator]);
    }

    /**
     * Get all registered operators
     *
     * @return array<string> List of registered operators
     */
    public function getRegisteredOperators(): array
    {
        return array_keys($this->operatorMap);
    }

    /**
     * Register default operator strategies
     */
    private function registerDefaultOperators(): void
    {
        // Equality operators
        $this->operatorMap['=='] = EqualityOperator::class;
        $this->operatorMap['!='] = EqualityOperator::class;

        // Comparison operators
        $this->operatorMap['>'] = ComparisonOperator::class;
        $this->operatorMap['<'] = ComparisonOperator::class;
        $this->operatorMap['>='] = ComparisonOperator::class;
        $this->operatorMap['<='] = ComparisonOperator::class;

        // LIKE operators
        $this->operatorMap['%like%'] = LikeOperator::class;
        $this->operatorMap['like%'] = LikeOperator::class;
        $this->operatorMap['%like'] = LikeOperator::class;

        // NULL check operators
        $this->operatorMap['IS NULL'] = NullCheckOperator::class;
        $this->operatorMap['IS NOT NULL'] = NullCheckOperator::class;
    }
}
