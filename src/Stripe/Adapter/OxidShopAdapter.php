<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Adapter;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;

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
            $languageId = $lang->getLanguageIdByAbbr($languageAbbr);
            return $lang->translateString($languageConstant, $languageId);
        }

        return $lang->translateString($languageConstant);
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

    public function getShopName(): string
    {
        $shop = Registry::getConfig()->getActiveShop();
        return $shop->oxshops__oxname->value ?? 'OXID eShop';
    }

    public function getShopCurrency(): string
    {
        $currency = Registry::getConfig()->getActShopCurrencyObject();
        return $currency->name ?? 'EUR';
    }

    public function isTestMode(): bool
    {
        return (bool) Registry::getConfig()->getConfigParam('blDebugMode');
    }

    public function getAdapterName(): string
    {
        return 'oxid';
    }
}
