<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\PaymentHandler\ExistingCheckout;
use OxidEsales\Payments\Stripe\PaymentHandler\PendingCheckoutReuse;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Throwable;

/**
 * Answers one question for everybody who needs it: is the checkout this shopper
 * already has in flight still the one to use?
 *
 * Two callers used to answer it differently and undo each other's work. The OPC
 * payment handler prepared a brand-new contract, early order and Stripe session
 * on every processCheckout call, and the stale-checkout cleanup cancelled
 * whatever was in flight as soon as a checkout page rendered. One checkout came
 * out the other end with five Stripe sessions, five contracts and five orders,
 * and the customer could pay in a sheet the shop had already moved on from.
 *
 * "Cannot tell" is always answered with null, and both callers read that as
 * "carry on as before": the handler prepares a fresh checkout, the cleanup
 * cancels the old one. Neither may break because Stripe was briefly unreachable.
 */
class CheckoutInFlightGuard
{
    public function __construct(
        private readonly ?StripeAdapterFactoryInterface $adapterFactory = null,
    ) {
    }

    /**
     * The Stripe session belonging to this contract, but only while it is still
     * usable for what is in the basket right now. Null means "no usable checkout
     * in flight".
     */
    public function inspect(?object $contract): ?StripeCheckoutSessionDto
    {
        if ($contract === null) {
            return null;
        }

        try {
            $session = $this->retrieveSession($this->readProviderOrderId($contract));
            if ($session === null) {
                return null;
            }

            [$amountMinorUnits, $currency] = $this->readCurrentBasketTotal();

            $existing = new ExistingCheckout(
                $this->readString($contract, 'getStateValue'),
                $this->readString($contract, 'getProvider'),
                $session->id,
                $session->amountTotal,
                $session->currency,
                $session->paymentStatus
            );

            return PendingCheckoutReuse::allows($existing, $amountMinorUnits, $currency) ? $session : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function retrieveSession(string $sessionId): ?StripeCheckoutSessionDto
    {
        if ($sessionId === '' || $this->adapterFactory === null) {
            return null;
        }

        return $this->adapterFactory->getStripeAdapter()->retrieveCheckoutSession($sessionId);
    }

    /**
     * The basket total as Stripe would charge it, in minor units. A zero total
     * means it could not be determined, which the rule refuses.
     *
     * @return array{0: int, 1: string}
     */
    protected function readCurrentBasketTotal(): array
    {
        // No defensive checks here on purpose: the shop's own types say these
        // calls are safe, and anything that still goes wrong at runtime (no
        // session basket, for instance) throws and is caught by inspect(), which
        // is exactly the "cannot tell" answer the callers expect.
        $basket = Registry::getSession()->getBasket();
        $currencyName = $basket->getBasketCurrency()->name;
        $currency = is_string($currencyName) ? $currencyName : '';
        $gross = (float) $basket->getPrice()->getBruttoPrice();

        return [AmountConverter::toMinorUnits($gross, $currency), $currency];
    }

    private function readProviderOrderId(object $contract): string
    {
        return $this->readString($contract, 'getProviderOrderId');
    }

    private function readString(object $contract, string $method): string
    {
        if (!method_exists($contract, $method)) {
            return '';
        }

        $value = $contract->{$method}();

        return is_string($value) ? $value : '';
    }
}
