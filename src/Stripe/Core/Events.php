<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Core;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Stripe\Service\StaticContent;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;

/**
 * Activation and deactivation handler
 *
 * Database schema is handled by Doctrine migrations in migration/data/
 * This class only handles payment method installation and cache clearing.
 */
class Events
{
    /**
     * Lists of all custom-groups to add the payment-methods to
     *
     * @var array<string>
     */
    public static $aGroupsToAdd = [
        'oxidadmin',
        'oxidcustomer',
        'oxiddealer',
        'oxidforeigncustomer',
        'oxidgoodcust',
        'oxidmiddlecust',
        'oxidnewcustomer',
        'oxidnewsletter',
        'oxidnotyetordered',
        'oxidpowershopper',
        'oxidpricea',
        'oxidpriceb',
        'oxidpricec',
        'oxidsmallcust',
    ];

    /**
     * List of all removed payment methods
     *
     * @var array<string>
     */
    public static $aRemovedPaymentMethods = [
        'stripepaypal'
    ];

    /**
     * Execute action on activate event.
     *
     * @return void
     */
    public static function onActivate(): void
    {
        self::ensureStripePaymentMethods();
        self::deleteRemovedPaymentMethods();
        self::regenerateViews();
        self::clearTmp();
    }

    /**
     * Execute action on deactivate event.
     *
     * @return void
     */
    public static function onDeactivate(): void
    {
        if (Registry::getConfig()->isAdmin()) {
            self::deactivatePaymentMethods();
            self::clearTmp();
        }
    }

    /**
     * Regenerates database view-tables.
     *
     * @return void
     */
    protected static function regenerateViews(): void
    {
        /** @var \OxidEsales\Eshop\Application\Model\Shop $oShop */
        $oShop = oxNew('oxShop');
        $oShop->generateViews();
    }

    /**
     * Clear cache.
     *
     * @return void
     */
    protected static function clearTmp(): void
    {
        shell_exec(VENDOR_PATH . 'bin/oe-console oe:cache:clear');
    }

    /**
     * Ensure all Stripe payment methods are installed using StaticContent service
     *
     * @return void
     */
    protected static function ensureStripePaymentMethods(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        /** @var \OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface $queryBuilderFactory */
        $queryBuilderFactory = $container->get(
            \OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface::class
        );

        $staticContentService = new StaticContent($queryBuilderFactory);
        $staticContentService->ensureStripePaymentMethods();
    }

    /**
     * Deletes removed payment methods
     *
     * @return void
     */
    protected static function deleteRemovedPaymentMethods(): void
    {
        foreach (self::$aRemovedPaymentMethods as $sPaymentId) {
            self::deletePaymentMethod($sPaymentId);
        }
    }

    /**
     * Deletes payment method from the database
     *
     * @param string $sPaymentId
     * @return void
     */
    protected static function deletePaymentMethod(string $sPaymentId): void
    {
        DatabaseProvider::getDb()->Execute(
            "DELETE FROM oxpayments WHERE oxid = ?",
            [$sPaymentId]
        );
    }

    /**
     * Deactivates Stripe payment methods on module deactivation
     *
     * @return void
     */
    protected static function deactivatePaymentMethods(): void
    {
        // Payment methods remain in database but can be deactivated if needed
    }
}
