<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\PaymentAdapterException;
use OxidSolutionCatalysts\Payments\Stripe\DTO\RefundResult;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Refund;

/**
 * Service for processing Stripe refunds.
 *
 * Sprint 21: Extract business logic from StripeRefundRequestHandler.
 *
 * SOLID Principles:
 * - SRP: Only handles refund processing logic
 * - OCP: Can be extended for different refund strategies
 * - DIP: Depends on abstractions (interfaces)
 *
 * @since 2.0.0
 */
final class RefundService implements RefundServiceInterface
{
    /** @var array<string> Valid Stripe refund reasons */
    private const VALID_REASONS = ['duplicate', 'fraudulent', 'requested_by_customer'];

    private LoggerInterface $logger;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function processFullRefund(
        string $orderId,
        ?string $paymentIntentId = null,
        ?string $reason = null,
        ?string $description = null,
        string $initiator = 'admin'
    ): RefundResult {
        if ($paymentIntentId === null) {
            return RefundResult::failure('Payment intent ID is required for refund');
        }

        $chargeId = $this->getChargeIdFromPaymentIntent($paymentIntentId);
        if ($chargeId === null) {
            return RefundResult::failure('No charge found for payment intent');
        }

        $metadata = $this->buildMetadata($orderId, $initiator, $description);
        $validReason = $this->validateReason($reason);

        return $this->processRefundByCharge($chargeId, null, $validReason, $metadata);
    }

    public function processPartialRefund(
        string $orderId,
        int $amountCents,
        ?string $paymentIntentId = null,
        ?string $reason = null,
        ?string $description = null,
        string $initiator = 'admin'
    ): RefundResult {
        if ($paymentIntentId === null) {
            return RefundResult::failure('Payment intent ID is required for partial refund');
        }

        $chargeId = $this->getChargeIdFromPaymentIntent($paymentIntentId);
        if ($chargeId === null) {
            return RefundResult::failure('No charge found for payment intent');
        }

        $metadata = $this->buildMetadata($orderId, $initiator, $description);
        $validReason = $this->validateReason($reason);

        return $this->processRefundByCharge($chargeId, $amountCents, $validReason, $metadata);
    }

    public function processRefundByCharge(
        string $chargeId,
        ?int $amountCents = null,
        ?string $reason = null,
        ?array $metadata = null
    ): RefundResult {
        try {
            $refund = $this->adapterFactory
                ->getStripeAdapter()
                ->createRefundByCharge($chargeId, $amountCents, $reason, $metadata);

            return $this->handleRefundResponse($refund, $chargeId);
        } catch (PaymentAdapterException $e) {
            return $this->handleRefundError($e, $chargeId);
        }
    }

    private function getChargeIdFromPaymentIntent(string $paymentIntentId): ?string
    {
        try {
            $paymentIntent = $this->adapterFactory
                ->getStripeAdapter()
                ->retrievePaymentIntent($paymentIntentId);

            $latestCharge = $paymentIntent->latest_charge;
            if ($latestCharge === null) {
                return null;
            }

            return is_string($latestCharge) ? $latestCharge : ($latestCharge->id ?? null);
        } catch (PaymentAdapterException $e) {
            $this->logger->error('Failed to retrieve payment intent', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildMetadata(string $orderId, string $initiator, ?string $description): array
    {
        $metadata = [
            'order_id' => $orderId,
            'initiator' => $initiator,
        ];

        if ($description !== null) {
            $metadata['description'] = $description;
        }

        return $metadata;
    }

    private function validateReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return in_array($reason, self::VALID_REASONS, true) ? $reason : null;
    }

    private function handleRefundResponse(Refund $refund, string $chargeId): RefundResult
    {
        $status = $refund->status ?? 'unknown';

        if (!in_array($status, ['succeeded', 'pending'], true)) {
            return RefundResult::failure("Refund failed with status: {$status}");
        }

        $this->logger->info('Refund processed successfully', [
            'refund_id' => $refund->id,
            'amount' => ($refund->amount ?? 0) / 100,
            'charge_id' => $chargeId,
            'status' => $status,
        ]);

        return RefundResult::success(
            $refund->id ?? 'unknown',
            (int) ($refund->amount ?? 0),
            $refund->currency ?? 'eur',
            $status
        );
    }

    private function handleRefundError(PaymentAdapterException $e, string $chargeId): RefundResult
    {
        $this->logger->error('Refund failed', [
            'error' => $e->getMessage(),
            'code' => $e->getErrorCode(),
            'charge_id' => $chargeId,
        ]);

        return RefundResult::failure($e->getMessage(), $e->getErrorCode());
    }
}
