<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Webhook;

class WebhookLog
{
    private readonly string $id;
    private ?string $eventType = null;
    private ?string $contractId = null;
    private ?string $error = null;

    public function __construct(
        private readonly string $eventId,
        private readonly \DateTimeImmutable $receivedAt,
        private readonly string $status
    ) {
        $this->id = uniqid('webhook_log_', true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getEventType(): ?string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): void
    {
        $this->eventType = $eventType;
    }

    public function getContractId(): ?string
    {
        return $this->contractId;
    }

    public function setContractId(string $contractId): void
    {
        $this->contractId = $contractId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(string $error): void
    {
        $this->error = $error;
    }
}
