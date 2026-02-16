<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * MCP request/response logging service.
 *
 * Sprint 56: Wraps FileLoggerInterface with silent-fail pattern
 * (same as WebhookLogService). Truncates large payloads to prevent log bloat.
 *
 * @since Sprint 56
 */
final class McpLogService implements McpLogServiceInterface
{
    private const MAX_RESPONSE_BYTES = 4096;

    public function __construct(
        private readonly FileLoggerInterface $fileLogger
    ) {
    }

    /**
     * @param array<string, mixed> $requestData
     */
    public function logRequest(string $controllerType, array $requestData): void
    {
        try {
            $this->fileLogger->log('REQUEST', array_merge(
                ['controller' => $controllerType],
                $requestData
            ));
        } catch (\Throwable) {
            // Silent fail — don't break controller flow
        }
    }

    /**
     * @param array<string, mixed> $responseData
     */
    public function logResponse(string $controllerType, int $httpStatusCode, array $responseData): void
    {
        try {
            $this->fileLogger->log('RESPONSE', [
                'controller' => $controllerType,
                'http_status' => $httpStatusCode,
                'response' => $this->truncatePayload($responseData),
            ]);
        } catch (\Throwable) {
            // Silent fail — don't break controller flow
        }
    }

    /**
     * @param array<string, mixed> $errorData
     */
    public function logError(
        string $controllerType,
        int $httpStatusCode,
        string $errorMessage,
        array $errorData = []
    ): void {
        try {
            $this->fileLogger->log('ERROR', array_merge(
                [
                    'controller' => $controllerType,
                    'http_status' => $httpStatusCode,
                    'error_message' => $errorMessage,
                ],
                $errorData
            ));
        } catch (\Throwable) {
            // Silent fail — don't break controller flow
        }
    }

    /**
     * Truncate response payload if JSON representation exceeds MAX_RESPONSE_BYTES.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|string
     */
    private function truncatePayload(array $payload): array|string
    {
        $json = json_encode($payload);
        if ($json === false) {
            return ['_truncated' => true, '_reason' => 'json_encode_failed'];
        }

        if (strlen($json) <= self::MAX_RESPONSE_BYTES) {
            return $payload;
        }

        return '[truncated: ' . strlen($json) . ' bytes]';
    }
}
