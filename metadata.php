<?php
/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

use OxidSolutionCatalysts\Payments\Component\Controller\Http\WebhookController;
use OxidSolutionCatalysts\Payments\Component\Controller\Http\PaymentController;
use OxidSolutionCatalysts\Payments\Watch\Controller\AssumptionController;
use OxidSolutionCatalysts\Payments\Stripe\Core\Events;
use OxidSolutionCatalysts\Payments\Stripe\Controller\Admin\StripeConnect;
use OxidSolutionCatalysts\Payments\Stripe\Application\Controller\Admin\ModuleConfiguration;

/**
 * Metadata version
 */
$sMetadataVersion = '2.1';

/**
 * Module information
 */
$aModule = [
    'id' => 'osc_stripe_wallet',
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
        \OxidEsales\Eshop\Application\Controller\Admin\ModuleConfiguration::class => ModuleConfiguration::class,
    ],
    'controllers' => [
        'osc_stripe_webhook' => WebhookController::class,
        'osc_stripe_payment' => PaymentController::class,
        'paymentwatch_assumption' => AssumptionController::class,
        'StripeConnect' => StripeConnect::class,
    ],
    'templates' => [
        'osc_stripe_payment.tpl' => 'osc/stripe/views/tpl/payment.tpl',
        'osc_stripe_admin_config.tpl' => 'osc/stripe/views/admin/tpl/config.tpl',
        '@osc_stripe_wallet/admin/stripe_connect' => 'osc/stripe/views/admin_twig/twig/stripe_connect.html.twig',
    ],
    'blocks' => [
        [
            'template' => 'page/checkout/payment.tpl',
            'block' => 'checkout_payment_main',
            'file' => '/views/blocks/checkout_payment.tpl',
        ],
    ],
    'settings'      => [
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
        ['group' => 'PAYMENTWATCH',             'name' => 'paywatchEnabled',                    'type' => 'bool',       'value' => '0',         'position' => 200],
        ['group' => 'PAYMENTWATCH',             'name' => 'paywatchAllowedHosts',               'type' => 'arr',        'value' => '[]',        'position' => 210],
        ['group' => 'PAYMENTWATCH',             'name' => 'paywatchRateLimitEnabled',           'type' => 'bool',       'value' => '0',         'position' => 220],
        ['group' => 'PAYMENTWATCH',             'name' => 'paywatchRateLimitPerMinute',         'type' => 'str',        'value' => '100',       'position' => 230],
    ],
    'events' => [
        'onActivate' => Events::class . '::onActivate',
        'onDeactivate' => Events::class . '::onDeactivate',
    ],
];
