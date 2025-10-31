<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContextInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;

readonly class ContractFailedEvent implements ContractFailedEventInterface
{
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContextInterface $context,
        private string $errorCode,
        private string $errorMessage
    ) {
    }

    public function getContract(): PaymentContractInterface
    {
        return $this->contract;
    }

    public function getContext(): EventContextInterface
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

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
