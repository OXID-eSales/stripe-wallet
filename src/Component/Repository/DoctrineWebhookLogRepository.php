<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookLog;

class DoctrineWebhookLogRepository implements WebhookLogRepositoryInterface
{
    private const TABLE_NAME = 'osc_payment_webhooklogs';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function save(WebhookLog $log): void
    {
        try {
            $data = [
                'OXID' => $log->getId(),
                'OXEVENTID' => $log->getEventId(),
                'OXEVENTTYPE' => $log->getEventType(),
                'OXCONTRACTID' => $log->getContractId(),
                'OXSTATUS' => $log->getStatus(),
                'OXRECEIVEDAT' => $log->getReceivedAt()->format('Y-m-d H:i:s'),
                'OXERROR' => $log->getError(),
            ];

            $exists = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . self::TABLE_NAME . ' WHERE OXID = :id',
                ['id' => $log->getId()]
            );

            if ($exists > 0) {
                $this->connection->update(self::TABLE_NAME, $data, ['OXID' => $log->getId()]);
            } else {
                $this->connection->insert(self::TABLE_NAME, $data);
            }
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to save webhook log: ' . $e->getMessage(), 0, $e);
        }
    }

    public function existsByEventId(string $eventId): bool
    {
        try {
            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . self::TABLE_NAME . ' WHERE OXEVENTID = :eventId',
                ['eventId' => $eventId]
            );

            return $count > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    public function findByEventId(string $eventId): ?WebhookLog
    {
        try {
            $data = $this->connection->fetchAssociative(
                'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXEVENTID = :eventId',
                ['eventId' => $eventId]
            );

            if ($data === false) {
                return null;
            }

            return $this->hydrateWebhookLog($data);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateWebhookLog(array $data): WebhookLog
    {
        $log = new WebhookLog(
            (string) $data['OXEVENTID'],
            new DateTimeImmutable($data['OXRECEIVEDAT']),
            (string) $data['OXSTATUS']
        );

        // Use reflection to set the readonly id property
        $reflection = new \ReflectionClass($log);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($log, $data['OXID']);

        // Set optional properties if they exist
        if (!empty($data['OXEVENTTYPE'])) {
            $log->setEventType((string) $data['OXEVENTTYPE']);
        }

        if (!empty($data['OXCONTRACTID'])) {
            $log->setContractId((string) $data['OXCONTRACTID']);
        }

        if (!empty($data['OXERROR'])) {
            $log->setError((string) $data['OXERROR']);
        }

        return $log;
    }
}
