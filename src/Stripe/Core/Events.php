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
        self::registerPaymentHandler();
        self::registerOnePageCheckoutFooterWidget();
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
     * Register Stripe payment handler in One-Page Checkout registry
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function registerPaymentHandler(): void
    {
        try {
            $container = ContainerFactory::getInstance()->getContainer();

            // Check if PaymentHandlerRegistry exists (one-page checkout module installed)
            if (!$container->has(\OxidEsales\OnePageCheckout\Service\PaymentHandlerRegistry::class)) {
                return;
            }

            /** @var \OxidEsales\OnePageCheckout\Service\PaymentHandlerRegistry $registry */
            $registry = $container->get(\OxidEsales\OnePageCheckout\Service\PaymentHandlerRegistry::class);

            /** @var \OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler $handler */
            $handler = $container->get(\OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler::class);

            $registry->registerHandler($handler);

            Registry::getLogger()->info('[StripeModule] Payment handler registered in one-page checkout');
        } catch (\Exception $e) {
            // One-page checkout module not installed - silently ignore
            Registry::getLogger()->debug('[StripeModule] Could not register payment handler: ' . $e->getMessage());
        }
    }

    /**
     * Register Stripe footer widget for One-Page Checkout module
     *
     * IMPORTANT: This registers a custom footer widget ONLY for the One-Page Checkout
     * module (used in Buy Now modal and checkout flows), NOT the global shop footer/layout.
     *
     * Context:
     * - Widget appears in the Buy Now modal footer (modal-footer section)
     * - Widget appears in full-page checkout footer (checkout footer section)
     * - Widget is NOT related to any specific URL - it's rendered dynamically in modals/checkout UI
     * - Widget activation is triggered by payment method selection ('oxidstripe')
     *
     * The widget replaces the standard checkout footer (terms + submit button) with
     * a Stripe-branded version when user selects 'oxidstripe' payment method.
     *
     * Widget features:
     * - Custom terms checkbox with Stripe Consumer Terms and PCI disclaimers
     * - Custom submit button with Stripe branding and purple gradient
     * - Loading states with full-screen overlay during payment processing
     * - Error handling with user-friendly messages
     * - EventBus integration for state synchronization with checkout
     *
     * Technical details:
     * - Registry: \OxidEsales\OnePageCheckout\Service\FooterWidgetRegistry
     * - Widget Class: \OxidEsales\Payments\Stripe\Component\Widget\StripeCheckoutFooter
     * - Payment Method ID: 'oxidstripe'
     * - Widget Controller Name: 'stripecheckoutfooter'
     * - Render Context: Modal footer slot, Checkout footer slot
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @see \OxidEsales\Payments\Stripe\Component\Widget\StripeCheckoutFooter
     * @see docs/FOOTER_WIDGET_ARCHITECTURE.md in one-page-checkout module
     */
    protected static function registerOnePageCheckoutFooterWidget(): void
    {
        try {
            $container = ContainerFactory::getInstance()->getContainer();

            // Check if FooterWidgetRegistry exists (requires one-page-checkout module with footer widget support)
            if (!$container->has(\OxidEsales\OnePageCheckout\Service\FooterWidgetRegistry::class)) {
                Registry::getLogger()->debug(
                    '[StripeModule] One-Page Checkout FooterWidgetRegistry not available - ' .
                    'checkout footer widget registration skipped. This is expected if one-page-checkout ' .
                    'module is not installed or does not support footer widgets yet.'
                );
                return;
            }

            /** @var \OxidEsales\OnePageCheckout\Service\FooterWidgetRegistry $registry */
            $registry = $container->get(\OxidEsales\OnePageCheckout\Service\FooterWidgetRegistry::class);

            // Register Stripe footer widget for 'oe_payments_stripe_wallet' payment method
            // Widget renders in: Buy Now modal footer, checkout page footer (not global shop footer!)
            // Activation: When user selects Stripe as payment method
            // NOTE: Payment method ID is the module ID (oe_payments_stripe_wallet), not 'oxidstripe'
            // NOTE: Using full class name instead of controller ID for cross-module compatibility
            $registry->registerWidget(
                'oe_payments_stripe_wallet',
                \OxidEsales\Payments\Stripe\Component\Widget\StripeCheckoutFooter::class
            );

            Registry::getLogger()->info(
                '[StripeModule] One-Page Checkout footer widget registered successfully. ' .
                'Payment method: oe_payments_stripe_wallet, Widget: StripeCheckoutFooter. ' .
                'Widget will render in Buy Now modal and checkout page footers when Stripe is selected.'
            );
        } catch (\Exception $e) {
            // FooterWidgetRegistry not available - log and continue without failing
            Registry::getLogger()->debug(
                '[StripeModule] Could not register One-Page Checkout footer widget: ' .
                $e->getMessage() . '. This does not affect standard checkout functionality.'
            );
        }
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
        } catch (Exception) {
            // do nothing
        }
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
