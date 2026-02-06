<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Helper for Stripe Checkout Session operations.
 *
 * Sprint 46: Extracted from StripeAdapter to reduce ECC.
 *
 * @since 2.0.0
 */
final class CheckoutSessionHelper
{
    /**
     * @param array<string, mixed> $params
     */
    public function createCheckoutSession(StripeClient $client, array $params): Session
    {
        try {
            return $client->checkout->sessions->create($params);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    /**
     * @param array<string> $expand
     */
    public function retrieveCheckoutSession(StripeClient $client, string $sessionId, array $expand = []): Session
    {
        try {
            $options = [];
            if (!empty($expand)) {
                $options['expand'] = $expand;
            }
            return $client->checkout->sessions->retrieve($sessionId, $options);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }
}
