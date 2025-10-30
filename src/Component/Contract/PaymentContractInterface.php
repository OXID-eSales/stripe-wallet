<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Contract;

/**
 * Payment contract capturing purchase intent before order creation.
 *
 * States: DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
 * Or: CANCELLED | EXPIRED | FAILED
 */
interface PaymentContractInterface
{
    public function getId(): string;

    public function getStateValue(): string;

    public function getAmount(): float;

    public function getCurrency(): string;

    public function isInState(string $state): bool;

    public function getOrderId(): ?string;

    public function getProviderOrderId(): ?string;

    public function getCreatedAt(): \DateTimeInterface;

    public function getUpdatedAt(): \DateTimeInterface;
}
