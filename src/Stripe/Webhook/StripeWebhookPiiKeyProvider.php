<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookPiiKeyProvider;

/**
 * VR-18 L4: the keys of a Stripe event payload that hold personal or card data under a name payment-base's generic
 * vocabulary does not catch (`shipping`, `billing_details`, `metadata`, `last4`, ...). Tagged
 * `payment_base.webhook_pii_key_provider`.
 */
final class StripeWebhookPiiKeyProvider implements WebhookPiiKeyProvider
{
    public function piiKeys(): array
    {
        return [
            'customer_details',
            'customer_email',
            'customer_name',
            'shipping',
            'billing_details',
            'receipt_email',
            'metadata',
            'last4',
            'exp_month',
            'exp_year',
        ];
    }
}
