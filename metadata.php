<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

use OxidEsales\Eshop\Application\Controller\Admin\ModuleConfiguration;
use OxidEsales\Eshop\Application\Controller\OrderController;
use OxidEsales\Eshop\Application\Controller\PaymentController;
use OxidEsales\Eshop\Application\Model\Order as CoreOrder;
use OxidEsales\Eshop\Application\Model\Payment as CorePayment;
use OxidEsales\Eshop\Core\ViewConfig;
use OxidEsales\Payments\Stripe\Controller\Admin\ModuleConfiguration as StripeModuleConfiguration;
use OxidEsales\Payments\Stripe\Controller\Admin\StripeConnect;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController as StripeOrderController;
use OxidEsales\Payments\Stripe\Controller\PaymentController as StripePaymentController;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController as StripeWebhookController;
use OxidEsales\Payments\Stripe\Core\Events as StripeEvents;
use OxidEsales\Payments\Stripe\Core\ViewConfig as StripeViewConfig;
use OxidEsales\Payments\Stripe\Model\Order as StripeOrderModel;
use OxidEsales\Payments\Stripe\Model\Payment as StripePaymentModel;
use OxidEsales\Payments\Stripe\Component\Widget\StripeCheckoutFooter;
use OxidEsales\Payments\Stripe\Module;

/**
 * Metadata version
 */
$sMetadataVersion = '2.1';

/**
 * Module information
 */
$aModule = [
    'id' => Module::MODULE_ID,
    'title' => [
        'de' => 'Stripe Wallet',
        'en' => 'Stripe Wallet',
    ],
    'description' => [
        'de' => 'Stripe-Zahlungsintegration mit Smart Contracts für OXID eShop 7',
        'en' => 'Stripe payment integration with Smart Contracts for OXID eShop 7',
    ],
    'thumbnail' => 'img/logo.png',
    'version' => '3.3.0',
    'author' => 'OXID eSales AG',
    'url' => 'https://www.oxid-esales.com',
    'email' => 'info@oxid-esales.com',
    'extend' => [
        ModuleConfiguration::class => StripeModuleConfiguration::class,
        ViewConfig::class => StripeViewConfig::class,
        CorePayment::class => StripePaymentModel::class,
        CoreOrder::class => StripeOrderModel::class,
        PaymentController::class => StripePaymentController::class,
        OrderController::class => StripeOrderController::class,
    ],
    'controllers' => [
        'StripeWebhookController' => StripeWebhookController::class,
        'StripeConnect' => StripeConnect::class,
        'stripecheckoutfooter' => StripeCheckoutFooter::class,
    ],
    'events' => [
        'onActivate' => StripeEvents::class . '::onActivate',
        'onDeactivate' => StripeEvents::class . '::onDeactivate',
    ],
    'settings'      => [
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeMode',                        'type' => 'select',     'value' => 'test',      'position' => 10, 'constraints' => 'live|test'],
        // Sprint 114: test credentials moved into their own group for visual separation from
        // general checkboxes / capture mode.
        ['group' => 'STRIPE_TEST_CONFIG',       'name' => 'sStripeTestToken',                   'type' => 'str',        'value' => '',          'position' => 20],
        ['group' => 'STRIPE_TEST_CONFIG',       'name' => 'sStripeTestPk',                      'type' => 'str',        'value' => '',          'position' => 21],
        // Sprint 110: platform secret key for registering Connect webhooks (paste from Stripe Dashboard → Developers → API keys).
        // Distinct from sStripeTestToken (connected-account access_token from OAuth).
        ['group' => 'STRIPE_TEST_CONFIG',       'name' => 'sStripeTestKey',                     'type' => 'str',        'value' => '',          'position' => 22],
        // Sprint 114: live credentials in their own group.
        ['group' => 'STRIPE_LIVE_CONFIG',       'name' => 'sStripeLiveToken',                   'type' => 'str',        'value' => '',          'position' => 30],
        ['group' => 'STRIPE_LIVE_CONFIG',       'name' => 'sStripeLivePk',                      'type' => 'str',        'value' => '',          'position' => 31],
        // Sprint 110: platform secret key for registering Connect webhooks (paste from Stripe Dashboard → Developers → API keys).
        // Distinct from sStripeLiveToken (connected-account access_token from OAuth).
        ['group' => 'STRIPE_LIVE_CONFIG',       'name' => 'sStripeLiveKey',                     'type' => 'str',        'value' => '',          'position' => 32],
        ['group' => 'STRIPE_GENERAL',           'name' => 'blStripeProvideCustomerEmailAddress','type' => 'bool',       'value' => '0',         'position' => 37],
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeCaptureMode',                 'type' => 'select',     'value' => 'automatic', 'position' => 39, 'constraints' => 'automatic|manual'],
        ['group' => 'STRIPE_WEBHOOKS',          'name' => 'sStripeWebhookEndpoint',             'type' => 'str',        'value' => '',          'position' => 130],
        // VR-26 S1: not read and not shown any more; kept only so a value set before this release survives until
        // activation moves it into the per-mode key (LegacyWebhookSecretMigration). Remove once every shop has activated.
        ['group' => 'STRIPE_WEBHOOKS',          'name' => 'sStripeWebhookEndpointSecret',       'type' => 'str',        'value' => '',          'position' => 140],
        // Sprint 109/111: per-mode endpoint ID and signing secret are stored in oxconfig
        // (via saveShopConfVar with module: prefix) — NOT as module settings — so they do
        // not surface as editable form fields in the module_config admin view.

        // Logging controls (the former blStripeLogTransactionInfo toggle is gone).
        //
        // sStripeLogLevel options map to channels as follows (Phase 3 wires this):
        //   off    → all channels use NullFileLogger (no writes)
        //   errors → request channel logs exceptions only
        //   normal → requests (full) + reconciliation
        //   debug  → all channels including events + frontend console
        //
        // blStripeLogWebhooks is independent of the level so merchants can silence
        // chatty webhook traffic without going dark on other channels.
        ['group' => 'STRIPE_LOGGING',           'name' => 'sStripeLogLevel',                    'type' => 'select',     'value' => 'normal',    'position' => 200, 'constraints' => 'off|errors|normal|debug'],
        ['group' => 'STRIPE_LOGGING',           'name' => 'blStripeLogWebhooks',                'type' => 'bool',       'value' => '1',         'position' => 210],
    ]
];
