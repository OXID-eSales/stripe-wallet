<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContextInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;

interface ContractEventInterface extends EventInterface
{
    public function getContract(): PaymentContractInterface;

    public function getContext(): EventContextInterface;
}
