<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Core\ShopCurrency;
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

    public function getShopName(): string
    {
        $shop = Registry::getConfig()->getActiveShop();
        /** @phpstan-ignore-next-line OXID core: magic property oxshops__oxname->value */
        return $shop->oxshops__oxname->value ?? 'OXID eShop';
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

    public function isTestMode(): bool
    {
        return (bool) Registry::getConfig()->getConfigParam('blDebugMode');
    }

    public function getAdapterName(): string
    {
        return 'oxid';
    }
}
