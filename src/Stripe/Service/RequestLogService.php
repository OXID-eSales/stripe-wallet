<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidSolutionCatalysts\Stripe\Application\Model\RequestLog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Facade service for logging payment requests to RequestLog.
 *
 * Sprint 8: Wraps legacy RequestLog model.
 * Benefits:
 * - Handlers don't depend on legacy model directly
 * - Can swap implementation (e.g., to database repository) without changing handlers
 * - Centralized error handling for logging failures
 *
 * Follows existing patterns: NullLogger default, readonly properties, final class.
 *
 * @since 2.0.0
 */
final class RequestLogService implements RequestLogServiceInterface
{
    private readonly LoggerInterface $logger;

    /** @var callable|null */
    private $requestLogFactory;

    /**
     * @param LoggerInterface|null $logger PSR-3 logger
     * @param callable|null $requestLogFactory Factory for creating RequestLog instances (for testing)
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?callable $requestLogFactory = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->requestLogFactory = $requestLogFactory;
    }

    public function logRequest(
        string $action,
        array $request,
        array $response,
        string $referenceId,
        int $shopId
    ): void {
        try {
            $requestLog = $this->createRequestLog();
            $requestLog->logRequest(
                array_merge($request, ['action' => $action]),
                $response,
                $referenceId,
                $shopId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log request to RequestLog', [
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
            $requestLog = $this->createRequestLog();
            $requestLog->logExceptionResponse(
                ['action' => $action],
                $this->resolveExceptionCode($exception),
                $exception->getMessage(),
                $action,
                $referenceId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log exception to RequestLog', [
                'action' => $action,
                'reference_id' => $referenceId,
                'original_error' => $exception->getMessage(),
                'log_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve exception code, defaulting to 500 if not set.
     */
    private function resolveExceptionCode(\Throwable $exception): int
    {
        $code = $exception->getCode();

        if ($code === 0 || !is_int($code)) {
            return 500;
        }

        return $code;
    }

    /**
     * Create RequestLog instance using factory or default.
     *
     * @return RequestLog|object RequestLog or compatible object (for testing)
     */
    private function createRequestLog(): object
    {
        if ($this->requestLogFactory !== null) {
            return ($this->requestLogFactory)();
        }

        return oxNew(RequestLog::class);
    }
}
