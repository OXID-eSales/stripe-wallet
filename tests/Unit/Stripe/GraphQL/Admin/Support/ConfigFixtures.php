<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigSnapshot;

final class ConfigFixtures
{
    /**
     * @param array<string, mixed> $overrides constructor arguments of the snapshot, by name
     */
    public static function snapshot(array $overrides = []): StripeConfigSnapshot
    {
        return new StripeConfigSnapshot(...array_merge([
            'mode' => 'test',
            'captureMode' => 'automatic',
            'removeByBillingCountry' => true,
            'removeByBasketCurrency' => true,
            'provideCustomerEmail' => false,
            'logLevel' => 'normal',
            'logWebhooks' => true,
            'testPublishableKey' => 'pk_test_AAAAAAAAAAbbbb',
            'livePublishableKey' => '',
            'webhookEndpointUrl' => 'https://shop.example/index.php?cl=stripewebhook',
            'testTokenSet' => true,
            'liveTokenSet' => false,
            'testPlatformKeySet' => false,
            'livePlatformKeySet' => false,
            'webhookSecretSet' => true,
            'testConnected' => true,
            'liveConnected' => false,
            'keyValidationError' => null,
        ], $overrides));
    }
}
