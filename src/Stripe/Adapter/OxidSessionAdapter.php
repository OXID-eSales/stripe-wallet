<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;

/**
 * OXID implementation of SessionAdapterInterface.
 *
 * Sprint 27: Implements payment-base interface (moved from Stripe).
 * Sprint 20: Wraps Registry::getSession() to allow mocking in unit tests.
 *
 * @since 2.0.0
 */
class OxidSessionAdapter implements SessionAdapterInterface
{
    public function getSessionId(): string
    {
        return Registry::getSession()->getId();
    }

    /** @phpstan-ignore return.unusedType (interface allows null, OXID always returns basket) */
    public function getBasket(): ?object
    {
        return Registry::getSession()->getBasket();
    }

    public function setVariable(string $name, mixed $value): void
    {
        Registry::getSession()->setVariable($name, $value);
    }

    public function getVariable(string $name): mixed
    {
        return Registry::getSession()->getVariable($name);
    }

    public function setBasket(object $basket): void
    {
        Registry::getSession()->setBasket($basket);
    }

    public function setUser(object $user): void
    {
        Registry::getSession()->setVariable('oePaymentUser', $user);
    }
}
