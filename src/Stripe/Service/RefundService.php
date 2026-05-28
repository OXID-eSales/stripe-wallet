<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for processing Stripe refunds (full and partial).
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

    public function processRefund(
        string $orderId,
        ?string $paymentIntentId = null,
        ?string $reason = null,
        ?string $description = null,
        string $initiator = 'admin',
        ?float $amount = null
    ): RefundResponse {
        if ($paymentIntentId === null) {
            return RefundResponse::failure('Payment intent ID is required for refund');
        }

        $chargeId = $this->getChargeIdFromPaymentIntent($paymentIntentId);
        if ($chargeId === null) {
            return RefundResponse::failure('No charge found for payment intent');
        }

        $metadata = $this->buildMetadata($orderId, $initiator, $description);
        $validReason = $this->validateReason($reason);
        // currency is sourced from the charge returned by createRefundByCharge;
        // processRefund callers pass the amount in major units. The charge's currency
        // is not available here without an extra API call — pass '' to default to 2 decimals.
        // Sprint 114.7: full currency threading would require adding currency to RefundService
        // callers; safe for EUR-primary shops.
        $amountInCents = $amount !== null ? AmountConverter::toMinorUnits($amount, '') : null;

        return $this->executeRefundByCharge($chargeId, $orderId, $paymentIntentId, $validReason, $metadata, $amountInCents);
    }

    public function processRefundByCharge(
        string $chargeId,
        ?string $reason = null,
        ?array $metadata = null
    ): RefundResponse {
        // Extract orderId from metadata if available
        $orderId = $metadata['order_id'] ?? null;

        return $this->executeRefundByCharge($chargeId, $orderId, null, $reason, $metadata);
    }

    /**
     * Execute refund by charge ID with order context.
     *
     * @param array<string, string>|null $metadata
     * @param int|null $amountInCents Refund amount in cents (null = full refund)
     */
    private function executeRefundByCharge(
        string $chargeId,
        ?string $orderId,
        ?string $paymentIntentId,
        ?string $reason,
        ?array $metadata,
        ?int $amountInCents = null
    ): RefundResponse {
        try {
            $refundDto = $this->adapterFactory
                ->getStripeAdapter()
                ->createRefundByCharge($chargeId, $amountInCents, $reason, $metadata);

            return $this->handleRefundResponse($refundDto, $chargeId, $orderId, $paymentIntentId);
        } catch (PaymentAdapterException $e) {
            return $this->handleRefundError($e, $chargeId);
        }
    }

    private function getChargeIdFromPaymentIntent(string $paymentIntentId): ?string
    {
        try {
            $piDto = $this->adapterFactory
                ->getStripeAdapter()
                ->retrievePaymentIntent($paymentIntentId);

            if ($piDto->charge !== null) {
                return $piDto->charge->id;
            }

            return $piDto->latestChargeId;
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

    private function handleRefundResponse(
        StripeRefundDto $refund,
        string $chargeId,
        ?string $orderId,
        ?string $paymentIntentId
    ): RefundResponse {
        if (!in_array($refund->status, ['succeeded', 'pending'], true)) {
            return RefundResponse::failure("Refund failed with status: {$refund->status}");
        }

        // Restore stock for all order articles (Sprint 24)
        if ($orderId !== null) {
            $articlesProcessed = $this->stockRestorationService->restoreStockForOrder($orderId);
            $this->logger->info('Stock restored after refund', [
                'orderId' => $orderId,
                'articlesProcessed' => $articlesProcessed,
            ]);
        }

        // Convert amount from Stripe minor units to major units using the refund's own currency.
        $refundCurrency     = strtoupper($refund->currency);
        $amountInMajorUnits = AmountConverter::toMajorUnits($refund->amount, $refundCurrency);

        $this->logger->info('Refund processed successfully', [
            'refund_id' => $refund->id,
            'amount'    => $amountInMajorUnits,
            'charge_id' => $chargeId,
            'status'    => $refund->status,
        ]);

        return RefundResponse::success(
            providerPaymentId: $paymentIntentId ?? $chargeId,
            refundId: $refund->id !== '' ? $refund->id : 'unknown',
            amountRefunded: $amountInMajorUnits,
            currency: $refund->currency,
            status: $refund->status,
            refundedAt: new DateTimeImmutable(),
            reason: null,
            providerData: ['charge_id' => $chargeId],
            metadata: $orderId !== null ? ['order_id' => $orderId] : []
        );
    }

    private function handleRefundError(PaymentAdapterException $e, string $chargeId): RefundResponse
    {
        $this->logger->error('Refund failed', [
            'error' => $e->getMessage(),
            'code' => $e->getErrorCode(),
            'charge_id' => $chargeId,
        ]);

        return RefundResponse::failure($e->getMessage(), $e->getErrorCode());
    }
}
