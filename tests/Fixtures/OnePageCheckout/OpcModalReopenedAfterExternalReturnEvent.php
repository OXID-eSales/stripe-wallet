<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\OnePageCheckout\EventSystem\Event;

use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;

/**
 * Test stub for the one-page-checkout module's event class.
 *
 * The OPC integration is optional: {@see \OxidEsales\Payments\Stripe\EventSystem\Handler\OpcExternalReturnCleanupHandler}
 * references this event only through `::class` and `instanceof`, both of which
 * are safe when the class is absent, so Stripe carries no composer dependency
 * on one-page-checkout. The isolated unit-test job therefore never has the
 * real class on the autoload path.
 *
 * This stub is loaded from tests/bootstrap.php only when the real class is not
 * available, declaring it under its true FQCN so both `new` (in the test) and
 * the handler's `instanceof` resolve. It mirrors the real constructor/getters
 * (see extensions/one-page-checkout/src/EventSystem/Event/…). Same rationale as
 * the class_alias shims in tests/PhpStan/phpstan-bootstrap.php.
 */
final class OpcModalReopenedAfterExternalReturnEvent implements EventInterface
{
    public function __construct(
        private readonly string $modalId,
        private readonly string $originUrl,
    ) {
    }

    public function getModalId(): string
    {
        return $this->modalId;
    }

    public function getOriginUrl(): string
    {
        return $this->originUrl;
    }
}
