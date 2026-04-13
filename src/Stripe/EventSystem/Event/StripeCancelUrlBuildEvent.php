<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Event;

use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;

/**
 * Fired by CheckoutSessionService::buildCancelUrl() after the base URL is assembled.
 *
 * Listeners may append additional query parameters to the URL.
 * Example use case: OPC modal appends &opcModalId=... so the cancel handler
 * knows which Buy Now modal to reopen after the user cancels on Stripe.
 */
class StripeCancelUrlBuildEvent implements EventInterface
{
    public function __construct(
        private string $url,
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
}
