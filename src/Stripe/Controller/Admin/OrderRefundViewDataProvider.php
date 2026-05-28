<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Admin\StripeTransactionHistoryBuilder;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Service\ChargeAmountResolverInterface;
use OxidEsales\Payments\Stripe\Service\StripeOrderApiService;

/**
 * Provides Stripe API view data for the OrderRefund admin template.
 *
 * Sprint 46: Extracted from OrderRefund to reduce ECC.
 * Sprint 114.10b: Migrated from raw \Stripe\* to StripePaymentIntentDto / StripeChargeDto
 * (A1 boundary fix — seals Stripe SDK types inside src/Stripe/Adapter/).
 *
 * @since 2.0.0
 */
class OrderRefundViewDataProvider
{
    private ?StripePaymentIntentDto $apiOrder = null;
    private ?StripeChargeDto $apiCharge = null;
    private ?string $apiError = null;

    public function __construct(
        private readonly StripeOrderApiService $apiService,
        private readonly ChargeAmountResolverInterface $chargeAmountResolver,
        private readonly StripeTransactionHistoryBuilder $historyBuilder,
    ) {
    }

    /**
     * Get the Stripe API error message if one occurred.
     */
    public function getApiError(): ?string
    {
        return $this->apiError;
    }

    /**
     * Check if a Stripe API error occurred.
     */
    public function hasApiError(): bool
    {
        return $this->apiError !== null;
    }

    /**
     * Retrieve PaymentIntent DTO for order, with caching.
     *
     * Sprint 104: uses the expanded PI (latest_charge + refunds) as the canonical
     * source so all render-path reads share one HTTP round-trip.
     * Mutation-path callers (CaptureService, RefundService, etc.) still pass
     * refresh=true to get a fresh post-mutation state.
     */
    public function getPaymentIntent(Order $order, bool $refresh = false): ?StripePaymentIntentDto
    {
        try {
            if ($this->apiOrder === null || $refresh) {
                $this->apiOrder  = $this->fetchExpandedPaymentIntent($order);
                $this->apiCharge = null; // derive charge from new PI on next read
                if ($this->apiOrder === null) {
                    $this->apiError = 'Order has no Stripe transaction ID';
                }
            }
            return $this->apiOrder;
        } catch (\Exception $e) {
            $this->apiError = $e->getMessage();
            return null;
        }
    }

    /**
     * Retrieve last Charge DTO for order, with caching.
     *
     * Sprint 104: derives the Charge DTO from the expanded PI's charge field
     * instead of making a separate API call.
     * Sprint 114.10b: returns StripeChargeDto instead of \Stripe\Charge.
     */
    public function getLastCharge(Order $order, bool $refresh = false): ?StripeChargeDto
    {
        try {
            $paymentIntent = $this->getPaymentIntent($order, $refresh);
            if ($paymentIntent === null) {
                return null;
            }
            if ($this->apiCharge === null || $refresh) {
                $this->apiCharge = $paymentIntent->charge;
                if ($this->apiCharge === null) {
                    $this->apiError = 'PaymentIntent has no charge';
                }
            }
            return $this->apiCharge;
        } catch (\Exception $e) {
            $this->apiError = $e->getMessage();
            return null;
        }
    }

    /**
     * Check if order is in capturable state.
     */
    public function isOrderCapturable(Order $order): bool
    {
        $paymentIntent = $this->getPaymentIntent($order);
        if ($paymentIntent === null) {
            return false;
        }
        return $paymentIntent->status === StripeStatusMapper::STRIPE_REQUIRES_CAPTURE;
    }

    /**
     * Get the authorized amount that can be captured, formatted.
     */
    public function getCaptureableAmount(Order $order): string
    {
        return $this->formatPrice($this->getCaptureableRaw($order), $order);
    }

    /**
     * Get capturable amount as raw float (for input fields).
     */
    public function getCaptureableRaw(Order $order): float
    {
        $paymentIntent = $this->getPaymentIntent($order);
        if ($paymentIntent === null) {
            return 0.0;
        }
        $currency = strtoupper($paymentIntent->currency);
        return AmountConverter::toMajorUnits($paymentIntent->amount, $currency);
    }

