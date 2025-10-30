<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

interface ContractReadyToCommitEventInterface extends ContractEventInterface
{
    public function getPaymentProviderData(): array;
}
