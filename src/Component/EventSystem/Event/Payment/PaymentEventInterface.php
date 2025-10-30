<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;

interface PaymentEventInterface extends EventInterface
{
    public function getContext(): EventContext;
}