    /**
     * Check if order is refundable based on Stripe charge data.
     *
     * Sprint 103: delegates to the resolver so partial-capture orders where
     * Stripe's auth-release is encoded as a refund are not incorrectly treated
     * as fully-refunded when the customer has not yet been refunded.
     * Sprint 104: removed refresh=true — render-path reads the cached charge.
     */
    public function isOrderRefundable(Order $order): bool
    {
        $charge = $this->getLastCharge($order);
        if ($charge === null) {
            return false;
        }

        return $this->chargeAmountResolver->availableForRefund($charge) > 0.0;
    }

    /**
     * Get remaining refundable amount, formatted.
     */
    public function getRemainingRefundableAmount(Order $order): string
    {
        return $this->formatPrice($this->getRemainingRefundableRaw($order), $order);
    }

    /**
     * Get remaining refundable amount as raw float (for input fields).
     *
     * Sprint 103: delegates to the resolver so partial-capture orders
     * return the correct available-for-refund value — the auth-release
     * encoded by Stripe on partial capture is excluded from the customer total.
     * Sprint 104: removed refresh=true — render-path reads the cached charge.
     */
    public function getRemainingRefundableRaw(Order $order): float
    {
        $charge = $this->getLastCharge($order);
        if ($charge === null) {
            return 0.0;
        }

        return $this->chargeAmountResolver->availableForRefund($charge);
    }

    /**
     * Get total captured amount, formatted.
     */
    public function getStripeCapturedAmount(Order $order): string
    {
        $charge = $this->getLastCharge($order, false);
        if ($charge === null || $charge->amountCaptured === 0) {
            return $this->formatPrice(0.0, $order);
        }
        $chargeCurrency = strtoupper($charge->currency);
        $price = AmountConverter::toMajorUnits($charge->amountCaptured, $chargeCurrency);
        return $this->formatPrice($price, $order);
    }

    /**
     * Format a price using order's currency.
     */
    public function formatPrice(float $price, Order $order): string
    {
        /** @phpstan-ignore-next-line OXID core: magic property oxorder__oxcurrency->value */
        $currency = Registry::getConfig()->getCurrencyObject($order->oxorder__oxcurrency->value);
        return Registry::getLang()->formatCurrency($price, $currency);
    }

    /**
     * Build transaction history from Stripe API (source of truth).
     *
     * Covers all actions regardless of origin (admin, Stripe Dashboard, webhook).
     * Uses expanded PaymentIntent to include refunds (Stripe SDK v19+: Charge.refunds removed).
     * Sprint 104: reads from the shared expanded-PI cache (populated by getPaymentIntent).
     * Sprint 114.10b: reads StripePaymentIntentDto / StripeChargeDto / StripeRefundDto.
     * Sprint 114.11b (S4): history assembly delegated to StripeTransactionHistoryBuilder.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStripeTransactionHistory(Order $order): array
    {
        $paymentIntent = $this->getPaymentIntent($order);
        if ($paymentIntent === null) {
            return [];
        }

        return $this->historyBuilder->build($paymentIntent);
    }

    /**
     * Reset cached API data (after capture/cancel/refund).
     */
    public function resetCache(): void
    {
        $this->apiOrder  = null;
        $this->apiCharge = null;
        $this->apiError  = null;
    }

    /**
     * Sprint 104: testability seam — fetches the expanded PaymentIntent DTO.
     *
     * Separated from getPaymentIntent() so tests can override this single
     * method to count HTTP calls without mocking the final StripeOrderApiService.
     * All render-path reads flow through this one entry point.
     *
     * Sprint 114.10b: return type changed from ?PaymentIntent to ?StripePaymentIntentDto.
     */
    protected function fetchExpandedPaymentIntent(Order $order): ?StripePaymentIntentDto
    {
        return $this->apiService->getPaymentIntentWithRefunds($order);
    }
}
