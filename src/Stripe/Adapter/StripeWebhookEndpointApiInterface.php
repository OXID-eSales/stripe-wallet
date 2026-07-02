<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrationResult;

/**
 * Narrow port over the Stripe SDK's webhook endpoint operations.
 *
 * Wrapping the SDK behind this interface keeps the Stripe SDK version
 * (and its `@property` magic accessors that fight PHPUnit mocking) out of
 * the unit-test surface. Production implementation: {@see StripeWebhookEndpointApi}.
 */
interface StripeWebhookEndpointApiInterface
{
    /**
     * Create a new webhook endpoint on the Stripe account identified by $apiKey.
     *
     * When $isConnect is true the endpoint is registered as a platform-level
     * Connect webhook (receives events for all connected accounts). Pass false
     * (the default) for per-account webhooks using a connected-account token.
     *
     * @param list<string> $events
     * @throws WebhookRegistrationException
     */
    public function create(
        string $apiKey,
        string $url,
        array $events,
        string $description,
        bool $isConnect = false
    ): WebhookEndpointRegistrationResult;

    /**
     * Update an existing webhook endpoint. Stripe does not re-emit the signing
     * secret on update, so the returned result's `secret` is always null.
     *
     * @param list<string> $events
     * @throws WebhookRegistrationException
     */
    public function update(
        string $apiKey,
        string $endpointId,
        string $url,
        array $events,
        string $description
    ): WebhookEndpointRegistrationResult;

    /**
     * Lists webhook endpoints visible to $apiKey, returning their IDs.
     *
     * When $urlFilter is non-null, only endpoints whose `url` exactly equals
     * $urlFilter are returned — used to scope deletion to a single shop when
     * multiple shops share one Stripe key.
     *
     * @return list<string> Endpoint IDs (`we_…`).
     * @throws WebhookRegistrationException
     */
    public function listAll(string $apiKey, ?string $urlFilter = null): array;

    /**
     * Deletes the given endpoint. Stripe accepts deletion of any endpoint visible
     * to the API key, including Connect-mode ones, with no special flag required.
     *
     * @throws WebhookRegistrationException
     */
    public function delete(string $apiKey, string $endpointId): void;
}
