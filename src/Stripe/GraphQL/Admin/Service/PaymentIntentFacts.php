<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * What Stripe says about a PaymentIntent right now, or why it could not be asked. Never a raw payload.
 */
final readonly class PaymentIntentFacts
{
    public function __construct(public ?string $status, public ?string $chargeId, public ?string $apiError)
    {
    }

    public static function unavailable(string $apiError): self
    {
        return new self(null, null, $apiError);
    }
}
