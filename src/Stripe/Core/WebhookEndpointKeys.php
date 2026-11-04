<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Core;

/**
 * The names under which the shop remembers its Stripe webhook endpoint, per mode.
 *
 * One place for them: the module configuration service (reads the secret), the admin form controller (writes id and
 * secret) and the admin API's webhook state (reads both) each carried their own copy of these strings.
 * Per-mode values live in the module's oxconfig variables, never in the settings form; the two LEGACY_* settings are
 * the mode-agnostic ones a merchant may have pasted by hand before auto-registration existed.
 */
final class WebhookEndpointKeys
{
    public const LEGACY_URL = 'sStripeWebhookEndpoint';
    public const LEGACY_SECRET = 'sStripeWebhookEndpointSecret';

    private function __construct()
    {
    }

    public static function endpointId(string $mode): string
    {
        return $mode === StripeDefinitions::MODE_LIVE ? 'sStripeWebhookEndpointIdLive' : 'sStripeWebhookEndpointIdTest';
    }

    public static function secret(string $mode): string
    {
        return $mode === StripeDefinitions::MODE_LIVE
            ? 'sStripeWebhookEndpointSecretLive'
            : 'sStripeWebhookEndpointSecretTest';
    }

    public static function otherMode(string $mode): string
    {
        return $mode === StripeDefinitions::MODE_LIVE ? StripeDefinitions::MODE_TEST : StripeDefinitions::MODE_LIVE;
    }
}
