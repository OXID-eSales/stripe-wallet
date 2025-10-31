<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Contract;

class BasketSnapshot
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $items;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $discounts;
    private float $totalGross;
    private float $totalNet;
    private float $totalVat;
    private string $currency;
    private \DateTimeInterface $capturedAt;

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $discounts
     */
    private function __construct(
        array $items,
        array $discounts,
        float $totalGross,
        float $totalNet,
        float $totalVat,
        string $currency,
        \DateTimeInterface $capturedAt
    ) {
        $this->items = $items;
        $this->discounts = $discounts;
        $this->totalGross = $totalGross;
        $this->totalNet = $totalNet;
        $this->totalVat = $totalVat;
        $this->currency = $currency;
        $this->capturedAt = $capturedAt;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $capturedAt = isset($data['capturedAt'])
            ? new \DateTime($data['capturedAt'])
            : new \DateTime();

        return new self(
            items: $data['items'] ?? [],
            discounts: $data['discounts'] ?? [],
            totalGross: (float) $data['totalGross'],
            totalNet: (float) $data['totalNet'],
            totalVat: (float) $data['totalVat'],
            currency: $data['currency'],
            capturedAt: $capturedAt
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDiscounts(): array
    {
        return $this->discounts;
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

    public function getCapturedAt(): \DateTimeInterface
    {
        return $this->capturedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'discounts' => $this->discounts,
            'totalGross' => $this->totalGross,
            'totalNet' => $this->totalNet,
            'totalVat' => $this->totalVat,
            'currency' => $this->currency,
            'capturedAt' => $this->capturedAt->format('Y-m-d H:i:s'),
        ];
    }
}
