<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelUrlBuildEvent;

/**
 * Appends the OPC Buy Now modal ID to the Stripe cancel URL.
 *
 * When the user cancels on the Stripe Checkout page, they are redirected
 * back to this URL. Including &opcModalId=... allows the cancel handler
 * to reopen the correct Buy Now modal so the user can choose a different
 * payment method without losing context.
 *
 * This handler is a no-op when OPC is not active or no modal session exists.
 * Modal-ID resolution is centralised in OpcModalSessionReader (D8).
 */
class OpcModalCancelUrlHandler implements HandlerInterface
{
    public function __construct(
        private readonly OpcModalSessionReader $sessionReader,
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return StripeCancelUrlBuildEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCancelUrlBuildEvent) {
            return;
        }

        $modalId = $this->sessionReader->getModalId();
        if ($modalId === null) {
            return;
        }

        // Prefer redirecting to the originating page so the OPC modal can re-open there.
        $originUrl = $this->sessionReader->getOriginUrl();
        if ($originUrl !== null) {
            $separator = str_contains($originUrl, '?') ? '&' : '?';
            $event->setUrl($originUrl . $separator . 'opcModalId=' . urlencode($modalId));
            return;
        }

        // Fallback: append opcModalId to the base cancel URL.
        $event->setUrl($event->getUrl() . '&opcModalId=' . urlencode($modalId));
    }
}
