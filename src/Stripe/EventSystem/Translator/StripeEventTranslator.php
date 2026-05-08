<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Translator;

use OxidEsales\PaymentBase\EventSystem\Broker\ProviderEventTranslatorInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;

/**
 * Maps the payment-base's abstract request events onto Stripe's
 * concrete event classes. The broker dispatches the translated event
 * through the standard dispatcher; existing Stripe handlers fire
 * unchanged.
 */
final class StripeEventTranslator implements ProviderEventTranslatorInterface
{
    public function supports(string $providerName): bool
    {
        return $providerName === 'stripe';
    }

    public function translate(AbstractProviderRequestEvent $event): ?EventInterface
    {
        if ($event instanceof RefundRequestedEvent) {
            return new StripeRefundRequestEvent($event->getContext());
        }
        if ($event instanceof CaptureRequestedEvent) {
            return new StripeCaptureRequestEvent($event->getContext());
        }
        if ($event instanceof CancelAuthorizationRequestedEvent) {
            return new StripeCancelAuthorizationRequestEvent($event->getContext());
        }
        return null;
    }
}
