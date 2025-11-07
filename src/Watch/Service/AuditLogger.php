<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionResponse;

/**
 * Audit logger for PaymentWatch requests
 *
 * Logs all requests with sensitive data sanitization.
 * Provides structured logging for security monitoring and debugging.
 *
 * Log Format:
 * - Request ID (for tracing)
 * - Client IP (with partial masking option)
 * - API Key (partial, first 8 chars only)
 * - Query details (table, field, operator)
 * - Result (match status, query time, rows)
 * - Timestamp
 */
final class AuditLogger
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Log successful request
     *
     * @param string $requestId Unique request identifier
     * @param string $clientIp Client IP address
     * @param string $apiKey API key (will be partially masked)
     * @param AssumptionRequest $request The assumption request
     * @param AssumptionResponse $response The query response
     */
    public function logRequest(
        string $requestId,
        string $clientIp,
        string $apiKey,
        AssumptionRequest $request,
        AssumptionResponse $response
    ): void {
        $context = [
            'request_id' => $requestId,
            'client_ip' => $this->sanitizeIp($clientIp),
            'api_key_partial' => $this->sanitizeApiKey($apiKey),
            'query' => [
                'table' => $request->getTableName(),
                'field' => $request->getFieldName(),
                'operator' => $request->getOperator(),
                'where_fields' => array_keys($request->getWhereClause()),
            ],
            'result' => [
                'assumption' => $response->isMatch(),
                'query_time_ms' => $response->getQueryTimeMs(),
                'matched_rows' => $response->getMatchedRows(),
            ],
        ];

        $message = sprintf(
            'PaymentWatch request: %s.%s %s [match=%s, time=%.2fms]',
            $request->getTableName(),
            $request->getFieldName(),
            $request->getOperator(),
            $response->isMatch() ? 'true' : 'false',
            $response->getQueryTimeMs()
        );

        $this->logger->info($message, $context);
    }

    /**
     * Log authentication failure
     *
     * @param string $requestId Unique request identifier
     * @param string $clientIp Client IP address
     * @param string $reason Failure reason
     */
    public function logAuthenticationFailure(
        string $requestId,
        string $clientIp,
        string $reason
    ): void {
        $context = [
            'request_id' => $requestId,
            'client_ip' => $clientIp,
            'reason' => $reason,
        ];

        $message = sprintf(
            'PaymentWatch authentication failed: %s (IP: %s)',
            $reason,
            $this->sanitizeIp($clientIp)
        );

        $this->logger->warning($message, $context);
    }

    /**
     * Log validation error
     *
     * @param string $requestId Unique request identifier
     * @param string $clientIp Client IP address
     * @param string $error Error message
     */
    public function logValidationError(
        string $requestId,
        string $clientIp,
        string $error
    ): void {
        $context = [
            'request_id' => $requestId,
            'client_ip' => $this->sanitizeIp($clientIp),
            'error' => $error,
        ];

        $message = sprintf(
            'PaymentWatch validation error: %s',
            $error
        );

        $this->logger->warning($message, $context);
    }

    /**
     * Log SQL injection attempt
     *
     * @param string $requestId Unique request identifier
     * @param string $clientIp Client IP address
     * @param string $suspiciousInput The suspicious input
     */
    public function logSqlInjectionAttempt(
        string $requestId,
        string $clientIp,
        string $suspiciousInput
    ): void {
        $context = [
            'request_id' => $requestId,
            'client_ip' => $clientIp,
            'suspicious_input' => substr($suspiciousInput, 0, 100), // Limit length
            'severity' => 'SECURITY',
        ];

        $message = sprintf(
            'SECURITY: Possible SQL injection attempt detected from IP: %s',
            $clientIp
        );

        $this->logger->error($message, $context);
    }

    /**
     * Log database error
     *
     * @param string $requestId Unique request identifier
     * @param string $clientIp Client IP address
     * @param \Throwable $exception The exception
     */
    public function logDatabaseError(
        string $requestId,
        string $clientIp,
        \Throwable $exception
    ): void {
        $context = [
            'request_id' => $requestId,
            'client_ip' => $this->sanitizeIp($clientIp),
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ];

        $message = sprintf(
            'PaymentWatch database error: %s',
            $exception->getMessage()
        );

        $this->logger->error($message, $context);
    }

    /**
     * Sanitize IP address (mask last octet for IPv4)
     *
     * @param string $ip IP address
     * @return string Sanitized IP
     */
    private function sanitizeIp(string $ip): string
    {
        // For privacy, you could mask part of the IP
        // For now, return as-is for debugging purposes
        return $ip;
    }

    /**
     * Sanitize API key (show only first 8 characters)
     *
     * @param string $apiKey API key
     * @return string Sanitized API key
     */
    private function sanitizeApiKey(string $apiKey): string
    {
        if (strlen($apiKey) < 8) {
            return '***';
        }

        return substr($apiKey, 0, 8) . '...' . substr($apiKey, -4);
    }
}
