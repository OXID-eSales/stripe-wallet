<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Translator;

use OxidEsales\PaymentBase\EventSystem\Broker\ProviderEventTranslatorInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
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
        return $providerName === StripeDefinitions::PROVIDER;
    }

    public function translate(AbstractProviderRequestEvent $event): ?EventInterface
    {
        // Stripe's concrete event classes constructor-inject the concrete
        // EventContext (not the interface). Narrow once at the entry, so
        // each branch below can hand the context over cleanly. Returns
        // null on the unexpected interface-but-not-concrete case so the
        // broker treats the event as unhandled.
        $context = $event->getContext();
        if (!$context instanceof EventContext) {
            return null;
        }

        if ($event instanceof RefundRequestedEvent) {
            return new StripeRefundRequestEvent($context);
        }
        if ($event instanceof CaptureRequestedEvent) {
            return new StripeCaptureRequestEvent($context);
        }
        if ($event instanceof CancelAuthorizationRequestedEvent) {
            return new StripeCancelAuthorizationRequestEvent($context);
        }
        return null;
    }
}
