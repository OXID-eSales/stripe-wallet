<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

interface ContractCommittedEventInterface extends ContractEventInterface
{
    public function getOrderId(): string;
}
