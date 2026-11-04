<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Translator;

use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;

/**
 * A provider request event Stripe has no mapping for. Named, not anonymous: anonymous readonly classes need PHP 8.3
 * and the module supports 8.2.
 */
readonly class UnmappedProviderRequestEvent extends AbstractProviderRequestEvent
{
}
