<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Exception;

/**
 * Exception thrown when authentication fails
 *
 * This exception is used for authentication errors including:
 * - Invalid API key
 * - IP address not allowed
 * - Missing authentication credentials
 */
class AuthenticationException extends \RuntimeException
{
}
