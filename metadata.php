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
use OxidEsales\Payments\Stripe\Application\Controller\Admin\ModuleConfiguration as StripeModuleConfiguration;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefund;
use OxidEsales\Payments\Stripe\Controller\Admin\StripeConnect;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController as StripeOrderController;
use OxidEsales\Payments\Stripe\Controller\PaymentController as StripePaymentController;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController as StripeWebhookController;
use OxidEsales\Payments\Stripe\Core\Events as StripeEvents;
use OxidEsales\Payments\Stripe\Core\ViewConfig as StripeViewConfig;
use OxidEsales\Payments\Stripe\Model\Order as StripeOrderModel;
use OxidEsales\Payments\Stripe\Model\Payment as StripePaymentModel;
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
        'de' => 'Stripe Payment Gateway',
        'en' => 'Stripe Payment Gateway',
    ],
    'description' => [
        'de' => 'Stripe-Zahlungsintegration mit Smart Contracts für OXID eShop 7',
        'en' => 'Stripe payment integration with Smart Contracts for OXID eShop 7',
    ],
    'thumbnail' => 'img/stripe_logo.png',
    'version' => '1.0.0',
    'author' => 'OXID Solution Catalysts',
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
        'osc_stripe_payment' => StripePaymentController::class,
        'osc_stripe_webhook' => StripeWebhookController::class,
        'StripeConnect' => StripeConnect::class,
        'stripe_order_refund' => OrderRefund::class,
        'orderController' => StripeOrderController::class,
    ],
    'events' => [
        'onActivate' => StripeEvents::class . '::onActivate',
        'onDeactivate' => StripeEvents::class . '::onDeactivate',
    ],
    'templates' => [
        '@oe_payments_stripe_wallet/admin/stripe_connect' => 'views/twig/admin/stripe_connect.html.twig',
        '@oe_payments_stripe_wallet/admin/stripe_order' => 'views/twig/admin/stripe_order_refund.html.twig',
    ],
    'settings'      => [
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeDevMode',                     'type' => 'bool',       'value' => '0',         'position' => 5],
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeMode',                        'type' => 'select',     'value' => 'test',      'position' => 10, 'constraints' => 'live|test'],
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeTestToken',                   'type' => 'str',        'value' => '',          'position' => 20],
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeTestPk',                      'type' => 'str',        'value' => '',          'position' => 21],
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeLiveToken',                   'type' => 'str',        'value' => '',          'position' => 30],
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeLivePk',                      'type' => 'str',        'value' => '',          'position' => 31],
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeTestKey',                     'type' => 'str',        'value' => '',          'position' => 32],
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeLiveKey',                     'type' => 'str',        'value' => '',          'position' => 33],
        ['group' => 'STRIPE_GENERAL',           'name' => 'blStripeLogTransactionInfo',         'type' => 'bool',       'value' => '1',         'position' => 34],
        ['group' => 'STRIPE_GENERAL',           'name' => 'blStripeRemoveByBillingCountry',     'type' => 'bool',       'value' => '1',         'position' => 35],
        ['group' => 'STRIPE_GENERAL',           'name' => 'blStripeRemoveByBasketCurrency',     'type' => 'bool',       'value' => '1',         'position' => 36],
        ['group' => 'STRIPE_GENERAL',           'name' => 'blStripeProvideCustomerEmailAddress','type' => 'bool',       'value' => '0',         'position' => 37],
        ['group' => 'STRIPE_GENERAL',           'name' => 'blStripeUseStructuredCustomId',      'type' => 'bool',       'value' => '0',         'position' => 38],
        ['group' => 'STRIPE_GENERAL',           'name' => 'sStripeCaptureMode',                 'type' => 'select',     'value' => 'automatic', 'position' => 39, 'constraints' => 'automatic|manual'],
        ['group' => 'STRIPE_STATUS_MAPPING',    'name' => 'sStripeStatusPending',               'type' => 'select',     'value' => '',          'position' => 50],
        ['group' => 'STRIPE_STATUS_MAPPING',    'name' => 'sStripeStatusProcessing',            'type' => 'select',     'value' => '',          'position' => 60],
        ['group' => 'STRIPE_STATUS_MAPPING',    'name' => 'sStripeStatusCancelled',             'type' => 'select',     'value' => '',          'position' => 70],
        ['group' => 'STRIPE_CRONJOBS',          'name' => 'sStripeCronFinishOrdersActive',      'type' => 'bool',       'value' => '0',         'position' => 80],
        ['group' => 'STRIPE_CRONJOBS',          'name' => 'sStripeCronSecondChanceActive',      'type' => 'bool',       'value' => '0',         'position' => 90],
        ['group' => 'STRIPE_CRONJOBS',          'name' => 'iStripeCronSecondChanceTimeDiff',    'type' => 'select',     'value' => '1',         'position' => 100],
        ['group' => 'STRIPE_CRONJOBS',          'name' => 'sStripeCronOrderShipmentActive',     'type' => 'bool',       'value' => '0',         'position' => 110],
        ['group' => 'STRIPE_CRONJOBS',          'name' => 'sStripeCronSecureKey',               'type' => 'str',        'value' => '',          'position' => 120],
        ['group' => 'STRIPE_WEBHOOKS',          'name' => 'sStripeWebhookEndpoint',             'type' => 'str',        'value' => '',          'position' => 130],
        ['group' => 'STRIPE_WEBHOOKS',          'name' => 'sStripeWebhookEndpointSecret',       'type' => 'str',        'value' => '',          'position' => 140],
    ]
];
