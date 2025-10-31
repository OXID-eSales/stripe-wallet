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
        if (!isset($data['totalGross'], $data['totalNet'], $data['totalVat'], $data['currency'])) {
            throw new \InvalidArgumentException('Required basket data is missing');
        }

        $capturedAt = isset($data['capturedAt']) && is_string($data['capturedAt'])
            ? new \DateTime($data['capturedAt'])
            : new \DateTime();

        /** @var array<int, array<string, mixed>> $items */
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

        /** @var array<int, array<string, mixed>> $discounts */
        $discounts = isset($data['discounts']) && is_array($data['discounts']) ? $data['discounts'] : [];

        return new self(
            items: $items,
            discounts: $discounts,
            totalGross: is_float($data['totalGross']) || is_int($data['totalGross']) ? (float) $data['totalGross'] : (float) $data['totalGross'],
            totalNet: is_float($data['totalNet']) || is_int($data['totalNet']) ? (float) $data['totalNet'] : (float) $data['totalNet'],
            totalVat: is_float($data['totalVat']) || is_int($data['totalVat']) ? (float) $data['totalVat'] : (float) $data['totalVat'],
            currency: is_string($data['currency']) ? $data['currency'] : (string) $data['currency'],
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
