<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler\Support;

use OxidSolutionCatalysts\Payments\Component\Repository\OrderRepositoryInterface;

class InMemoryOrderRepository implements OrderRepositoryInterface
{
    private array $orders = [];
    private int $nextId = 1;
    private int $nextOrderNumber = 1000;

    public function save(object $order): void
    {
        if ($order instanceof Order) {
            $this->orders[$order->getId()] = $order;
        }
    }

    public function findById(int $id): ?object
    {
        return $this->orders[$id] ?? null;
    }

    public function findAll(): array
    {
        return array_values($this->orders);
    }

    public function generateNextId(): int
    {
        return $this->nextId++;
    }

    public function generateNextOrderNumber(): string
    {
        return (string) $this->nextOrderNumber++;
    }
}
