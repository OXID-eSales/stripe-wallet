<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Core\Registry;
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
 */
class OpcModalCancelUrlHandler implements HandlerInterface
{
    public static function getHandledEventClass(): string
    {
        return StripeCancelUrlBuildEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCancelUrlBuildEvent) {
            return;
        }

        $modalId = $this->getOpcModalId();
        if ($modalId === null) {
            return;
        }

        // Prefer redirecting to the originating page so the OPC modal can re-open there.
        // The originUrl is the page where the Buy Now modal was first opened.
        $originUrl = $this->getOpcOriginUrl();
        if ($originUrl !== null) {
            $separator = str_contains($originUrl, '?') ? '&' : '?';
            $event->setUrl($originUrl . $separator . 'opcModalId=' . urlencode($modalId));
            return;
        }

        // Fallback: just append opcModalId to the base cancel URL.
        $event->setUrl($event->getUrl() . '&opcModalId=' . urlencode($modalId));
    }

    private function getOpcModalId(): ?string
    {
        // Primary: passed explicitly in the processCheckout request body.
        $fromRequest = Registry::getRequest()->getRequestParameter('opcModalId');
        if (is_string($fromRequest) && $fromRequest !== '') {
            return $fromRequest;
        }

        // Fallback: read from PHP session (set by registerModalOpen).
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

    private function getOpcOriginUrl(): ?string
    {
        try {
            $modalSession = Registry::getSession()->getVariable('oe_opc_modal_session');
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($modalSession) || empty($modalSession['originUrl'])) {
            return null;
        }

        $url = $modalSession['originUrl'];

        return is_string($url) ? $url : null;
    }
}
