<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionResponse;
use OxidSolutionCatalysts\Payments\Watch\Strategy\OperatorStrategyFactory;

/**
 * Secure query builder using prepared statements
 *
 * This service builds and executes database queries using:
 * - Prepared statements (never string concatenation)
 * - Parameter binding for all user input
 * - Operator strategies for value comparison
 * - DBAL for database abstraction
 *
 * Security Features:
 * - Always uses prepared statements
 * - Parameter binding for WHERE clauses
 * - No direct SQL string interpolation
 * - Query result limiting (LIMIT 1)
 */
final class QueryBuilder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OperatorStrategyFactory $operatorFactory
    ) {
    }

    /**
     * Execute assumption query
     *
     * @param AssumptionRequest $request The assumption request
     * @return AssumptionResponse The query result
     * @throws \Doctrine\DBAL\Exception If query execution fails
     */
    public function execute(AssumptionRequest $request): AssumptionResponse
    {
        $startTime = microtime(true);

        // Build and execute query
        $result = $this->executeQuery($request);

        $queryTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // No rows found
        if ($result === null) {
            return new AssumptionResponse(
                isMatch: false,
                queryTimeMs: $queryTime,
                matchedRows: 0
            );
        }

        // Get the actual value from result
        $actualValue = $result[$request->getFieldName()] ?? null;

        // Compare using operator strategy
        $operator = $this->operatorFactory->create($request->getOperator());
        $isMatch = $operator->compare($actualValue, $request->getExpectedValue());

        return new AssumptionResponse(
            isMatch: $isMatch,
            queryTimeMs: $queryTime,
            matchedRows: 1,
            actualValue: $actualValue,
            expectedValue: $request->getExpectedValue()
        );
    }

    /**
     * Execute the database query with prepared statement
     *
     * @param AssumptionRequest $request The assumption request
     * @return array<string, mixed>|null Query result or null if no rows found
     * @throws \Doctrine\DBAL\Exception If query execution fails
     */
    private function executeQuery(AssumptionRequest $request): ?array
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        // SELECT field FROM table
        $queryBuilder
            ->select($this->connection->quoteIdentifier($request->getFieldName()))
            ->from($this->connection->quoteIdentifier($request->getTableName()));

        // Add WHERE conditions
        $parameters = [];
        $paramIndex = 0;

        foreach ($request->getWhereClause() as $fieldName => $value) {
            $paramName = 'where_' . $paramIndex++;
            $queryBuilder->andWhere(
                $this->connection->quoteIdentifier($fieldName) . ' = :' . $paramName
            );
            $parameters[$paramName] = $value;
        }

        // LIMIT 1 - we only need one row
        $queryBuilder->setMaxResults(1);

        // Execute query with bound parameters
        $result = $queryBuilder->executeQuery($parameters)->fetchAssociative();

        return $result ?: null;
    }
}
