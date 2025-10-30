<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

interface ContractExpiredEventInterface extends ContractTerminatedEventInterface
{
    public function getExpirationTime(): int;
}
