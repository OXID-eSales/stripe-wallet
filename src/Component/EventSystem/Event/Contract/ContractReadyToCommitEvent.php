<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContextInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;

readonly class ContractReadyToCommitEvent implements ContractReadyToCommitEventInterface
{
    /**
     * @param array<string, mixed> $paymentProviderData
     */
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContextInterface $context,
        private array $paymentProviderData
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
        return $this->contract->getId() ?? '';
    }

    public function getContractState(): string
    {
        return $this->contract->getStateValue();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentProviderData(): array
    {
        return $this->paymentProviderData;
    }
}
