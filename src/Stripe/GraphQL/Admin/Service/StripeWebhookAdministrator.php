<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * Where the shop's webhook stands in the mode in use. Read-only: registering and clearing the endpoints is the classic
 * module configuration's ("Create webhooks", "Clear all webhooks"), not the admin API's (CFG-1).
 */
final class StripeWebhookAdministrator implements StripeWebhookAdministratorInterface
{
    public function __construct(
        private readonly StripeAdminSettingsInterface $settings,
        private readonly StripeWebhookStateInterface $state,
    ) {
    }

    public function status(): StripeWebhookStatus
    {
        $snapshot = $this->settings->snapshot();

        return new StripeWebhookStatus(
            $snapshot->mode,
            $this->state->hasSecret($snapshot->mode),
            $this->state->endpointId($snapshot->mode),
            $snapshot->webhookEndpointUrl,
        );
    }
}
