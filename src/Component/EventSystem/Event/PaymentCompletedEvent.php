<?php

declare(strict_types=1);

namespace OxidEsales\StripeWallet\Component\EventSystem\Event;

/**
 * Event dispatched when a payment is successfully completed
 *
 * This event is fired after the payment has been processed
 * and confirmed by the payment provider (Stripe).
 *
 * Subscribers can:
 * - Send confirmation emails
 * - Update order status
 * - Log transactions
 * - Trigger fulfillment workflows
 */
class PaymentCompletedEvent
{
    public function __construct(
        private readonly string $contractId,
        private readonly string $orderId,
        private readonly string $transactionId,
        private readonly float $amount,
        private readonly string $currency,
        private readonly string $status,
        private readonly array $metadata = []
    ) {
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }

    public function requiresAction(): bool
    {
        return $this->status === 'requires_action';
    }
}
