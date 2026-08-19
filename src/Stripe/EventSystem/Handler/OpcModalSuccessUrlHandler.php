<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use Psr\Log\LoggerInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeSuccessUrlBuildEvent;

/**
 * Appends the OPC Buy Now modal ID to the Stripe success URL.
 *
 * When a customer pays via the Buy Now modal, the modal session stores a
 * unique modalId in the PHP session (key: oe_opc_modal_session.modalId).
 * Appending &opcModalId=... to the success URL allows the checkoutSuccess
 * handler to reopen the correct modal after Stripe redirects back.
 *
 * This handler is a no-op when OPC is not active or no modal session exists.
 * Modal-ID resolution is centralised in OpcModalSessionReader (D8).
 */
class OpcModalSuccessUrlHandler implements HandlerInterface
{
    public function __construct(
        private readonly OpcModalSessionReader $sessionReader,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return StripeSuccessUrlBuildEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeSuccessUrlBuildEvent) {
            // Sprint 133 (F16): a wiring regression must not be silent.
            $this->logger?->warning('OpcModalSuccessUrlHandler received an unexpected event type; skipping', [
                'expected' => StripeSuccessUrlBuildEvent::class,
                'received' => $event::class,
            ]);

            return;
        }

        $modalId = $this->sessionReader->getModalId();
        if ($modalId === null) {
            return;
        }

        $event->setUrl($event->getUrl() . '&opcModalId=' . urlencode($modalId));
    }
}
