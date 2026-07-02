<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Event;

use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;

/**
 * Fired by CheckoutSessionService::buildSuccessUrl() after the base URL is assembled.
 *
 * Listeners may append additional query parameters to the URL.
 * Example use case: OPC modal appends &opcModalId=... so the success handler
 * knows which Buy Now modal to reopen after Stripe redirects back.
 */
class StripeSuccessUrlBuildEvent implements EventInterface
{
    public function __construct(
        private string $url,
        private readonly string $contractId,
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }
}
