<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Model;

use OxidEsales\Eshop\Core\Counter as EshopCoreCounter;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\StripeOrderApiService;

/**
 * Stripe Order Model Extension
 *
 * Extends OXID Order model to ensure proper order number generation
 * for Stripe payments, following the same pattern as PayPal module.
 *
 * Also handles address validation for Stripe Checkout return flow,
 * where the address hash needs to be read from session instead of
 * request parameters.
 *
 * @mixin \OxidEsales\Eshop\Application\Model\Order
 */
class Order extends Order_parent
{
    /**
     * Ensure order number is always set.
     *
     * This override is necessary because the core setOrderNumber() method
     * may not always generate a number depending on order state.
     *
     * Pattern borrowed from PayPal module for consistency across payment modules.
     *
     * @return void
     */
    public function setOrderNumber(): void
    {
        if (!$this->hasOrderNumber()) {
            $this->setNumber();
            return;
        }

        /** @var EshopCoreCounter $counter */
        $counter = oxNew(EshopCoreCounter::class);
        $orderNr = $this->getFieldData('oxordernr');
        $counter->update($this->getCounterIdent(), is_numeric($orderNr) ? (int) $orderNr : 0);
    }

    /**
     * Check if order already has a valid order number.
     *
     * @return bool
     */
    public function hasOrderNumber(): bool
    {
        $orderNr = $this->getFieldData('oxordernr');
        return 0 < (is_numeric($orderNr) ? (int) $orderNr : 0);
    }

    /**
     * Override payment execution for Stripe payments.
     *
     * Stripe payments are handled separately via the Stripe SDK/API,
     * so we bypass the standard OXID payment gateway execution.
     * This allows finalizeOrder() to work normally without triggering
     * the standard payment gateway which would fail for Stripe payments.
     *
     * @param \OxidEsales\Eshop\Application\Model\Basket $oBasket Shopping basket
     * @param object $oUserpayment User payment object
     * @return bool|int Always returns true for Stripe payments to allow order finalization
     */
    protected function executePayment(\OxidEsales\Eshop\Application\Model\Basket $oBasket, $oUserpayment)
    {
        // Check if this is a Stripe payment
        $paymentId = $oBasket->getPaymentId();

        if (strpos($paymentId, 'oe_payments_stripe_') === 0) {
            // Stripe payment - skip standard OXID payment execution
            // Payment is handled separately via Stripe SDK in OrderController
            Registry::getLogger()->debug('Stripe payment detected, skipping standard payment execution', [
                'payment_id' => $paymentId,
                'order_id' => $this->getId()
            ]);

            return true; // Return success to continue order finalization
        }

        // For non-Stripe payments, use standard OXID payment execution
        return parent::executePayment($oBasket, $oUserpayment);
    }

