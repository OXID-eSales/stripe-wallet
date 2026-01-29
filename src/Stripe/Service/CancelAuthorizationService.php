<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\PaymentComponent\Service\Result\CancellationResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for canceling Stripe payment authorizations.
 *
 * Sprint 11: Extract from StripeCancelAuthorizationRequestHandler.
 * Sprint 26: Changed to use factory for lazy adapter creation (module activation fix).
 *
 * @since 2.0.0
 */
final class CancelAuthorizationService implements CancelAuthorizationServiceInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function cancelAuthorization(
        string $paymentIntentId,
        ?string $reason = null
    ): CancellationResult {
        try {
            $cancelledPaymentIntent = $this->adapterFactory->getStripeAdapter()->cancelPaymentIntent(
                $paymentIntentId,
                $reason
            );

            $this->logger->info('Authorization cancelled successfully', [
                'payment_intent_id' => $paymentIntentId,
                'status' => $cancelledPaymentIntent->status,
            ]);

            return CancellationResult::success(
                $paymentIntentId,
                $cancelledPaymentIntent->status ?? 'canceled'
            );
        } catch (\Throwable $e) {
            $this->logger->error('Cancel authorization failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return CancellationResult::failure($e->getMessage());
        }
    }
}
