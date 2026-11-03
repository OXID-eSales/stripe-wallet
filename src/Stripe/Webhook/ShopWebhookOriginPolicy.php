<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\ShopStripeAccountInterface;

/**
 * VR-23 T3 (S-2): the platform's Connect endpoint receives the events of every connected account, live and test. Only
 * an event of the shop's own mode, and - when it names an account - of the shop's own account, is the shop's. Anything
 * else is answered successfully (Stripe must not retry it) and touches nothing: a connected merchant can put this
 * shop's contract id into the metadata of its own PaymentIntent.
 */
final class ShopWebhookOriginPolicy implements WebhookOriginPolicyInterface
{
    public const FOREIGN_ACCOUNT = 'ignored_foreign_account';
    public const LIVEMODE_MISMATCH = 'ignored_livemode_mismatch';

    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly ShopStripeAccountInterface $shopAccount
    ) {
    }

    public function refusalFor(WebhookEvent $event): ?string
    {
        $envelope = StripeEventEnvelope::from($event);
        if ($envelope === null) {
            return null;
        }
        if ($envelope->livemode === $this->config->isTestMode()) {
            return self::LIVEMODE_MISMATCH;
        }
        if ($envelope->account !== null && $envelope->account !== $this->shopAccount->id()) {
            return self::FOREIGN_ACCOUNT;
        }

        return null;
    }
}
