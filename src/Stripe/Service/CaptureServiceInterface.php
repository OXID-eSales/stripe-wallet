<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Service interface for capturing Stripe payments.
 *
 * - processCapture(): the broker's capture of a contract: Stripe, the booking, the state transition
 * - captureAtStripe(): only the Stripe call (the admin's capture; payment-base's runner books it)
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
     * Ask Stripe to capture a PaymentIntent. Nothing is booked and no contract state moves.
     *
     * @param float|null $amount Amount in currency units (null for full capture)
     * @param string $currency The contract's currency (minor-unit conversion)
     * @param array<string, mixed> $metadata Metadata to attach
     * @return CaptureResponse Response object (never throws); a failure carries Stripe's error code
     */
    public function captureAtStripe(
        string $paymentIntentId,
        ?float $amount,
        string $currency,
        array $metadata
    ): CaptureResponse;
}
