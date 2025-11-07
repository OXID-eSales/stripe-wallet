<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Exception;

/**
 * Exception thrown when validation fails
 *
 * This exception is used for validation errors including:
 * - Invalid identifiers (table/field names)
 * - SQL injection attempts
 * - Invalid operators
 * - Malformed requests
 */
class ValidationException extends \InvalidArgumentException
{
}
