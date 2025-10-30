<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler\Support;

class Order
{
    private int $id;
    private string $orderNumber;
    private string $userId;
    private float $totalGross;
    private float $totalNet;
    private float $totalVat;
    private string $currency;
    private array $items;
    private string $contractId;
    private string $status;
    private \DateTimeInterface $createdAt;

    public function __construct(
        int $id,
        string $orderNumber,
        string $userId,
        float $totalGross,
        float $totalNet,
        float $totalVat,
        string $currency,
        array $items,
        string $contractId
    ) {
        $this->id = $id;
        $this->orderNumber = $orderNumber;
        $this->userId = $userId;
        $this->totalGross = $totalGross;
        $this->totalNet = $totalNet;
        $this->totalVat = $totalVat;
        $this->currency = $currency;
        $this->items = $items;
        $this->contractId = $contractId;
        $this->status = 'pending';
        $this->createdAt = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getTotalGross(): float
    {
        return $this->totalGross;
    }

    public function getTotalNet(): float
    {
        return $this->totalNet;
    }

    public function getTotalVat(): float
    {
        return $this->totalVat;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
