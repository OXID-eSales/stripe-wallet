<?php
/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

use OxidSolutionCatalysts\Payments\Component\Controller\Http\WebhookController;
use OxidSolutionCatalysts\Payments\Component\Controller\Http\PaymentController;

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
    'thumbnail' => 'logo.png',
    'version' => '1.0.0',
    'author' => 'OXID Solution Catalysts',
    'url' => 'https://www.oxid-esales.com',
    'email' => 'info@oxid-esales.com',
    'extend' => [],
    'controllers' => [
        'osc_stripe_webhook' => WebhookController::class,
        'osc_stripe_payment' => PaymentController::class,
    ],
    'templates' => [
        'osc_stripe_payment.tpl' => 'osc/stripe/views/tpl/payment.tpl',
        'osc_stripe_admin_config.tpl' => 'osc/stripe/views/admin/tpl/config.tpl',
    ],
    'blocks' => [
        [
            'template' => 'page/checkout/payment.tpl',
            'block' => 'checkout_payment_main',
            'file' => '/views/blocks/checkout_payment.tpl',
        ],
    ],
    'settings' => [
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_test_mode',
            'type' => 'bool',
            'value' => true,
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_test_publishable_key',
            'type' => 'str',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_test_secret_key',
            'type' => 'password',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_test_webhook_secret',
            'type' => 'password',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_live_publishable_key',
            'type' => 'str',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_live_secret_key',
            'type' => 'password',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_live_webhook_secret',
            'type' => 'password',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_payment_methods',
            'name' => 'osc_stripe_payment_methods',
            'type' => 'arr',
            'value' => ['card'],
        ],
        [
            'group' => 'osc_stripe_payment_methods',
            'name' => 'osc_stripe_capture_method',
            'type' => 'select',
            'value' => 'automatic',
            'constraints' => ['automatic', 'manual'],
        ],
    ],
    'events' => [
        'onActivate' => 'OxidSolutionCatalysts\\Payments\\Component\\Core\\Events::onActivate',
        'onDeactivate' => 'OxidSolutionCatalysts\\Payments\\Component\\Core\\Events::onDeactivate',
    ],
];
