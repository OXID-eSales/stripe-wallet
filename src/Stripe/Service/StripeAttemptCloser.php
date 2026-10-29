<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Checkout\Attempt\PaymentAttemptCloserInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

/**
 * OPC-175 — the customer leaves a Stripe attempt for another payment method.
 *
 * A paid session is not replaced. An open one is expired: Stripe then takes no
 * more payment on it. A complete or expired session needs nothing. After
 * checkout.session.completed the contract carries the payment intent instead
 * of the session: succeeded, requires_capture or processing is paid, any other
 * status can take no money without a new confirmation. A Stripe error reaches
 * the caller: nothing is replaced on a guess.
 *
 * @since OPC-175
 */
final class StripeAttemptCloser implements PaymentAttemptCloserInterface
{
    private const SESSION_OPEN = 'open';
    private const PAID = 'paid';
    private const PAYMENT_INTENT_PREFIX = 'pi_';
    private const PAID_PAYMENT_INTENT = ['succeeded', 'requires_capture', 'processing'];

    public function __construct(private readonly StripeAdapterFactoryInterface $adapterFactory)
    {
    }

    public function provider(): string
    {
        return StripeDefinitions::PROVIDER;
    }

    public function close(PaymentContractInterface $contract): AttemptClosure
    {
        $sessionId = (string) $contract->getProviderOrderId();
        if ($sessionId === '') {
            return AttemptClosure::Closed;
        }

        $adapter = $this->adapterFactory->getStripeAdapter();
        if (str_starts_with($sessionId, self::PAYMENT_INTENT_PREFIX)) {
            $status = $adapter->retrievePaymentIntent($sessionId)->status;

            return in_array($status, self::PAID_PAYMENT_INTENT, true) ? AttemptClosure::Paid : AttemptClosure::Closed;
        }

        $session = $adapter->retrieveCheckoutSession($sessionId);
        if ($session->paymentStatus === self::PAID) {
            return AttemptClosure::Paid;
        }

        if ($session->status === self::SESSION_OPEN) {
            $adapter->expireCheckoutSession($sessionId);
        }

        return AttemptClosure::Closed;
    }
}
