<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

interface ContractTransitionedToPendingEventInterface extends ContractEventInterface
{
    public function getConditions(): array;
}
