<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Support;

use LogicException;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

/**
 * For test doubles that replace every Stripe read of the class under test: asking it for the adapter is a bug.
 */
final class UnreachableStripeAdapterFactory implements StripeAdapterFactoryInterface
{
    public function getStripeAdapter(): StripeAdapterInterface
    {
        throw new LogicException('The test double replaces every read that would need Stripe.');
    }

    public function isTestMode(): bool
    {
        return true;
    }
}
