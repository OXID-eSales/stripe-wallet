<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepository;

class ContractService
{
    private ContractRepository $contractRepository;

    public function __construct(ContractRepository $contractRepository)
    {
        $this->contractRepository = $contractRepository;
    }

    public function createContract(
        string $userId,
        object $basket,
        array $conditionTypes = []
    ): PaymentContract {
        $basketSnapshot = $this->createBasketSnapshot($basket);

        $contract = new PaymentContract(
            shopId: 1,
            userId: $userId,
            basketSnapshot: $basketSnapshot
        );

        if (empty($conditionTypes)) {
            $conditionTypes = [
                ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                ContractCondition::TYPE_FRAUD_CHECK,
            ];
        }

        foreach ($conditionTypes as $type) {
            $contract->addCondition(new ContractCondition($type));
        }

        $this->contractRepository->save($contract);

        return $contract;
    }

    public function findActiveContractByUser(string $userId): ?PaymentContract
    {
        return $this->contractRepository->findActiveByUserId($userId);
    }

    public function cleanupExpiredContracts(): int
    {
        $expired = $this->contractRepository->findExpired();
        $count = 0;

        foreach ($expired as $contract) {
            $contract->expire();
            $this->contractRepository->save($contract);
            $count++;
        }

        return $count;
    }

    private function createBasketSnapshot(object $basket): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => $basket->totalGross ?? 0.0,
            'totalNet' => $basket->totalNet ?? 0.0,
            'totalVat' => $basket->totalVat ?? 0.0,
            'currency' => $basket->currency ?? 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);
    }
}
