<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContextInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;

interface PaymentEventInterface extends EventInterface
{
    public function getContext(): EventContextInterface;
}
