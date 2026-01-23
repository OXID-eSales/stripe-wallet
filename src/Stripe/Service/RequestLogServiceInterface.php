<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Service interface for logging payment requests to RequestLog.
 *
 * Sprint 8: Facade pattern - wraps legacy RequestLog model.
 * Can swap implementation later without changing handlers.
 *
 * @since 2.0.0
 */
interface RequestLogServiceInterface
{
    /**
     * Log a successful payment request.
     *
     * @param string $action Action type (capture, refund, cancel_authorization)
     * @param array<string, mixed> $request Request data
     * @param array<string, mixed> $response Response data
     * @param string $referenceId Order ID or Contract ID
     * @param int $shopId Shop ID
     */
    public function logRequest(
        string $action,
        array $request,
        array $response,
        string $referenceId,
        int $shopId
    ): void;

    /**
     * Log a failed payment request exception.
     *
     * @param string $action Action type
     * @param \Throwable $exception The exception
     * @param string $referenceId Order ID or Contract ID
     * @param int $shopId Shop ID
     */
    public function logException(
        string $action,
        \Throwable $exception,
        string $referenceId,
        int $shopId
    ): void;
}
