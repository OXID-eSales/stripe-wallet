<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Service\StripeOrderApiService;
use Stripe\Charge;
use Stripe\PaymentIntent;

/**
 * Provides Stripe API view data for the OrderRefund admin template.
 *
 * Sprint 46: Extracted from OrderRefund to reduce ECC.
 *
 * @since 2.0.0
 */
class OrderRefundViewDataProvider
{
    private ?PaymentIntent $apiOrder = null;
    private ?Charge $apiCharge = null;
    private ?string $apiError = null;

    public function __construct(
        private readonly StripeOrderApiService $apiService
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
     * Retrieve PaymentIntent for order, with caching.
     */
    public function getPaymentIntent(Order $order, bool $refresh = false): ?PaymentIntent
    {
        try {
            if ($this->apiOrder === null || $refresh) {
                $this->apiOrder = $this->apiService->getPaymentIntent($order);
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
     * Retrieve last Charge for order, with caching.
     */
    public function getLastCharge(Order $order, bool $refresh = false): ?Charge
    {
        try {
            if ($this->apiCharge === null || $refresh) {
                $paymentIntent = $this->getPaymentIntent($order, $refresh);
                if ($paymentIntent === null) {
                    return null;
                }
                $this->apiCharge = $this->apiService->getLastCharge($paymentIntent);
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
        return ($paymentIntent->status ?? '') === 'requires_capture';
    }

    /**
     * Get the authorized amount that can be captured, formatted.
     */
    public function getCaptureableAmount(Order $order): string
    {
        $paymentIntent = $this->getPaymentIntent($order);
        if ($paymentIntent === null) {
            return $this->formatPrice(0, $order);
        }
        $amount = (int) ($paymentIntent->amount ?? 0);
        return $this->formatPrice($amount / 100, $order);
    }

    /**
     * Check if order is refundable based on Stripe charge data.
     */
    public function isOrderRefundable(Order $order): bool
    {
        $charge = $this->getLastCharge($order, true);
        if ($charge === null) {
            return false;
        }

        $amountRefunded = $charge->amount_refunded ?? 0;
        $amount = $charge->amount ?? 0;

        return empty($amountRefunded) || $amountRefunded != $amount;
    }

    /**
     * Get remaining refundable amount, formatted.
     */
    public function getRemainingRefundableAmount(Order $order): string
    {
        $charge = $this->getLastCharge($order, true);
        $price = 0;
        if ($charge && !empty($charge->amount_captured)) {
            $price = ($charge->amount_captured - ($charge->amount_refunded ?? 0)) / 100;
        }
        return $this->formatPrice($price, $order);
    }

    /**
     * Get total captured amount, formatted.
     */
    public function getStripeCapturedAmount(Order $order): string
    {
        $charge = $this->getLastCharge($order, false);
        $price = 0;
        if ($charge && !empty($charge->amount_captured)) {
            $price = $charge->amount_captured / 100;
        }
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
     * Reset cached API data (after capture/cancel/refund).
     */
    public function resetCache(): void
    {
        $this->apiOrder = null;
        $this->apiCharge = null;
        $this->apiError = null;
    }
}
