<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Core;

use Exception;
use OxidEsales\Eshop\Application\Model\Shop;
use OxidEsales\Eshop\Core\Exception\DatabaseConnectionException;
use OxidEsales\Eshop\Core\Exception\DatabaseErrorException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StaticContent;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

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
    public static array $aGroupsToAdd = [
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
    public static array $aRemovedPaymentMethods = [
        'stripepaypal'
    ];

    /**
     * Execute action on activate event.
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DatabaseConnectionException
     * @throws DatabaseErrorException
     * @throws NotFoundExceptionInterface
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
    /**
     * Sprint 133 · Story 18 (F18): the body used to sit behind isAdmin(), so
     * `oe-console oe:module:deactivate` — the documented CLI path — skipped the
     * file-cache reset and left stale templates and config behind.
     *
     * Stripe payment methods intentionally survive deactivation: activation's
     * ensureStripePaymentMethods() deliberately leaves `oxactive` untouched to
     * preserve admin changes, so switching them off here would silently keep
     * them off after the next activate. The former deactivatePaymentMethods()
     * had an empty body under a name that promised otherwise, and is gone.
     */
    public static function onDeactivate(): void
    {
        self::clearTmp();
    }

    /**
     * Regenerates database view-tables.
     *
     * @return void
     */
    protected static function regenerateViews(): void
    {
        /** @var Shop $oShop */
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
        Registry::getUtils()->oxResetFileCache();
    }

    /**
     * Ensure all Stripe payment methods are installed using StaticContent service
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function ensureStripePaymentMethods(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        /** @var QueryBuilderFactoryInterface $queryBuilderFactory */
        $queryBuilderFactory = $container->get(
            QueryBuilderFactoryInterface::class
        );

        $staticContentService = new StaticContent($queryBuilderFactory);
        $staticContentService->ensureStripePaymentMethods();
    }

    /**
     * Deletes removed payment methods
     *
     * @return void
     * @throws DatabaseConnectionException
     * @throws DatabaseErrorException
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
        try {
            // Use OXID's DatabaseProvider for database operations
            $db = \OxidEsales\Eshop\Core\DatabaseProvider::getDb();
            $db->execute(
                'DELETE FROM oxpayments WHERE oxid = ?',
                [$sPaymentId]
            );
        } catch (Exception $e) {
            // Sprint 133 · Story 18 (F18): this swallowed everything, so a
            // failed cleanup left the removed payment method in oxpayments and
            // visible in admin with no trace of why.
            Registry::getLogger()->error('Failed to delete a removed Stripe payment method', [
                'payment_id' => $sPaymentId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
