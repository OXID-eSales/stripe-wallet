<?php

declare(strict_types=1);

namespace OxidEsales\StripeWallet\Component\EventSystem\Event;

/**
 * Event dispatched when customer address is updated during checkout
 *
 * This event allows other components to react to address changes,
 * such as recalculating shipping costs, validating tax regions, etc.
 */
class AddressUpdatedEvent
{
    public function __construct(
        private readonly string $customerId,
        private readonly array $billingAddress,
        private readonly ?array $shippingAddress = null
    ) {
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getBillingAddress(): array
    {
        return $this->billingAddress;
    }

    public function getShippingAddress(): ?array
    {
        return $this->shippingAddress;
    }

    public function hasShippingAddress(): bool
    {
        return $this->shippingAddress !== null;
    }
}
