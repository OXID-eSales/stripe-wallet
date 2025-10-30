<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

interface ContractConditionFulfilledEventInterface extends ContractEventInterface
{
    public function getConditionType(): string;

    public function getConditionData(): array;
}
