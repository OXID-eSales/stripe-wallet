<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;

/**
 * Decides whether a verified Stripe event is this shop's to handle.
 */
interface WebhookOriginPolicyInterface
{
    /**
     * @return string|null why the event is not this shop's (the action the webhook is answered with), null when it is
     */
    public function refusalFor(WebhookEvent $event): ?string;
}
