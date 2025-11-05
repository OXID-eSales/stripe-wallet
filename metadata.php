<?php
/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Metadata version
 */
$sMetadataVersion = '2.1';

/**
 * Module information
 */
$aModule = [
    'id'            => 'stripe',
    'title'         => [
        'de' => 'Stripe Payment',
        'en' => 'Stripe Payment',
        'fr' => 'Stripe Payment'
    ],
    'description'   => [
        'de' => 'Dieses Modul integriert STRIPE als Zahlungsanbieter in Ihren OXID Shop.',
        'en' => 'This module integrates STRIPE as payment provider in your OXID Shop.',
    ],
    'thumbnail'     => 'img/stripe_logo.png',
    'version'       => '2.0.3',
    'author'        => 'OXID eSales AG',
    'url'           => 'https://www.oxid-esales.com',
    'email'         => 'info@oxid-esales.com',
    'extend'        => [
        PaymentGateway::class => StripePaymentGateway::class,
        Order::class => StripeOrder::class,
        OrderArticle::class => StripeOrderArticle::class,
        Payment::class => StripePayment::class,
        ModuleConfiguration::class => StripeModuleConfiguration::class,
        ModuleMain::class => StripeModuleMain::class,
        PaymentMain::class => StripePaymentMain::class,
        OrderMain::class => StripeOrderMain::class,
        OrderOverview::class => StripeOrderOverview::class,
        PaymentController::class => StripePaymentController::class,
        OrderController::class => StripeOrderController::class,
        Email::class => StripeEmail::class,
        Session::class => StripeSession::class,
    ],
    'controllers'   => [
        'StripeWebhook' => OxidSolutionCatalysts\Stripe\Application\Controller\StripeWebhook::class,
        'StripeFinishPayment' => OxidSolutionCatalysts\Stripe\Application\Controller\StripeFinishPayment::class,
        'stripe_order_refund' => OxidSolutionCatalysts\Stripe\Application\Controller\Admin\OrderRefund::class,
        'StripeConnect' => \OxidSolutionCatalysts\Stripe\Application\Controller\Admin\StripeConnect::class,
    ],
    'events'        => [
        'onActivate' => \OxidSolutionCatalysts\Stripe\Core\Events::class.'::onActivate',
        'onDeactivate' => \OxidSolutionCatalysts\Stripe\Core\Events::class.'::onDeactivate',
    ],
    'settings'      => [
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
            'constraints' => 'automatic|manual',
        ],
    ],
    'events' => [
        'onActivate' => 'OxidSolutionCatalysts\\Payments\\Component\\Core\\Events::onActivate',
        'onDeactivate' => 'OxidSolutionCatalysts\\Payments\\Component\\Core\\Events::onDeactivate',
    ],
];
