<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrationResult;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Production adapter wrapping `Stripe\StripeClient::webhookEndpoints`.
 *
 * Translates SDK exceptions into our domain {@see WebhookRegistrationException}
 * so callers can catch a single type without coupling to the SDK.
 *
 * WHY inject StripeClientProviderInterface instead of calling new StripeClient():
 * The platform key used here may differ from the shop's default secret key (it is
 * read from config per request), so we cannot use the pre-configured client from
 * StripeClientFactory::create(). StripeClientProviderInterface::forKey() pins the
 * same stripe_version as the rest of the module, ensuring consistent API behaviour.
 */
class StripeWebhookEndpointApi implements StripeWebhookEndpointApiInterface
{
    public function __construct(
        private readonly StripeClientProviderInterface $clientProvider
    ) {
    }
    /**
     * @param list<string> $events
     */
    public function create(
        string $apiKey,
        string $url,
        array $events,
        string $description,
        bool $isConnect = false
    ): WebhookEndpointRegistrationResult {
        try {
            $payload = [
                'url'            => $url,
                'enabled_events' => $events,
                'description'    => $description,
            ];

            // WHY: Stripe rejects this call from connected accounts; only a platform
            // secret key can register Connect webhooks that listen to all connected accounts.
            if ($isConnect) {
                $payload['connect'] = true;
            }

            $endpoint = $this->client($apiKey)->webhookEndpoints->create($payload);
        } catch (ApiErrorException $e) {
            throw WebhookRegistrationException::fromApiError(
                (string) $e->getStripeCode(),
                $e->getMessage(),
                $e
            );
        }

        return new WebhookEndpointRegistrationResult(
            (string) $endpoint->id,
            $endpoint->secret !== null ? (string) $endpoint->secret : null
        );
    }

    /**
     * @param list<string> $events
     */
    public function update(
        string $apiKey,
        string $endpointId,
        string $url,
        array $events,
        string $description
    ): WebhookEndpointRegistrationResult {
        try {
            $endpoint = $this->client($apiKey)->webhookEndpoints->update($endpointId, [
                'url'            => $url,
                'enabled_events' => $events,
                'description'    => $description,
            ]);
        } catch (ApiErrorException $e) {
            throw WebhookRegistrationException::fromApiError(
                (string) $e->getStripeCode(),
                $e->getMessage(),
                $e
            );
        }

        // Stripe never re-emits the signing secret on update; nulling it here
        // signals to the caller that the stored secret should be preserved.
        return new WebhookEndpointRegistrationResult((string) $endpoint->id, null);
    }

    public function listAll(string $apiKey, ?string $urlFilter = null): array
    {
        try {
            $endpoints = $this->client($apiKey)->webhookEndpoints->all(['limit' => 100]);
        } catch (ApiErrorException $e) {
            throw WebhookRegistrationException::fromApiError(
                (string) $e->getStripeCode(),
                $e->getMessage(),
                $e
            );
        }

        $ids = [];
        foreach ($endpoints->data as $endpoint) {
            // Skip endpoints whose URL doesn't match the filter — preserves
            // endpoints belonging to OTHER shops that share the same Stripe key.
            if ($urlFilter !== null && (string) $endpoint->url !== $urlFilter) {
                continue;
            }
            $ids[] = (string) $endpoint->id;
        }
        return $ids;
    }

    public function delete(string $apiKey, string $endpointId): void
    {
        try {
            $this->client($apiKey)->webhookEndpoints->delete($endpointId);
        } catch (ApiErrorException $e) {
            throw WebhookRegistrationException::fromApiError(
                (string) $e->getStripeCode(),
                $e->getMessage(),
                $e
            );
        }
    }

    private function client(string $apiKey): StripeClient
    {
        return $this->clientProvider->forKey($apiKey);
    }
}
