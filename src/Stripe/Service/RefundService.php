<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\Exception\PaymentAdapterException;
use OxidEsales\PaymentComponent\Service\Result\RefundResult;
use OxidEsales\PaymentComponent\Service\StockRestorationServiceInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Refund;

/**
 * Service for processing Stripe refunds.
 *
 * Sprint 21: Extract business logic from StripeRefundRequestHandler.
 * Sprint 22: Removed partial refund - Stripe module only supports full refunds.
 * Sprint 24: Added stock restoration on successful refund.
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
        private readonly StockRestorationServiceInterface $stockRestorationService,
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

        return $this->executeRefundByCharge($chargeId, $orderId, $validReason, $metadata);
    }

    public function processRefundByCharge(
        string $chargeId,
        ?string $reason = null,
        ?array $metadata = null
    ): RefundResult {
        // Extract orderId from metadata if available
        $orderId = $metadata['order_id'] ?? null;

        return $this->executeRefundByCharge($chargeId, $orderId, $reason, $metadata);
    }

    /**
     * Execute refund by charge ID with order context.
     *
     * @param array<string, string>|null $metadata
     */
    private function executeRefundByCharge(
        string $chargeId,
        ?string $orderId,
        ?string $reason,
        ?array $metadata
    ): RefundResult {
        try {
            // Always full refund (null amount)
            $refund = $this->adapterFactory
                ->getStripeAdapter()
                ->createRefundByCharge($chargeId, null, $reason, $metadata);

            return $this->handleRefundResponse($refund, $chargeId, $orderId);
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

    private function handleRefundResponse(Refund $refund, string $chargeId, ?string $orderId): RefundResult
    {
        $status = $refund->status ?? 'unknown';

        if (!in_array($status, ['succeeded', 'pending'], true)) {
            return RefundResult::failure("Refund failed with status: {$status}");
        }

        // Restore stock for all order articles (Sprint 24)
        if ($orderId !== null) {
            $articlesProcessed = $this->stockRestorationService->restoreStockForOrder($orderId);
            $this->logger->info('Stock restored after refund', [
                'orderId' => $orderId,
                'articlesProcessed' => $articlesProcessed,
            ]);
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
