<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Adapter;

use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Component\Adapter\ShopAdapterInterface;

/**
 * OXID eShop adapter for shop-specific operations.
 *
 * Implements ShopAdapterInterface for OXID eShop platform.
 * Wraps OXID-specific Registry calls to provide clean, testable interface.
 *
 * @since 1.0.0
 */
final class OxidShopAdapter implements ShopAdapterInterface
{
    /**
     * @inheritDoc
     */
    public function translateString(string $languageConstant, ?string $languageAbbr = null): string
    {
        $language = Registry::getLang();

        if ($languageAbbr !== null) {
            // Get language ID for abbreviation
            $languages = $language->getLanguageArray();
            foreach ($languages as $lang) {
                if ($lang->abbr === $languageAbbr) {
                    return $language->translateString($languageConstant, $lang->id, false);
                }
            }
        }

        // Use current language
        return $language->translateString($languageConstant, null, false);
    }

    /**
     * @inheritDoc
     */
    public function getCurrentLanguageAbbr(): string
    {
        return Registry::getLang()->getLanguageAbbr();
    }

    /**
     * @inheritDoc
     */
    public function getShopId(): string
    {
        return (string) Registry::getConfig()->getShopId();
    }

    /**
     * @inheritDoc
     */
    public function getShopUrl(): string
    {
        return Registry::getConfig()->getShopCurrentURL();
    }

    /**
     * @inheritDoc
     */
    public function getShopName(): string
    {
        return Registry::getConfig()->getActiveShop()->getFieldData('oxname') ?? 'OXID eShop';
    }

    /**
     * @inheritDoc
     */
    public function getShopCurrency(): string
    {
        $currency = Registry::getConfig()->getActShopCurrencyObject();
        return $currency->name ?? 'EUR';
    }

    /**
     * @inheritDoc
     */
    public function isTestMode(): bool
    {
        // Check if OXID is in development/productive mode
        // In OXID, productive mode = false means test/development
        return !Registry::getConfig()->isProductiveMode();
    }

    /**
     * @inheritDoc
     */
    public function getAdapterName(): string
    {
        return 'oxid';
    }
}