    /**
     * Validate delivery address for Stripe Checkout return flow.
     *
     * When returning from Stripe Checkout (GET redirect), the standard
     * 'sDeliveryAddressMD5' request parameter is not available because
     * there's no form submission. For Stripe payments, we fall back to
     * the session variable 'sDelAddrMD5' which was restored from the
     * contract metadata by StripeCheckoutReturnHandler.
     *
     * @param \OxidEsales\Eshop\Application\Model\User $oUser User object
     * @return int Validation state (0 = OK, 7 = address changed)
     */
    public function validateDeliveryAddress($oUser): int
    {
        // Get basket to check payment type
        $oBasket = Registry::getSession()->getBasket();
        $paymentId = '';
        if ($oBasket !== null) {
            $paymentId = $oBasket->getPaymentId();
        }

        // Check if this is a Stripe payment
        if (strpos($paymentId, 'oe_payments_stripe_') === 0) {
            // Get hash from request first (standard OXID behavior)
            $sDelAddressMD5 = Registry::getRequest()->getRequestEscapedParameter('sDeliveryAddressMD5');

            // If not in request, try session (Stripe Checkout return flow)
            if (empty($sDelAddressMD5)) {
                $sDelAddressMD5 = Registry::getSession()->getVariable('sDelAddrMD5');

                Registry::getLogger()->debug('Stripe: Using session hash for address validation', [
                    'payment_id' => $paymentId,
                    'session_hash' => $sDelAddressMD5,
                ]);
            }

            // If we still don't have a hash, skip validation for Stripe
            // This handles edge cases where the hash couldn't be stored/restored
            if (empty($sDelAddressMD5)) {
                Registry::getLogger()->warning('Stripe: No address hash available, skipping validation', [
                    'payment_id' => $paymentId,
                    'order_id' => $this->getId(),
                ]);
                return 0; // OK - allow order to proceed
            }

            // Compute current address hash (same as parent)
            $sDeliveryAddress = $oUser->getEncodedDeliveryAddress();

            /** @var \OxidEsales\Eshop\Application\Model\Address|null $oDeliveryAddress */
            $oDeliveryAddress = $this->getDelAddressInfo();
            if ($oDeliveryAddress) {
                $sDeliveryAddress .= $oDeliveryAddress->getEncodedDeliveryAddress();
            }

            // Compare hashes
            if ($sDelAddressMD5 !== $sDeliveryAddress) {
                Registry::getLogger()->error('Stripe: Address hash mismatch', [
                    'payment_id' => $paymentId,
                    'stored_hash' => $sDelAddressMD5,
                    'computed_hash' => $sDeliveryAddress,
                ]);
                return self::ORDER_STATE_INVALIDDELADDRESSCHANGED;
            }

            return 0; // OK
        }

        // For non-Stripe payments, use standard OXID validation
        return parent::validateDeliveryAddress($oUser);
    }

    // =========================================================================
    // Stripe Amount Display (for order overview tab)
    // =========================================================================

    /**
     * Get factual captured amount from Stripe, formatted.
     * Returns empty string for non-Stripe orders.
     */
    public function getStripeCapturedAmount(): string
    {
        $charge = $this->getStripeCharge();
        if ($charge === null) {
            return '';
        }

        $amount = ((int) ($charge->amount_captured ?? 0)) / 100;
        return $this->formatStripeAmount($amount);
    }

    /**
     * Get refunded amount from Stripe, formatted.
     * Returns empty string for non-Stripe orders or no refunds.
     */
    public function getStripeRefundedAmount(): string
    {
        $charge = $this->getStripeCharge();
        if ($charge === null) {
            return '';
        }

        $refunded = (int) ($charge->amount_refunded ?? 0);
        if ($refunded <= 0) {
            return '';
        }

        return $this->formatStripeAmount($refunded / 100);
    }

    /**
     * Check if order has any Stripe refunds.
     */
    public function hasStripeRefunds(): bool
    {
        $charge = $this->getStripeCharge();
        if ($charge === null) {
            return false;
        }

        return ((int) ($charge->amount_refunded ?? 0)) > 0;
    }

    private function getStripeCharge(): ?\Stripe\Charge
    {
        /** @phpstan-ignore-next-line OXID core: magic property */
        $paymentType = (string) ($this->oxorder__oxpaymenttype->value ?? '');
        if (!StripeDefinitions::isStripePayment($paymentType)) {
            return null;
        }

        try {
            /** @var StripeOrderApiService $apiService */
            $apiService = ContainerFactory::getInstance()->getContainer()->get(StripeOrderApiService::class);
            /** @phpstan-ignore-next-line OXID core: virtual parent — $this is Order extension */
            $paymentIntent = $apiService->getPaymentIntent($this);
            if ($paymentIntent === null) {
                return null;
            }
            return $apiService->getLastCharge($paymentIntent);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function formatStripeAmount(float $amount): string
    {
        /** @phpstan-ignore-next-line OXID core: magic property */
        $currencyName = (string) ($this->oxorder__oxcurrency->value ?? '');
        $currency = Registry::getConfig()->getCurrencyObject($currencyName);

        return Registry::getLang()->formatCurrency($amount, $currency);
    }
}
