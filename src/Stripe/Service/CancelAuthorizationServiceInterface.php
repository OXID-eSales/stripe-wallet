<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Service\Result\CancellationResult;

/**
 * Service interface for canceling Stripe payment authorizations.
 *
 * Sprint 11: Extract from handler.
 *
 * @since 2.0.0
 */
interface CancelAuthorizationServiceInterface
{
    /**
     * Cancel a PaymentIntent authorization.
     *
     * @param string $paymentIntentId Stripe PaymentIntent ID (pi_xxx)
     * @param string|null $reason Cancellation reason
     * @return CancellationResult Result of the cancellation
     */
    public function cancelAuthorization(
        string $paymentIntentId,
        ?string $reason = null
    ): CancellationResult;
}
