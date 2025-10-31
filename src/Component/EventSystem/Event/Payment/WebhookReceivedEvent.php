<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContextInterface;

readonly class WebhookReceivedEvent implements WebhookReceivedEventInterface
{
    public function __construct(
        private EventContextInterface $context,
        private string $provider,
        private string $eventType,
        private array $payload,
        private string $signature
    ) {
    }

    public function getContext(): EventContextInterface
    {
        return $this->context;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }
}
