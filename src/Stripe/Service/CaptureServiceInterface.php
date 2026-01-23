<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\DTO\CaptureResult;

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
     * @param array<string, string> $metadata Metadata to attach
     * @return CaptureResult Result object (never throws)
     */
    public function processCapture(
        PaymentContractInterface $contract,
        ?float $amount,
        array $metadata
    ): CaptureResult;

    /**
     * Process a direct capture without contract (admin panel).
     *
     * Used when capturing from admin panel where no contract exists.
     * Does NOT handle contract state - only Stripe API call.
     *
     * @param string $paymentIntentId Stripe PaymentIntent ID
     * @param float|null $amount Amount in currency units (null for full capture)
     * @param array<string, string> $metadata Metadata to attach
     * @return CaptureResult Result object (never throws)
     */
    public function processDirectCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata
    ): CaptureResult;
}
