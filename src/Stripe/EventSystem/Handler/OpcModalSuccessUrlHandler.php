<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
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
 */
class OpcModalSuccessUrlHandler implements HandlerInterface
{
    public static function getHandledEventClass(): string
    {
        return StripeSuccessUrlBuildEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeSuccessUrlBuildEvent) {
            return;
        }

        $modalId = $this->getOpcModalId();
        if ($modalId === null) {
            return;
        }

        $event->setUrl($event->getUrl() . '&opcModalId=' . urlencode($modalId));
    }

    private function getOpcModalId(): ?string
    {
        // Primary: passed explicitly in the processCheckout request body by the footer controller.
        // The footer widget is rendered after registerModalOpen, so the modal ID is already
        // embedded in the Twig template and forwarded by JS with the payment request.
        $fromRequest = Registry::getRequest()->getRequestParameter('opcModalId');
        if (is_string($fromRequest) && $fromRequest !== '') {
            return $fromRequest;
        }

        // Fallback: read from PHP session (set by registerModalOpen when modal opened).
        try {
            $modalSession = Registry::getSession()->getVariable('oe_opc_modal_session');
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($modalSession) || empty($modalSession['modalId'])) {
            return null;
        }

        $modalId = $modalSession['modalId'];

        return is_string($modalId) ? $modalId : null;
    }
}
