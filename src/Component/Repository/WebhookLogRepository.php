<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookLog;

class WebhookLogRepository implements WebhookLogRepositoryInterface
{
    /**
     * @var array<string, WebhookLog>
     */
    private array $logs = [];

    /**
     * @var array<string, string>
     */
    private array $eventIndex = [];

    public function save(WebhookLog $log): void
    {
        $this->logs[$log->getId()] = $log;
        $this->eventIndex[$log->getEventId()] = $log->getId();
    }

    public function existsByEventId(string $eventId): bool
    {
        return isset($this->eventIndex[$eventId]);
    }

    public function findByEventId(string $eventId): ?WebhookLog
    {
        $logId = $this->eventIndex[$eventId] ?? null;
        return $logId ? $this->logs[$logId] : null;
    }
}
