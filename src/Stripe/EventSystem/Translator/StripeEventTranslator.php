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
 *
 * O10 (Sprint 114.13): refactored from an instanceof ladder to a
 * static mapping table so new mappings are additive array entries
 * rather than edits to a conditional chain (OCP, R-2.2).
 */
final class StripeEventTranslator implements ProviderEventTranslatorInterface
{
    /**
     * Maps each abstract event class to its Stripe-specific counterpart.
     *
     * @var array<class-string<AbstractProviderRequestEvent>, class-string<EventInterface>>
     */
    private const EVENT_MAP = [
        RefundRequestedEvent::class              => StripeRefundRequestEvent::class,
        CaptureRequestedEvent::class             => StripeCaptureRequestEvent::class,
        CancelAuthorizationRequestedEvent::class => StripeCancelAuthorizationRequestEvent::class,
    ];

    public function supports(string $providerName): bool
    {
        return $providerName === StripeDefinitions::PROVIDER;
    }

    public function translate(AbstractProviderRequestEvent $event): ?EventInterface
    {
        // Stripe concrete events require the concrete EventContext (not the
        // interface). Guard here once; return null so the broker treats the
        // event as unhandled if a non-concrete context arrives.
        $context = $event->getContext();
        if (!$context instanceof EventContext) {
            return null;
        }

        $concreteClass = self::EVENT_MAP[$event::class] ?? null;
        if ($concreteClass === null) {
            return null;
        }

        return new $concreteClass($context);
    }
}
