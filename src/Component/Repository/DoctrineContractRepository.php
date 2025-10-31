<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;

class DoctrineContractRepository implements ContractRepositoryInterface
{
    private const TABLE_CONTRACTS = 'osc_payment_contract';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function save(PaymentContractInterface $contract): void
    {
        $this->connection->beginTransaction();

        try {
            $this->saveContract($contract);
            $this->connection->commit();
        } catch (Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function findById(string $id): ?PaymentContractInterface
    {
        $sql = 'SELECT * FROM ' . self::TABLE_CONTRACTS . ' WHERE OXID = :id';

        try {
            $data = $this->connection->fetchAssociative($sql, ['id' => $id]);

            if ($data === false) {
                return null;
            }

            return $this->hydrateContract($data);
        } catch (Exception $e) {
            return null;
        }
    }

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContractInterface
    {
        $sql = 'SELECT * FROM ' . self::TABLE_CONTRACTS . ' WHERE OXPROVIDERORDERID = :providerOrderId';

        try {
            $data = $this->connection->fetchAssociative($sql, ['providerOrderId' => $providerOrderId]);

            if ($data === false) {
                return null;
            }

            return $this->hydrateContract($data);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @return array<int, PaymentContractInterface>
     */
    public function findByUserId(string $userId): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE_CONTRACTS . ' WHERE OXUSERID = :userId ORDER BY OXCREATED DESC';

        try {
            $rows = $this->connection->fetchAllAssociative($sql, ['userId' => $userId]);

            return array_map(fn($row) => $this->hydrateContract($row), $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    public function findActiveByUserId(string $userId): ?PaymentContractInterface
    {
        $sql = 'SELECT * FROM ' . self::TABLE_CONTRACTS . '
                WHERE OXUSERID = :userId
                AND OXSTATE IN (:states)
                ORDER BY OXCREATED DESC
                LIMIT 1';

        try {
            $data = $this->connection->fetchAssociative(
                $sql,
                [
                    'userId' => $userId,
                    'states' => ['draft', 'pending', 'ready_to_commit', 'committed']
                ],
                [
                    'states' => Connection::PARAM_STR_ARRAY
                ]
            );

            if ($data === false) {
                return null;
            }

            return $this->hydrateContract($data);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @return array<int, PaymentContractInterface>
     */
    public function findExpired(?DateTimeInterface $before = null): array
    {
        $before = $before ?? new DateTime();

        $sql = 'SELECT * FROM ' . self::TABLE_CONTRACTS . '
                WHERE OXEXPIRESAT < :before
                AND OXSTATE NOT IN (:states)
                ORDER BY OXEXPIRESAT ASC';

        try {
            $rows = $this->connection->fetchAllAssociative(
                $sql,
                [
                    'before' => $before->format('Y-m-d H:i:s'),
                    'states' => ['fulfilled', 'cancelled', 'expired', 'failed']
                ],
                [
                    'states' => Connection::PARAM_STR_ARRAY
                ]
            );

            return array_map(fn($row) => $this->hydrateContract($row), $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * @throws Exception
     */
    private function saveContract(PaymentContractInterface $contract): void
    {
        $data = $this->prepareContractData($contract);

        $exists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE_CONTRACTS . ' WHERE OXID = :id',
            ['id' => $contract->getId()]
        );

        if ($exists > 0) {
            $this->connection->update(self::TABLE_CONTRACTS, $data, ['OXID' => $contract->getId()]);
        } else {
            $this->connection->insert(self::TABLE_CONTRACTS, $data);
        }
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    private function prepareContractData(PaymentContractInterface $contract): array
    {
        $contractArray = $contract->toArray();

        return [
            'OXID' => $contract->getId(),
            'OXSHOPID' => $contractArray['shopId'] ?? 1,
            'OXUSERID' => $contractArray['userId'] ?? '',
            'OXORDERID' => $contractArray['orderId'] ?? null,
            'OXSTATE' => $contract->getStateValue(),
            'OXSTATEREASON' => $contractArray['stateReason'] ?? null,
            'OXBASKETDATA' => json_encode($contractArray['basketSnapshot'] ?? []),
            'OXTERMS' => isset($contractArray['terms']) ? json_encode($contractArray['terms']) : null,
            'OXMETADATA' => isset($contractArray['metadata']) ? json_encode($contractArray['metadata']) : null,
            'OXCONDITIONS' => json_encode($contractArray['conditions'] ?? []),
            'OXPROVIDER' => $contractArray['provider'] ?? null,
            'OXPROVIDERORDERID' => $contractArray['providerOrderId'] ?? null,
            'OXPROVIDERDATA' => isset($contractArray['providerData']) ? json_encode($contractArray['providerData']) : null,
            'OXCREATED' => new DateTime($contractArray['createdAt'] ?? 'now'),
            'OXUPDATED' => new DateTime($contractArray['updatedAt'] ?? 'now'),
            'OXCOMMITTEDAT' => isset($contractArray['committedAt']) ? new DateTime($contractArray['committedAt']) : null,
            'OXFULFILLEDAT' => isset($contractArray['fulfilledAt']) ? new DateTime($contractArray['fulfilledAt']) : null,
            'OXEXPIRESAT' => isset($contractArray['expiresAt']) ? new DateTime($contractArray['expiresAt']) : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws Exception
     */
    private function hydrateContract(array $data): PaymentContractInterface
    {
        // Decode basket snapshot
        $basketData = json_decode((string) $data['OXBASKETDATA'], true);
        $basketSnapshot = BasketSnapshot::fromArray($basketData);

        // Decode conditions from JSON
        $conditionsData = json_decode((string) $data['OXCONDITIONS'], true) ?: [];
        $conditions = array_map(fn($condData) => ContractCondition::fromArray($condData), $conditionsData);

        // Create contract with basic data
        $contract = new PaymentContract(
            (int) $data['OXSHOPID'],
            (string) $data['OXUSERID'],
            $basketSnapshot,
            (string) $data['OXID']
        );

        // Use reflection to set private properties (as PaymentContract doesn't have setters)
        $reflection = new \ReflectionClass($contract);

        $this->setPrivateProperty($reflection, $contract, 'state', ContractState::from((string) $data['OXSTATE']));
        $this->setPrivateProperty($reflection, $contract, 'orderId', $data['OXORDERID']);
        $this->setPrivateProperty($reflection, $contract, 'provider', $data['OXPROVIDER']);
        $this->setPrivateProperty($reflection, $contract, 'providerOrderId', $data['OXPROVIDERORDERID']);
        $this->setPrivateProperty($reflection, $contract, 'expiresAt', $data['OXEXPIRESAT'] ? new DateTime($data['OXEXPIRESAT']) : null);
        $this->setPrivateProperty($reflection, $contract, 'createdAt', new DateTime($data['OXCREATED']));
        $this->setPrivateProperty($reflection, $contract, 'updatedAt', new DateTime($data['OXUPDATED']));
        $this->setPrivateProperty($reflection, $contract, 'committedAt', $data['OXCOMMITTEDAT'] ? new DateTime($data['OXCOMMITTEDAT']) : null);
        $this->setPrivateProperty($reflection, $contract, 'fulfilledAt', $data['OXFULFILLEDAT'] ? new DateTime($data['OXFULFILLEDAT']) : null);
        $this->setPrivateProperty($reflection, $contract, 'conditions', $conditions);

        return $contract;
    }

    /**
     * Set a private property value using reflection
     */
    private function setPrivateProperty(\ReflectionClass $reflection, object $object, string $propertyName, mixed $value): void
    {
        try {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue($object, $value);
        } catch (\ReflectionException $e) {
            // Property doesn't exist, skip
        }
    }
}
