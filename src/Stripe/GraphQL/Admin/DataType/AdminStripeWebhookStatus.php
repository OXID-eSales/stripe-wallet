<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\DataType;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookStatus;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * Where the shop's webhook stands. The signing secret is not here, ever.
 */
#[Type(name: 'AdminStripeWebhookStatus')]
final class AdminStripeWebhookStatus
{
    public function __construct(private readonly StripeWebhookStatus $status)
    {
    }

    /** The mode the status is about: `test` or `live`. */
    #[Field]
    public function getMode(): string
    {
        return $this->status->mode;
    }

    /** Deliveries can be verified: a signing secret is stored for the mode. */
    #[Field]
    public function isConfigured(): bool
    {
        return $this->status->configured;
    }

    /** The id of the endpoint at Stripe; null when this shop registered none (a pasted secret has no id). */
    #[Field]
    public function getEndpointId(): ?string
    {
        return $this->status->endpointId === '' ? null : $this->status->endpointId;
    }

    /** The URL Stripe has to deliver this shop's events to. */
    #[Field]
    public function getUrl(): string
    {
        return $this->status->url;
    }
}
