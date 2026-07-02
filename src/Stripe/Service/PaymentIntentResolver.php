<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use RuntimeException;

/**
 * Resolves a Stripe PaymentIntent ID from multiple possible sources.
 *
 * Sprint 114.8: Extracted from D4 duplication in StripeCaptureRequestHandler::getPaymentIntentId
 * and StripeCancelAuthorizationRequestHandler::resolvePaymentIntentId. Both handlers had
 * the same three-step resolution chain:
 *   1. Explicit id from event
 *   2. Contract providerOrderId
 *   3. Contract metadata['payment_intent_id']
 *
 * Throws RuntimeException on failure so callers can catch and set context errors uniformly.
 *
 * @since 2.0.0
 */
class PaymentIntentResolver
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository
    ) {
    }

    /**
     * Resolve the PaymentIntent ID.
     *
     * @throws RuntimeException when the id cannot be resolved.
     */
    public function resolve(?string $explicitPaymentIntentId, ?string $contractId): string
    {
        if ($explicitPaymentIntentId !== null && $explicitPaymentIntentId !== '') {
            return $explicitPaymentIntentId;
        }

        if ($contractId === null || $contractId === '') {
            throw new RuntimeException('PaymentIntent ID is missing');
        }

        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            throw new RuntimeException('Contract not found: ' . $contractId);
        }

        $providerOrderId = $contract->getProviderOrderId();
        if (is_string($providerOrderId) && $providerOrderId !== '') {
            return $providerOrderId;
        }

        $metadataId = $contract->getMetadata('payment_intent_id');
        if (is_string($metadataId) && $metadataId !== '') {
            return $metadataId;
        }

        throw new RuntimeException('No PaymentIntent ID found for this contract');
    }
}
