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
use OxidEsales\Payments\Stripe\Service\ChargeAmountResolverInterface;
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

        // Stripe payments: skip address hash validation.
        //
        // OXID's address validation compares a hash from the form (sDeliveryAddressMD5)
        // with a server-computed hash (getEncodedDeliveryAddress). This detects address
        // changes BETWEEN page loads. For Stripe, the address hasn't changed — we're in
        // the same request or returning from Stripe redirect where the session hash was
        // restored. The validation fails for:
        // - Cyrillic/multibyte characters (encoding mismatch between JS and PHP)
        // - Stripe Checkout return flow (form hash not in GET request)
        //
        // The address was validated by OXID's standard checkout steps before reaching
        // the payment step. Re-validating during early order creation is redundant.
        if (strpos($paymentId, 'oe_payments_stripe_') === 0) {
            return 0;
        }

        // For non-Stripe payments, use standard OXID validation
        return parent::validateDeliveryAddress($oUser);
    }

    // =========================================================================
    // Stripe Amount Display (for order overview tab)
    // =========================================================================

    /**
     * Sprint 104: instance-level memoisation for the Stripe charge.
     * $chargeCacheLoaded is required because null is a valid cached value
     * (non-Stripe orders, missing transaction ID). Without the flag, every
     * null result would trigger a re-fetch on the next call.
     */
    private ?\Stripe\Charge $cachedCharge = null;
    private bool $chargeCacheLoaded = false;

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
     *
     * Sprint 103: uses the resolver so partial-capture orders where Stripe's
     * auth-release is encoded as a refund show the customer-refunded amount
     * only, not the raw Stripe charge field value.
     * Returns empty string for non-Stripe orders or no customer refunds.
     */
    public function getStripeRefundedAmount(): string
    {
        $charge = $this->getStripeCharge();
        if ($charge === null) {
            return '';
        }

        $resolver = $this->getChargeAmountResolver();
        if ($resolver === null) {
            return '';
        }

        if (!$resolver->hasCustomerRefund($charge)) {
            return '';
        }

        return $this->formatStripeAmount($resolver->customerRefundedAmount($charge));
    }

    /**
     * Check if order has any Stripe customer refunds.
     *
     * Sprint 103: delegates to the resolver so auth-releases on partial
     * captures are not counted as customer refunds.
     */
    public function hasStripeRefunds(): bool
    {
        $charge = $this->getStripeCharge();
        if ($charge === null) {
            return false;
        }

        $resolver = $this->getChargeAmountResolver();
        if ($resolver === null) {
            return false;
        }

        return $resolver->hasCustomerRefund($charge);
    }

    protected function getStripeCharge(): ?\Stripe\Charge
    {
        if ($this->chargeCacheLoaded) {
            return $this->cachedCharge;
        }
        $this->chargeCacheLoaded = true;
        $this->cachedCharge      = $this->fetchStripeCharge();
        return $this->cachedCharge;
    }

    /**
     * Sprint 104: testability seam — performs the actual Stripe API fetch.
     *
     * Separated from getStripeCharge() so testable subclasses can override
     * this method to inject a fixture charge while the memoisation in
     * getStripeCharge() remains active. This preserves the protected seam
     * that Sprint 103 tests rely on (they override getStripeCharge() directly).
     */
    protected function fetchStripeCharge(): ?\Stripe\Charge
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

    /**
     * Retrieve the charge amount resolver from the DI container (lazy, cached).
     *
     * Sprint 103: Same ContainerFactory locator pattern as getStripeCharge().
     * Class extensions cannot use constructor DI; the try/catch provides the
     * same fail-soft behaviour — if the resolver is unavailable at boot,
     * the amount methods return empty string / false rather than throwing.
     * The protected visibility is the testability seam (testable subclasses
     * override this method to inject a stub resolver).
     */
    protected function getChargeAmountResolver(): ?ChargeAmountResolverInterface
    {
        try {
            /** @var ChargeAmountResolverInterface $resolver */
            $resolver = ContainerFactory::getInstance()->getContainer()->get(
                ChargeAmountResolverInterface::class
            );
            return $resolver;
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
