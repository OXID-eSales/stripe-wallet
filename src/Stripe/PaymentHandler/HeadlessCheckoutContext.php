<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\PaymentHandler;

use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;

/**
 * What a payment context says about a headless (GraphQL) checkout: whether it is one, in which Stripe UI mode, for which
 * basket and session, and where Stripe sends the shopper back to (GRAPH-QL / PS1). A headless context has no PHP session;
 * the client's URLs were validated by payment-base's return-URL policy before they got here.
 */
final class HeadlessCheckoutContext
{
    public function __construct(private readonly PaymentContextInterface $context)
    {
    }

    public function isHeadless(): bool
    {
        return $this->context->getMetadataValue('headless') === true;
    }

    public function uiMode(): string
    {
        $uiMode = $this->context->getMetadataValue('uiMode', 'hosted');

        return is_string($uiMode) && $uiMode !== '' ? $uiMode : 'hosted';
    }

    public function isEmbedded(): bool
    {
        return $this->uiMode() === 'embedded';
    }

    public function basketId(): ?string
    {
        $basketId = $this->context->getMetadataValue('basketId');

        return is_string($basketId) && $basketId !== '' ? $basketId : null;
    }

    public function sessionId(): string
    {
        $sessionId = $this->context->getMetadataValue('sessionId');

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : 'headless:' . (string) $this->basketId();
    }

    /**
     * Stripe appends the Checkout Session id to the return URL so the client can hand it to `stripeCheckoutReturn`.
     * Hosted mode uses success/cancel URLs, embedded mode a single return URL: the same string serves both. Without a
     * cancel URL the shopper comes back to the return URL and the client reads the contract state.
     *
     * @return array{0: string, 1: string} success URL, cancel URL
     */
    public function returnUrls(): array
    {
        $returnUrl = (string) $this->context->getReturnUrl();
        $successUrl = $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $this->context->getCancelUrl();

        return [$successUrl, is_string($cancelUrl) && $cancelUrl !== '' ? $cancelUrl : $returnUrl];
    }
}
