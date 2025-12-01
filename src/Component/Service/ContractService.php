<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;

class ContractService implements ContractServiceInterface
{
    private ContractRepositoryInterface $contractRepository;

    public function __construct(ContractRepositoryInterface $contractRepository)
    {
        $this->contractRepository = $contractRepository;
    }

    public function createContract(
        string $userId,
        object $basket,
        array $conditionTypes = []
    ): PaymentContractInterface {
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

    public function findActiveContractByUser(string $userId): ?PaymentContractInterface
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
        $items = [];
        $discounts = [];

        // Extract items from OXID basket
        if (method_exists($basket, 'getContents')) {
            foreach ($basket->getContents() as $basketItem) {
                $article = method_exists($basketItem, 'getArticle') ? $basketItem->getArticle() : null;
                $unitPrice = method_exists($basketItem, 'getUnitPrice')
                    ? $basketItem->getUnitPrice()->getBruttoPrice()
                    : 0.0;
                $amount = method_exists($basketItem, 'getAmount') ? (int) $basketItem->getAmount() : 1;

                $title = 'Product';
                if ($article !== null) {
                    if (isset($article->oxarticles__oxtitle->value)) {
                        $title = (string) $article->oxarticles__oxtitle->value;
                    } elseif (method_exists($article, 'getTitle')) {
                        $title = (string) $article->getTitle();
                    }
                }

                $items[] = [
                    'productId' => $article !== null ? $article->getId() : '',
                    'title' => $title,
                    'quantity' => $amount,
                    'unitPrice' => (float) $unitPrice,
                    'totalPrice' => (float) ($unitPrice * $amount),
                ];
            }
        }

        // Extract discounts from OXID basket
        if (method_exists($basket, 'getDiscounts')) {
            $basketDiscounts = $basket->getDiscounts();
            if (is_array($basketDiscounts)) {
                foreach ($basketDiscounts as $discount) {
                    $discounts[] = [
                        'name' => $discount->sDiscount ?? 'Discount',
                        'amount' => $discount->dDiscount ?? 0.0,
                    ];
                }
            }
        }

        // Get totals from OXID basket
        $totalGross = 0.0;
        $totalNet = 0.0;
        $totalVat = 0.0;
        $currency = 'EUR';

        if (method_exists($basket, 'getPrice')) {
            $price = $basket->getPrice();
            if ($price !== null) {
                $totalGross = (float) $price->getBruttoPrice();
                $totalNet = (float) $price->getNettoPrice();
                $totalVat = (float) $price->getVatValue();
            }
        }

        if (method_exists($basket, 'getBasketCurrency')) {
            $basketCurrency = $basket->getBasketCurrency();
            if ($basketCurrency !== null && isset($basketCurrency->name)) {
                $currency = (string) $basketCurrency->name;
            }
        }

        return BasketSnapshot::fromArray([
            'items' => $items,
            'discounts' => $discounts,
            'totalGross' => $totalGross,
            'totalNet' => $totalNet,
            'totalVat' => $totalVat,
            'currency' => $currency,
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);
    }
}
