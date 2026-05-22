<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\StripeWebhookEndpointApiInterface;
use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;

final class WebhookEndpointRegistrar implements WebhookEndpointRegistrarInterface
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
