<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

interface ContractCancelledEventInterface extends ContractTerminatedEventInterface
{
    public function getReason(): string;
}
