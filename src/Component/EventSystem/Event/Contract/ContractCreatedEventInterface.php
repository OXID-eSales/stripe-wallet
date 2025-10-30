<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

interface ContractCreatedEventInterface extends ContractEventInterface
{
    public function getContractId(): string;

    public function getContractState(): string;
}
