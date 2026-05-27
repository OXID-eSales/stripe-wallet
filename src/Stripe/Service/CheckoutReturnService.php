<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutReturnResult;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Checkout\Session;

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
        $paymentStatus = $session->payment_status ?? 'unknown';
        $paymentIntentStatus = $this->extractPaymentIntentStatus($session);

        // Step 4: Validate payment status
        // Accept 'paid' (automatic capture) OR 'unpaid' with requires_capture (manual capture)
        $isAutomaticCapture = $paymentStatus === 'paid';
        $isManualCapture = $paymentStatus === 'unpaid' && $paymentIntentStatus === 'requires_capture';

        if (!$isAutomaticCapture && !$isManualCapture) {
            return CheckoutReturnResult::failure("Payment not completed: {$paymentStatus}");
        }

        // Step 5: Validate contract ID from metadata
        $metadataContractId = $session->metadata->contract_id ?? null;
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
        $paymentIntentId = $this->extractPaymentIntentId($session);
        $amountTotal = (int) ($session->amount_total ?? 0);
        $currency = $session->currency ?? 'eur';

        $this->logger->info('Checkout return validated successfully', [
            'contract_id' => $contractId,
            'payment_intent_id' => $paymentIntentId,
            'payment_intent_status' => $paymentIntentStatus,
            'amount' => $amountTotal / 100,
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

    private function retrieveSession(string $checkoutSessionId): ?Session
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

    private function extractPaymentIntentId(Session $session): string
    {
        $paymentIntent = $session->payment_intent;

        if (is_string($paymentIntent)) {
            return $paymentIntent;
        }

        if (is_object($paymentIntent) && isset($paymentIntent->id)) {
            return $paymentIntent->id;
        }

        // @phpstan-ignore-next-line Stripe SDK may return array in some edge cases
        if (is_array($paymentIntent) && isset($paymentIntent['id'])) {
            return $paymentIntent['id'];
        }

        return '';
    }

    /**
     * Extract PaymentIntent status from expanded Session.
     *
     * The session must be retrieved with ['payment_intent'] expansion
     * to get the full PaymentIntent object with status.
     *
     * @param Session $session Checkout Session with expanded payment_intent
     * @return string PaymentIntent status (succeeded, requires_capture, etc.)
     */
    private function extractPaymentIntentStatus(Session $session): string
    {
        $paymentIntent = $session->payment_intent;

        // If expanded, payment_intent is an object with status
        if (is_object($paymentIntent) && isset($paymentIntent->status)) {
            // @phpstan-ignore-next-line ternary.alwaysTrue - Stripe SDK typings may not reflect runtime reality
            return is_string($paymentIntent->status) ? $paymentIntent->status : 'unknown';
        }

        // @phpstan-ignore-next-line Stripe SDK may return array in some edge cases
        if (is_array($paymentIntent) && isset($paymentIntent['status'])) {
            return is_string($paymentIntent['status']) ? $paymentIntent['status'] : 'unknown';
        }

        // If not expanded (string ID only), we can't get status without another API call
        // Default to 'succeeded' for backwards compatibility with existing flows
        $this->logger->warning('PaymentIntent not expanded, assuming succeeded status', [
            'session_id' => $session->id ?? 'unknown',
        ]);

        return 'succeeded';
    }
}
