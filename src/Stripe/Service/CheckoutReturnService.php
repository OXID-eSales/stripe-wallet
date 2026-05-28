<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutReturnResult;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for processing Stripe checkout returns.
 *
 * Sprint 21: Extract business logic from StripeCheckoutReturnHandler.
 *
 * SOLID Principles:
 * - SRP: Only handles checkout return validation logic
 * - OCP: Can be extended for different checkout flows
 * - DIP: Depends on abstractions (interfaces)
 *
 * @since 2.0.0
 */
final class CheckoutReturnService implements CheckoutReturnServiceInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly TokenServiceInterface $tokenService,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function validateReturn(
        string $checkoutSessionId,
        string $contractId,
        string $contractToken
    ): CheckoutReturnResult {
        // Step 1: Validate token
        if (!$this->tokenService->validateToken($contractToken, $contractId)) {
            $this->logger->warning('Invalid contract token', [
                'contract_id' => $contractId,
                'session_id' => $checkoutSessionId,
            ]);
            return CheckoutReturnResult::failure('Invalid contract token');
        }

        // Step 2: Retrieve Stripe session
        $session = $this->retrieveSession($checkoutSessionId);
        if ($session === null) {
            return CheckoutReturnResult::failure('Failed to retrieve checkout session');
        }

        // Step 3: Extract payment details early (needed for validation)
        $paymentStatus        = $session->paymentStatus;
        $paymentIntentStatus  = $session->paymentIntentStatus;

        // Step 4: Validate payment status
        // Accept 'paid' (automatic capture) OR 'unpaid' with requires_capture (manual capture)
        $isAutomaticCapture = $paymentStatus === StripeStatusMapper::CHECKOUT_PAYMENT_STATUS_PAID;
        $isManualCapture = $paymentStatus === StripeStatusMapper::CHECKOUT_PAYMENT_STATUS_UNPAID
            && $paymentIntentStatus === StripeStatusMapper::STRIPE_REQUIRES_CAPTURE;

        if (!$isAutomaticCapture && !$isManualCapture) {
            return CheckoutReturnResult::failure("Payment not completed: {$paymentStatus}");
        }

        // Step 5: Validate contract ID from metadata
        $metadataContractId = $session->metadata['contract_id'] ?? null;
        if ($metadataContractId === null) {
            return CheckoutReturnResult::failure('Contract ID not found in checkout session metadata');
        }

        if ($metadataContractId !== $contractId) {
            $this->logger->warning('Contract ID mismatch', [
                'url_contract_id' => $contractId,
                'metadata_contract_id' => $metadataContractId,
            ]);
            return CheckoutReturnResult::failure('Contract ID mismatch');
        }

        // Step 6: Extract remaining payment details
        $paymentIntentId = $session->paymentIntentId;
        $amountTotal     = $session->amountTotal;
        $currency        = $session->currency;

        $this->logger->info('Checkout return validated successfully', [
            'contract_id' => $contractId,
            'payment_intent_id' => $paymentIntentId,
            'payment_intent_status' => $paymentIntentStatus,
            'amount' => AmountConverter::toMajorUnits($amountTotal, strtoupper($currency)),
            'currency' => $currency,
        ]);

        return CheckoutReturnResult::success(
            $contractId,
            $paymentIntentId,
            $amountTotal,
            $currency,
            $paymentStatus,
            $paymentIntentStatus
        );
    }

    private function retrieveSession(string $checkoutSessionId): ?StripeCheckoutSessionDto
    {
        try {
            return $this->adapterFactory
                ->getStripeAdapter()
                ->retrieveCheckoutSession($checkoutSessionId, ['payment_intent']);
        } catch (PaymentAdapterException $e) {
            $this->logger->error('Failed to retrieve checkout session', [
                'session_id' => $checkoutSessionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
