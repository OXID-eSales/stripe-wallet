<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Controller\SessionWriterInterface;

/**
 * OXID-backed implementation of {@see SessionWriterInterface}. Writes
 * `sess_challenge` so OXID's thankyou controller can load the order
 * after the shared return chain commits. Stripe's OPC bridge and
 * `StripeOrderController::checkoutSuccess` both call through the
 * shared `CheckoutReturnResponder`, which delegates the session write
 * to this tiny class — keeping payment-component decoupled from the
 * OXID shop classes.
 */
final class OxidSessionWriter implements SessionWriterInterface
{
    public function writeSessChallenge(string $orderId): void
    {
        Registry::getSession()->setVariable('sess_challenge', $orderId);
    }
}
