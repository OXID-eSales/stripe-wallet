<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Core\ShopCurrency;
use OxidEsales\Payments\Stripe\Core\ShopName;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;

/**
 * OXID eShop specific implementation of ShopAdapterInterface.
 *
 * Provides shop-specific operations using OXID Registry and configuration.
 *
 * @since 1.0.0
 */
class OxidShopAdapter implements ShopAdapterInterface
{
    public function translateString(string $languageConstant, ?string $languageAbbr = null): string
    {
        $lang = Registry::getLang();

        if ($languageAbbr !== null) {
            $languageId = $this->getLanguageIdByAbbr($languageAbbr);
            /** @var string|array<string> $result */
            $result = $lang->translateString($languageConstant, $languageId);
            return is_array($result) ? (string) reset($result) : $result;
        }

        /** @var string|array<string> $result */
        $result = $lang->translateString($languageConstant);
        return is_array($result) ? (string) reset($result) : $result;
    }

    /**
     * Get language ID by abbreviation.
     *
     * @param string $abbr Language abbreviation (e.g., 'de', 'en')
     * @return int|null Language ID or null if not found
     */
    private function getLanguageIdByAbbr(string $abbr): ?int
    {
        $languages = Registry::getLang()->getLanguageArray();
        foreach ($languages as $language) {
            if (!is_object($language)) {
                continue;
            }
            /** @var object{abbr?: string, id?: int} $language */
            if (isset($language->abbr) && $language->abbr === $abbr) {
                return isset($language->id) ? (int) $language->id : null;
            }
        }
        return null;
    }

    public function getCurrentLanguageAbbr(): string
    {
        return Registry::getLang()->getLanguageAbbr();
    }

    public function getShopId(): string
    {
        return (string) Registry::getConfig()->getShopId();
    }

    public function getShopUrl(): string
    {
        return Registry::getConfig()->getCurrentShopUrl();
    }

    /**
     * Sprint 133 (F20): returns '' when unset rather than the literal
     * 'OXID eShop' — this value reaches Stripe as session branding, so guessing
     * it shows customers a name the merchant never chose.
     */
    public function getShopName(): string
    {
        return ShopName::of(Registry::getConfig()->getActiveShop());
    }

    /**
     * @throws \RuntimeException when the shop currency cannot be determined.
     *         Sprint 133 (F7): this used to fall back to 'EUR', which billed a
     *         CHF amount in EUR on a non-EUR shop.
     */
    public function getShopCurrency(): string
    {
        return ShopCurrency::nameOf(
            Registry::getConfig()->getActShopCurrencyObject(),
            'active shop currency'
        );
    }

    /**
     * Whether the SHOP is in test/development mode, per
     * {@see \OxidEsales\PaymentBase\Adapter\ShopAdapterInterface::isTestMode()}
     * — i.e. OXID's blDebugMode.
     *
     * Sprint 133 (F20): NOT the Stripe test/live mode. That is
     * {@see \OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface::isTestMode()},
     * which reads sStripeMode and decides which API keys are used. The review
     * called these two a contradiction; they are two different contracts that
     * unfortunately share a name. The interface method is implemented by the
     * PayPal and Mollie adapters as well, so renaming it belongs to a
     * payment-base major version — until then this docblock is the guard rail.
     */
    public function isTestMode(): bool
    {
        return (bool) Registry::getConfig()->getConfigParam('blDebugMode');
    }

    public function getAdapterName(): string
    {
        return 'oxid';
    }
}
