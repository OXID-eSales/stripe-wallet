<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\StripeWebhookEndpointApiInterface;
use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;

class WebhookEndpointRegistrar implements WebhookEndpointRegistrarInterface
{
    public function __construct(
        private readonly StripeWebhookEndpointApiInterface $api,
        private readonly WebhookEventCatalog $eventCatalog
    ) {
    }

    public function register(
        string $accessToken,
        string $webhookUrl,
        ?string $existingEndpointId,
        bool $isConnect = false,
        string $description = ''
    ): WebhookEndpointRegistrationResult {
        $this->assertHttps($webhookUrl);

        if ($existingEndpointId !== null && $existingEndpointId !== '') {
            return $this->api->update(
                $accessToken,
                $existingEndpointId,
                $webhookUrl,
                $this->eventCatalog->all(),
                $description
            );
        }

        return $this->api->create(
            $accessToken,
            $webhookUrl,
            $this->eventCatalog->all(),
            $description,
            $isConnect
        );
    }

    public function registerForShop(
        string $accountKey,
        string $platformKey,
        string $webhookUrl,
        ?string $existingEndpointId,
        string $description = ''
    ): WebhookEndpointRegistrationResult {
        try {
            return $this->register($accountKey, $webhookUrl, $existingEndpointId, false, $description);
        } catch (WebhookRegistrationException $refusal) {
            // An endpoint id we hold may have been created on the platform side (the old flow):
            // a missing resource on the account side is not the end either.
            if (!$refusal->isConnectedAccountRefusal() && !$refusal->isMissingResource()) {
                throw $refusal;
            }
        }

        $shopAccountId = $this->api->accountId($accountKey);
        $events = $this->eventCatalog->all();
        if ($platformKey === '') {
            throw WebhookRegistrationException::connectedAccountNeedsDashboardEndpoint($shopAccountId, $webhookUrl, $events);
        }
        if (!$this->api->platformControlsAccount($platformKey, $shopAccountId)) {
            throw WebhookRegistrationException::platformDoesNotControlAccount(
                $this->api->accountId($platformKey),
                $shopAccountId,
                $webhookUrl,
                $events,
            );
        }

        return $this->register($platformKey, $webhookUrl, $existingEndpointId, true, $description);
    }

    public function clearAll(string $accessToken, ?string $urlFilter = null): int
    {
        $ids = $this->api->listAll($accessToken, $urlFilter);
        foreach ($ids as $id) {
            $this->api->delete($accessToken, $id);
        }
        return count($ids);
    }

    private function assertHttps(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === 'https') {
            return;
        }
        throw WebhookRegistrationException::nonHttpsUrl($url);
    }
}
