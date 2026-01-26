<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Core\Registry;

/**
 * OXID implementation of SessionAdapterInterface.
 *
 * Sprint 20: Wraps Registry::getSession() to allow mocking in unit tests.
 *
 * @since 2.0.0
 */
final class OxidSessionAdapter implements SessionAdapterInterface
{
    public function getSessionId(): string
    {
        return Registry::getSession()->getId();
    }

    /** @phpstan-ignore return.unusedType */
    public function getBasket(): ?Basket
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
}
