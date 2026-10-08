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
class WebhookRegistrationException extends RuntimeException
{
    /** Stripe's error code when the failure came from the API (`resource_missing`, …), else null. */
    public ?string $stripeCode = null;

    /**
     * Stripe answers this when an endpoint is created or listed with the key of a
     * *connected* account (Standard account onboarded through Connect): such accounts may
     * manage webhooks in their own Dashboard only; by API, only their platform can, with
     * a Connect webhook.
     */
    public function isConnectedAccountRefusal(): bool
    {
        return stripos($this->getMessage(), 'connected account') !== false;
    }

    public function isMissingResource(): bool
    {
        return $this->stripeCode === 'resource_missing';
    }

    /**
     * @param list<string> $events
     */
    public static function connectedAccountNeedsDashboardEndpoint(
        string $shopAccountId,
        string $webhookUrl,
        array $events,
    ): self {
        return new self(sprintf(
            'Your Stripe account %s is a connected account: Stripe does not allow this module to create webhook '
            . 'endpoints with its key, and no platform key is configured. Create the endpoint in that account\'s '
            . 'Dashboard (Developers → Webhooks → Add endpoint) with the URL %s and the events %s, then paste its '
            . 'signing secret into "Webhook Endpoint Secret".',
            $shopAccountId,
            $webhookUrl,
            implode(', ', $events),
        ));
    }

    /**
     * @param list<string> $events
     */
    public static function platformDoesNotControlAccount(
        string $platformAccountId,
        string $shopAccountId,
        string $webhookUrl,
        array $events,
    ): self {
        return new self(sprintf(
            'The platform key belongs to Stripe account %s, which does not control your account %s: a Connect '
            . 'webhook registered there would never receive this shop\'s events. Paste the secret key of the '
            . 'platform that onboarded your account, or create the endpoint in your account\'s Dashboard '
            . '(Developers → Webhooks → Add endpoint) with the URL %s and the events %s, then paste its signing '
            . 'secret into "Webhook Endpoint Secret".',
            $platformAccountId,
            $shopAccountId,
            $webhookUrl,
            implode(', ', $events),
        ));
    }

    public static function nonHttpsUrl(string $url): self
    {
        return new self(sprintf(
            'Webhook URL must be https://, got %s. Stripe rejects non-HTTPS endpoints.',
            $url
        ));
    }

    public static function fromApiError(string $stripeCode, string $stripeMessage, ?Throwable $previous = null): self
    {
        $exception = new self(
            sprintf('Stripe API error [%s]: %s', $stripeCode, $stripeMessage),
            0,
            $previous
        );
        $exception->stripeCode = $stripeCode !== '' ? $stripeCode : null;

        return $exception;
    }
}
