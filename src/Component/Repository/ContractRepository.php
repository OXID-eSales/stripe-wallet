<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;

class ContractRepository implements ContractRepositoryInterface
{
    private array $storage = [];

    public function save(PaymentContractInterface $contract): void
    {
        $this->storage[$contract->getId()] = $contract->toArray();
    }

    public function findById(string $id): ?PaymentContract
    {
        if (!isset($this->storage[$id])) {
            return null;
        }

        return PaymentContract::fromArray($this->storage[$id]);
    }

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContract
    {
        foreach ($this->storage as $data) {
            if (($data['providerOrderId'] ?? null) === $providerOrderId) {
                return PaymentContract::fromArray($data);
            }
        }

        return null;
    }

    public function findByUserId(string $userId): array
    {
        $contracts = [];

        foreach ($this->storage as $data) {
            if ($data['userId'] === $userId) {
                $contracts[] = PaymentContract::fromArray($data);
            }
        }

        return $contracts;
    }

    public function findActiveByUserId(string $userId): ?PaymentContract
    {
        foreach ($this->storage as $data) {
            if (
                $data['userId'] === $userId &&
                in_array($data['state'], ['draft', 'pending', 'ready_to_commit', 'committed'], true)
            ) {
                return PaymentContract::fromArray($data);
            }
        }

        return null;
    }

    public function findExpired(?\DateTimeInterface $before = null): array
    {
        $before = $before ?? new \DateTime();
        $expired = [];

        foreach ($this->storage as $data) {
            $expiresAt = isset($data['expiresAt']) ? new \DateTime($data['expiresAt']) : null;

            if (
                $expiresAt &&
                $expiresAt < $before &&
                !in_array($data['state'], ['fulfilled', 'cancelled', 'expired', 'failed'], true)
            ) {
                $expired[] = PaymentContract::fromArray($data);
            }
        }

        return $expired;
    }

    public function delete(string $id): void
    {
        unset($this->storage[$id]);
    }
}
