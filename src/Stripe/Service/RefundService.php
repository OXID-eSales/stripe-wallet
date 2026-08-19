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
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for processing Stripe refunds (full and partial).
 *
 * @since 2.0.0
 */
class RefundService implements RefundServiceInterface
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
        // Sprint 121 (STRP-129): defense-in-depth — no caller may push a
        // non-positive partial amount to the Stripe API. Null = full refund.
        if ($amount !== null && $amount <= 0.0) {
            return RefundResponse::failure('Refund amount must be greater than zero');
        }

        if ($paymentIntentId === null) {
            return RefundResponse::failure('Payment intent ID is required for refund');
        }

        $paymentIntent = $this->retrievePaymentIntent($paymentIntentId);
        $chargeId = $paymentIntent !== null ? $this->extractChargeId($paymentIntent) : null;
        if ($paymentIntent === null || $chargeId === null) {
            return RefundResponse::failure('No charge found for payment intent');
        }

        // Sprint 133 · Story 1 (F3): the PaymentIntent we just retrieved already
        // carries its currency, so the minor-unit conversion is currency-correct
        // without an extra API call. Passing '' here (the previous behaviour)
        // always assumed 2 decimals and refunded 100x on zero-decimal currencies.
        $currency = $paymentIntent->currency;
        if ($amount !== null && $currency === '') {
            $this->logger->error('Refund aborted: PaymentIntent carries no currency', [
                'payment_intent_id' => $paymentIntentId,
                'order_id' => $orderId,
            ]);

            return RefundResponse::failure(
                'Cannot convert refund amount: no currency on payment intent',
                'currency_unresolvable'
            );
        }

        $metadata = $this->buildMetadata($orderId, $initiator, $description);
        $validReason = $this->validateReason($reason);
        $amountInMinorUnits = $amount !== null
            ? AmountConverter::toMinorUnits($amount, $currency)
            : null;

        return $this->executeRefundByCharge(
            $chargeId,
            $orderId,
            $paymentIntentId,
            $validReason,
            $metadata,
            $amountInMinorUnits,
            $this->buildRequestReference($paymentIntent)
        );
    }

    public function processRefundByCharge(
        string $chargeId,
        ?string $reason = null,
        ?array $metadata = null
    ): RefundResponse {
        // Extract orderId from metadata if available
        $orderId = $metadata['order_id'] ?? null;

        // Sprint 121 (STRP-129): same enum whitelist as processRefund — the
        // by-charge path previously passed the raw string to Stripe's reason param.
        return $this->executeRefundByCharge($chargeId, $orderId, null, $this->validateReason($reason), $metadata);
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
        ?int $amountInCents = null,
        ?string $requestReference = null
    ): RefundResponse {
        try {
            $refundDto = $this->adapterFactory
                ->getStripeAdapter()
                ->createRefundByCharge($chargeId, $amountInCents, $reason, $metadata, $requestReference);

            return $this->handleRefundResponse($refundDto, $chargeId, $orderId, $paymentIntentId);
        } catch (PaymentAdapterException $e) {
            return $this->handleRefundError($e, $chargeId);
        }
    }

    /**
     * Retrieve the PaymentIntent DTO once, so callers can use every field it
     * carries (charge id AND currency) instead of discarding all but one.
     *
     * Sprint 133 · Story 1 (F3): replaces getChargeIdFromPaymentIntent(), which
     * threw away the currency and forced a 2-decimal guess downstream.
     */
    private function retrievePaymentIntent(string $paymentIntentId): ?StripePaymentIntentDto
    {
        try {
            return $this->adapterFactory
                ->getStripeAdapter()
                ->retrievePaymentIntent($paymentIntentId, ['latest_charge']);
        } catch (PaymentAdapterException $e) {
            $this->logger->error('Failed to retrieve payment intent', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Resolve the charge id from an already-retrieved PaymentIntent.
     *
     * Prefers the expanded charge object, falls back to the id-only field.
     */
    private function extractChargeId(StripePaymentIntentDto $paymentIntent): ?string
    {
        if ($paymentIntent->charge !== null) {
            return $paymentIntent->charge->id;
        }

        return $paymentIntent->latestChargeId;
    }

    /**
     * Identify THIS refund attempt by the charge's pre-refund state.
     *
     * Sprint 133 · Story 2 (F2): a retry of one admin submit sees the same
     * already-refunded total and is therefore deduplicated, while a second,
     * legitimate partial refund of the same amount sees a larger total and is
     * treated as a distinct request. Server-side truth, so no client-supplied
     * token has to be trusted, and it costs nothing: the charge is expanded on
     * the PaymentIntent retrieve we already perform.
     *
     * Returns null when the charge was not expanded — the key then falls back to
     * (payment, amount, reason), which is safe but deduplicates two identical
     * partial refunds inside the TTL.
     */
    private function buildRequestReference(StripePaymentIntentDto $paymentIntent): ?string
    {
        if ($paymentIntent->charge === null) {
            return null;
        }

        return 'refunded:' . $paymentIntent->charge->amountRefunded;
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
        if (!in_array($refund->status, [StripeStatusMapper::STRIPE_SUCCEEDED, StripeStatusMapper::STRIPE_REFUND_STATUS_PENDING], true)) {
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
