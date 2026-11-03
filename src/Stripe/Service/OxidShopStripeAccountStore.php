<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Module;

/**
 * The shop's Stripe account id in the module-namespaced oxconfig variables `sStripeShopAccountIdTest` and
 * `sStripeShopAccountIdLive`: internal state, like the per-mode webhook endpoint id, never a field of the settings form.
 */
final class OxidShopStripeAccountStore implements ShopStripeAccountStoreInterface
{
    private const MODULE_NAMESPACE = 'module:' . Module::MODULE_ID;

    public function get(string $mode): string
    {
        // getShopConfVar() is typed object by OXID's PHPDoc but returns what was stored.
        /** @var mixed $value */
        $value = Registry::getConfig()->getShopConfVar($this->variable($mode), null, self::MODULE_NAMESPACE);

        return is_string($value) ? $value : '';
    }

    public function save(string $mode, string $accountId): void
    {
        Registry::getConfig()->saveShopConfVar('str', $this->variable($mode), $accountId, null, self::MODULE_NAMESPACE);
    }

    private function variable(string $mode): string
    {
        return $mode === StripeDefinitions::MODE_LIVE ? 'sStripeShopAccountIdLive' : 'sStripeShopAccountIdTest';
    }
}
