<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

use OxidSolutionCatalysts\Payments\Watch\Exception\ValidationException;

/**
 * Request validator with SQL injection prevention
 *
 * This service validates all user input to prevent SQL injection attacks.
 * It uses whitelisting approach - only allowing valid SQL identifier characters.
 *
 * Security Features:
 * - Blocks SQL keywords (SELECT, DROP, INSERT, etc.)
 * - Validates identifier format (alphanumeric + underscore only)
 * - Rejects special characters (; ' " ` etc.)
 * - Validates operator whitelist
 */
final class RequestValidator
{
    private const SQL_KEYWORDS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'UNION', 'JOIN', 'WHERE', 'FROM', 'TABLE', 'DATABASE',
        'EXEC', 'EXECUTE', 'DECLARE', 'CAST', 'CONVERT', 'SCRIPT', 'JAVASCRIPT',
        'EVAL', 'EXPRESSION', 'COMPILE'
    ];

    private const VALID_OPERATORS = [
        '==', '!=', '>', '<', '>=', '<=',
        '%like%', 'like%', '%like',
        'IS NULL', 'IS NOT NULL'
    ];

    /**
     * Validate SQL identifier (table name, field name)
     *
     * @param string $identifier The identifier to validate
     * @param string $type Type description for error messages ('table name', 'field name')
     * @throws ValidationException If identifier is invalid
     */
    public function validateIdentifier(string $identifier, string $type = 'identifier'): void
    {
        // Check empty
        if (empty($identifier)) {
            throw new ValidationException(sprintf('%s cannot be empty', ucfirst($type)));
        }

        // Validate format: must start with letter or underscore, followed by alphanumeric or underscore
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new ValidationException(
                sprintf(
                    'Invalid %s format: "%s". Only alphanumeric characters and underscores allowed.',
                    $type,
                    $identifier
                )
            );
        }

        // Block SQL keywords
        $upperIdentifier = strtoupper($identifier);
        if (in_array($upperIdentifier, self::SQL_KEYWORDS, true)) {
            throw new ValidationException(
                sprintf(
                    'SQL keyword not allowed as %s: "%s"',
                    $type,
                    $identifier
                )
            );
        }
    }

    /**
     * Validate operator against whitelist
     *
     * @param string $operator The operator to validate
     * @throws ValidationException If operator is invalid
     */
    public function validateOperator(string $operator): void
    {
        if (!in_array($operator, self::VALID_OPERATORS, true)) {
            throw new ValidationException(
                sprintf(
                    'Invalid operator: "%s". Allowed: %s',
                    $operator,
                    implode(', ', self::VALID_OPERATORS)
                )
            );
        }
    }

    /**
     * Validate WHERE clause field names
     *
     * @param array<string, mixed> $whereClause WHERE conditions to validate
     * @throws ValidationException If any field name is invalid
     */
    public function validateWhereClause(array $whereClause): void
    {
        foreach (array_keys($whereClause) as $fieldName) {
            if (!is_string($fieldName)) {
                throw new ValidationException('WHERE clause keys must be strings');
            }

            $this->validateIdentifier($fieldName, 'WHERE clause field');
        }
    }

    /**
     * Detect potential SQL injection attempts
     *
     * This method checks for common SQL injection patterns in any string value.
     *
     * @param string $value Value to check
     * @return bool True if suspicious patterns detected
     */
    public function detectSqlInjectionPatterns(string $value): bool
    {
        $patterns = [
            '/;\s*(DROP|DELETE|TRUNCATE|ALTER)/i',  // Dangerous commands
            '/UNION\s+SELECT/i',                     // Union attacks
            '/\/\*.*\*\//i',                         // SQL comments
            '/--\s*$/',                              // SQL comment at end
            '/#\s*$/',                               // MySQL comment
            '/\bOR\b\s+\d+\s*=\s*\d+/i',            // OR 1=1 attacks
            '/\'.*\bOR\b.*\'/i',                     // Quote-based OR attacks
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of valid operators
     *
     * @return array<string>
     */
    public function getValidOperators(): array
    {
        return self::VALID_OPERATORS;
    }
}
