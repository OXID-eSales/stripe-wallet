<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Component\EventSystem\Event;

/**
 * Event dispatched when a payment is initiated by the customer
 *
 * This event is fired after the customer submits payment data
 * and before the actual payment processing begins.
 */
class PaymentInitiatedEvent
{
    public function __construct(
        private readonly string $contractId,
        private readonly array $paymentData,
        private readonly string $customerId,
        private readonly float $amount,
        private readonly string $currency,
        private readonly ?string $returnUrl = null,
        private readonly bool $saveCard = false
    ) {
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }

    public function getPaymentData(): array
    {
        return $this->paymentData;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getReturnUrl(): ?string
    {
        return $this->returnUrl;
    }

    public function shouldSaveCard(): bool
    {
        return $this->saveCard;
    }

    /**
     * Get payment method details from decrypted data
     */
    public function getPaymentMethod(): ?string
    {
        return $this->paymentData['paymentMethod'] ?? null;
    }

    /**
     * Get card details (if available and decrypted)
     */
    public function getCardDetails(): ?array
    {
        return $this->paymentData['card'] ?? null;
    }
}
