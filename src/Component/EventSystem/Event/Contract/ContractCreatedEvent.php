<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;

readonly class ContractCreatedEvent implements ContractCreatedEventInterface
{
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContext $context
    ) {
    }

    public function getContract(): PaymentContractInterface
    {
        return $this->contract;
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getContractId(): string
    {
        return $this->contract->getId();
    }

    public function getContractState(): string
    {
        return $this->contract->getStateValue();
    }
}
