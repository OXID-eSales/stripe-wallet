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
        private readonly WebhookEventCatalog $eventCatalog,
        private readonly WebhookUrlOwnership $urlOwnership
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

        $existingEndpointId = $this->endpointToUpdate($accessToken, $webhookUrl, $existingEndpointId);
        if ($existingEndpointId !== null) {
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
        $ids = $this->endpointIdsToClear($this->api->endpointUrls($accessToken), $urlFilter);
        foreach ($ids as $id) {
            $this->api->delete($accessToken, $id);
        }

        return count($ids);
    }

    /**
     * The endpoints of this shop among those of the account: other shops can share the Stripe key. For
     * shop 1 that includes the endpoint registered before the URL named the shop (STRP-180).
     *
     * @param array<string, string> $endpointUrls endpoint id => url
     *
     * @return list<string>
     */
    private function endpointIdsToClear(array $endpointUrls, ?string $shopWebhookUrl): array
    {
        if ($shopWebhookUrl === null) {
            return array_keys($endpointUrls);
        }

        return array_keys(array_filter(
            $endpointUrls,
            fn (string $url): bool => $this->urlOwnership->owns($url, $shopWebhookUrl)
        ));
    }

    /**
     * The endpoint a registration updates: the one we hold, else the one of the account that already
     * points at this URL - a second one would deliver every event twice, one of them with a signing
     * secret nobody holds (VR-16 O6).
     *
     * A held id is used only when its endpoint is this shop's (sprint 144 AW): a subshop that holds a
     * copy of another shop's endpoint id would otherwise rewrite that endpoint to its own URL. An id
     * the account does not list stays: it can be a Connect webhook on the platform (the old flow).
     */
    private function endpointToUpdate(string $accessToken, string $webhookUrl, ?string $heldEndpointId): ?string
    {
        $endpointUrls = $this->api->endpointUrls($accessToken);
        $heldId = (string) $heldEndpointId;
        if ($heldId !== '' && $this->isHeldEndpointOfThisShop($endpointUrls[$heldId] ?? null, $webhookUrl)) {
            return $heldId;
        }

        $sameUrl = array_search($webhookUrl, $endpointUrls, true);

        return $sameUrl === false ? null : (string) $sameUrl;
    }

    private function isHeldEndpointOfThisShop(?string $heldEndpointUrl, string $webhookUrl): bool
    {
        return $heldEndpointUrl === null || $this->urlOwnership->owns($heldEndpointUrl, $webhookUrl);
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
