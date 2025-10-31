<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Webhook;

interface WebhookIdempotencyCheckerInterface
{
    public function isProcessed(string $eventId): bool;

    public function markAsProcessed(string $eventId): void;
}
