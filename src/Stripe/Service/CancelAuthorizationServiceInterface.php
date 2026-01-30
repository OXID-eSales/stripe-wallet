<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\Response\CancellationResponse;

/**
 * Service interface for canceling Stripe payment authorizations.
 *
 * Sprint 11: Extract from handler.
 * Sprint 31: Returns CancellationResponse instead of CancellationResult.
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
     * @return CancellationResponse Response object
     */
    public function cancelAuthorization(
        string $paymentIntentId,
        ?string $reason = null
    ): CancellationResponse;
}
