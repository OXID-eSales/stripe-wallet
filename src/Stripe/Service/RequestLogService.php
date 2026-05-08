<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Service\RequestLogServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for logging payment API requests to file.
 *
 * Sprint 27: Implements payment-base RequestLogServiceInterface.
 * Sprint 15: Refactored to use FileLoggerInterface instead of database model.
 * Logs to log/stripe/stripe_requests.log via RequestFileLoggerFactory.
 *
 */
final class RequestLogService implements RequestLogServiceInterface
{
    private readonly LoggerInterface $fallbackLogger;

    public function __construct(
        private readonly FileLoggerInterface $fileLogger,
        ?LoggerInterface $fallbackLogger = null
    ) {
        $this->fallbackLogger = $fallbackLogger ?? new NullLogger();
    }

    public function logRequest(
        string $action,
        array $request,
        array $response,
        string $referenceId,
        int $shopId
    ): void {
        try {
            $this->fileLogger->log($action, [
                'reference_id' => $referenceId,
                'shop_id' => $shopId,
                'request' => $request,
                'response' => $response,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->fallbackLogger->warning('Failed to log request', [
                'action' => $action,
                'reference_id' => $referenceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function logException(
        string $action,
        \Throwable $exception,
        string $referenceId,
        int $shopId
    ): void {
        try {
            $this->fileLogger->log($action . '_EXCEPTION', [
                'reference_id' => $referenceId,
                'shop_id' => $shopId,
                'error_code' => $exception->getCode() ?: 500,
                'error_message' => $exception->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->fallbackLogger->warning('Failed to log exception', [
                'action' => $action,
                'reference_id' => $referenceId,
                'original_error' => $exception->getMessage(),
                'log_error' => $e->getMessage(),
            ]);
        }
    }
}
