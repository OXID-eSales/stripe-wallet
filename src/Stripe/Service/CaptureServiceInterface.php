<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Service interface for capturing Stripe payments.
 *
 * Sprint 9: Two methods for different capture scenarios:
 * - processCapture(): Contract-based capture with state transition
 * - processDirectCapture(): Direct capture without contract (admin panel)
 *
 * @since 2.0.0
 */
interface CaptureServiceInterface
{
    /**
     * Process a capture for a contract.
     *
     * Handles:
     * - Stripe API capture call
     * - Contract state transition (AUTHORIZED -> READY_TO_COMMIT)
     * - Contract persistence
     *
     * @param PaymentContractInterface $contract The contract to capture
     * @param float|null $amount Amount in currency units (null for full capture)
     * @param array<string, mixed> $metadata Metadata to attach
     * @return CaptureResponse Response object (never throws)
     */
    public function processCapture(
        PaymentContractInterface $contract,
        ?float $amount,
        array $metadata
    ): CaptureResponse;

    /**
     * Process a direct capture without contract (admin panel).
     *
     * Used when capturing from admin panel where no contract exists.
     * Does NOT handle contract state - only Stripe API call.
     *
     * @param string $paymentIntentId Stripe PaymentIntent ID
     * @param float|null $amount Amount in currency units (null for full capture)
     * @param array<string, mixed> $metadata Metadata to attach
     * @return CaptureResponse Response object (never throws)
     */
    public function processDirectCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata
    ): CaptureResponse;
}
