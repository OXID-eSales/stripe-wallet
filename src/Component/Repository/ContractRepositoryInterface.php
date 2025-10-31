<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;

interface ContractRepositoryInterface
{
    public function save(PaymentContract $contract): void;

    public function findById(string $id): ?PaymentContract;

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContract;

    public function findByUserId(string $userId): array;
}
