<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;
use OxidSolutionCatalysts\Payments\Watch\Exception\ValidationException;

/**
 * Parser for assumption requests from JSON payload
 *
 * Converts HTTP request payloads into AssumptionRequest value objects
 * with comprehensive validation.
 *
 * Expected JSON format:
 * ```json
 * {
 *   "assumption": {
 *     "osc_payment_contract.OXSTATE": "committed",
 *     "where": {
 *       "OXID": "contract-123"
 *     },
 *     "operator": "=="
 *   }
 * }
 * ```
 */
final class AssumptionParser
{
    public function __construct(
        private readonly RequestValidator $validator
    ) {
    }

    /**
     * Parse assumption request from array
     *
     * @param array<string, mixed> $payload Request payload
     * @return AssumptionRequest Parsed assumption request
     * @throws ValidationException If payload is invalid
     */
    public function parse(array $payload): AssumptionRequest
    {
        // Check for assumption key
        if (!isset($payload['assumption']) || !is_array($payload['assumption'])) {
            throw new ValidationException('Missing or invalid "assumption" field');
        }

        $assumption = $payload['assumption'];

        // Extract field path and expected value
        [$fieldPath, $expectedValue] = $this->extractFieldPathAndValue($assumption);

        // Parse field path (table.field format)
        [$tableName, $fieldName] = $this->parseFieldPath($fieldPath);

        // Validate identifiers
        $this->validator->validateIdentifier($tableName, 'table name');
        $this->validator->validateIdentifier($fieldName, 'field name');

        // Extract operator (default to '==')
        $operator = $assumption['operator'] ?? '==';
        $this->validator->validateOperator($operator);

        // Extract WHERE clause
        $whereClause = $assumption['where'] ?? [];
        if (!is_array($whereClause)) {
            throw new ValidationException('WHERE clause must be an array');
        }

        $this->validator->validateWhereClause($whereClause);

        return new AssumptionRequest(
            tableName: $tableName,
            fieldName: $fieldName,
            expectedValue: $expectedValue,
            operator: $operator,
            whereClause: $whereClause
        );
    }

    /**
     * Extract field path and expected value from assumption array
     *
     * The assumption should have exactly one field path key (e.g., "table.field")
     * with the expected value.
     *
     * @param array<string, mixed> $assumption Assumption data
     * @return array{0: string, 1: mixed} [fieldPath, expectedValue]
     * @throws ValidationException If format is invalid
     */
    private function extractFieldPathAndValue(array $assumption): array
    {
        // Filter out special keys (operator, where)
        $fieldKeys = array_diff(array_keys($assumption), ['operator', 'where']);

        if (count($fieldKeys) === 0) {
            throw new ValidationException('No field path specified in assumption');
        }

        if (count($fieldKeys) > 1) {
            throw new ValidationException('Only one field path allowed per assumption');
        }

        $fieldPath = reset($fieldKeys);
        $expectedValue = $assumption[$fieldPath];

        return [$fieldPath, $expectedValue];
    }

    /**
     * Parse field path into table and field names
     *
     * Expected format: "tablename.fieldname"
     *
     * @param string $fieldPath Field path string
     * @return array{0: string, 1: string} [tableName, fieldName]
     * @throws ValidationException If format is invalid
     */
    private function parseFieldPath(string $fieldPath): array
    {
        if (!str_contains($fieldPath, '.')) {
            throw new ValidationException(
                'Field path must be in format "table.field"'
            );
        }

        $parts = explode('.', $fieldPath);

        if (count($parts) !== 2) {
            throw new ValidationException(
                'Field path must contain exactly one dot (table.field)'
            );
        }

        [$tableName, $fieldName] = $parts;

        if (empty($tableName) || empty($fieldName)) {
            throw new ValidationException(
                'Both table name and field name must be non-empty'
            );
        }

        return [$tableName, $fieldName];
    }
}
