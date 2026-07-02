<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;

/**
 * Shared plumbing for Stripe request-type event handlers.
 *
 * Sprint 114.8: Extracted from D4 duplication — the identical logEvent() method
 * was copy-pasted into all 9 event handlers. This base class owns it once.
 *
 * Concrete handlers that extend this class:
 * - StripeCaptureRequestHandler
 * - StripeRefundRequestHandler
 * - StripeCancelAuthorizationRequestHandler
 *
 * Each concrete handler still owns its own handle(), handleException(), and
 * logExceptionToRequestLog() (those vary per handler: different context keys,
 * different action strings, different referenceId sources). Only logEvent() is
 * truly identical and therefore belongs here.
 *
 * @since 2.0.0
 */
abstract class AbstractStripeRequestHandler implements HandlerInterface
{
    protected ?FileLoggerInterface $eventLogger = null;

    /**
     * Log event to file logger for debugging.
     *
     * @param array<string, mixed> $context
     */
    protected function logEvent(string $message, array $context = []): void
    {
        if ($this->eventLogger !== null) {
            $this->eventLogger->log($message, $context);
        }
    }
}
