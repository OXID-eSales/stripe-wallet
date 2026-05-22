<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when a Stripe webhook endpoint operation (create / update / list / delete)
 * fails: non-HTTPS URL, missing OAuth scope, rate limit, network outage, etc.
 *
 * Callers (the admin AJAX actions on ModuleConfiguration) are expected to catch
 * this, return the exception message to the client as JSON, and let the admin
 * decide whether to retry, fix the platform key, or paste the secret by hand.
 */
final class WebhookRegistrationException extends RuntimeException
{
    public static function nonHttpsUrl(string $url): self
    {
        return new self(sprintf(
            'Webhook URL must be https://, got %s. Stripe rejects non-HTTPS endpoints.',
            $url
        ));
    }

    public static function fromApiError(string $stripeCode, string $stripeMessage, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Stripe API error [%s]: %s', $stripeCode, $stripeMessage),
            0,
            $previous
        );
    }
}
