<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

/**
 * Interface for MCP request/response logging.
 *
 * Sprint 56: Provides structured logging for MCP, ACP, and UCP controllers.
 * All implementations must use silent-fail pattern (never break controller flow).
 *
 * @since Sprint 56
 */
interface McpLogServiceInterface
{
    /**
     * Log an incoming request.
     *
     * @param string $controllerType Controller identifier (MCP, UCP_CHECKOUT, UCP_PROFILE, PRODUCT_FEED, RESOURCE_METADATA)
     * @param array<string, mixed> $requestData Request metadata (client_ip, body_size, method, etc.)
     */
    public function logRequest(string $controllerType, array $requestData): void;

    /**
     * Log a successful response.
     *
     * @param string $controllerType Controller identifier
     * @param int $httpStatusCode HTTP status code
     * @param array<string, mixed> $responseData Response payload (truncated if >4KB)
     */
    public function logResponse(string $controllerType, int $httpStatusCode, array $responseData): void;

    /**
     * Log an error response.
     *
     * @param string $controllerType Controller identifier
     * @param int $httpStatusCode HTTP status code
     * @param string $errorMessage Error description
     * @param array<string, mixed> $errorData Additional error context (exception_class, exception_message, etc.)
     */
    public function logError(string $controllerType, int $httpStatusCode, string $errorMessage, array $errorData = []): void;
}
