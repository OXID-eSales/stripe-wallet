<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

/**
 * Immutable value object representing an assumption request
 *
 * This object encapsulates all data needed to verify a database field value.
 * It validates all inputs to prevent SQL injection and ensures data integrity.
 *
 * @example
 * ```php
 * $request = new AssumptionRequest(
 *     tableName: 'osc_payment_contract',
 *     fieldName: 'OXSTATE',
 *     expectedValue: 'committed',
 *     whereClause: ['OXID' => 'contract-123']
 * );
 * ```
 */
final class AssumptionRequest
{
    private const VALID_OPERATORS = [
        '==', '!=',
        '>', '<', '>=', '<=',
        '%like%', 'like%', '%like',
        'IS NULL', 'IS NOT NULL'
    ];

    /**
     * @param string $tableName Database table name (validated for SQL safety)
     * @param string $fieldName Field name in the table (validated for SQL safety)
     * @param mixed $expectedValue The expected value to compare against
     * @param string $operator Comparison operator (defaults to '==')
     * @param array<string, mixed> $whereClause Optional WHERE conditions for row identification
     *
     * @throws \InvalidArgumentException If any parameter is invalid
     */
    public function __construct(
        private readonly string $tableName,
        private readonly string $fieldName,
        private readonly mixed $expectedValue,
        private readonly string $operator = '==',
        private readonly array $whereClause = []
    ) {
        $this->validateTableName($tableName);
        $this->validateFieldName($fieldName);
        $this->validateOperator($operator);
        $this->validateWhereClause($whereClause);
    }

    /**
     * Get the table name
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get the field name
     */
    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    /**
     * Get the expected value
     */
    public function getExpectedValue(): mixed
    {
        return $this->expectedValue;
    }

    /**
     * Get the comparison operator
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * Get the WHERE clause conditions
     *
     * @return array<string, mixed>
     */
    public function getWhereClause(): array
    {
        return $this->whereClause;
    }

    /**
     * Get the full field path (table.field)
     *
     * @return string The field path in format "tablename.fieldname"
     */
    public function getFieldPath(): string
    {
        return $this->tableName . '.' . $this->fieldName;
    }

    /**
     * Validate table name for SQL injection prevention
     *
     * Allows only alphanumeric characters and underscores.
     * Table names must match pattern: [a-zA-Z_][a-zA-Z0-9_]*
     *
     * @throws \InvalidArgumentException If table name is invalid
     */
    private function validateTableName(string $tableName): void
    {
        if (empty($tableName)) {
            throw new \InvalidArgumentException('Table name cannot be empty');
        }

        // Only allow valid SQL identifier characters
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
            throw new \InvalidArgumentException(
                'Invalid table name format. Only alphanumeric characters and underscores allowed.'
            );
        }
    }

    /**
     * Validate field name for SQL injection prevention
     *
     * Allows only alphanumeric characters and underscores.
     * Field names must match pattern: [a-zA-Z_][a-zA-Z0-9_]*
     *
     * @throws \InvalidArgumentException If field name is invalid
     */
    private function validateFieldName(string $fieldName): void
    {
        if (empty($fieldName)) {
            throw new \InvalidArgumentException('Field name cannot be empty');
        }

        // Only allow valid SQL identifier characters
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
            throw new \InvalidArgumentException(
                'Invalid field name format. Only alphanumeric characters and underscores allowed.'
            );
        }
    }

    /**
     * Validate operator against whitelist
     *
     * @throws \InvalidArgumentException If operator is not in whitelist
     */
    private function validateOperator(string $operator): void
    {
        if (!in_array($operator, self::VALID_OPERATORS, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid operator "%s". Allowed operators: %s',
                    $operator,
                    implode(', ', self::VALID_OPERATORS)
                )
            );
        }
    }

    /**
     * Validate WHERE clause field names
     *
     * @param array<string, mixed> $whereClause
     * @throws \InvalidArgumentException If any WHERE clause field name is invalid
     */
    private function validateWhereClause(array $whereClause): void
    {
        foreach (array_keys($whereClause) as $fieldName) {
            if (!is_string($fieldName)) {
                throw new \InvalidArgumentException('WHERE clause keys must be strings');
            }

            // Validate each field name in WHERE clause
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid field name in WHERE clause: %s', $fieldName)
                );
            }
        }
    }
}
