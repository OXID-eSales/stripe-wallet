<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookLog;

interface WebhookLogRepositoryInterface
{
    public function save(WebhookLog $log): void;

    public function existsByEventId(string $eventId): bool;

    public function findByEventId(string $eventId): ?WebhookLog;
}
