<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Response\CancellationResponse;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for canceling Stripe payment authorizations.
 *
 * Sprint 11: Extract from StripeCancelAuthorizationRequestHandler.
 * Sprint 26: Changed to use factory for lazy adapter creation (module activation fix).
 *
 * @since 2.0.0
 */
class CancelAuthorizationService implements CancelAuthorizationServiceInterface
{
    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ThrowableSummaryInterface $throwableSummary,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function cancelAuthorization(
        string $paymentIntentId,
        ?string $reason = null
    ): CancellationResponse {
        try {
            $cancelledPaymentIntent = $this->adapterFactory->getStripeAdapter()->cancelPaymentIntent(
                $paymentIntentId,
                $reason
            );

            $this->logger->info('Authorization cancelled successfully', [
                'payment_intent_id' => $paymentIntentId,
                'status' => $cancelledPaymentIntent->status,
            ]);

            return CancellationResponse::success(
                providerPaymentId: $paymentIntentId,
                authorizationId: $paymentIntentId,
                status: $cancelledPaymentIntent->status,
                cancelledAt: new DateTimeImmutable(),
                reason: $reason
            );
        } catch (\Throwable $e) {
            $this->logger->error('Cancel authorization failed', [
                'payment_intent_id' => $paymentIntentId,
                'throwable' => $this->throwableSummary->fromThrowable($e),
            ]);

            return CancellationResponse::failure($e->getMessage());
        }
    }
}
