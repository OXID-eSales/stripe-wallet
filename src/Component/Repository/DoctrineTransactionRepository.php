<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Component\Transaction\Transaction;

/**
 * Doctrine DBAL implementation of TransactionRepositoryInterface
 *
 * Provider-agnostic repository for payment transaction persistence.
 */
class DoctrineTransactionRepository implements TransactionRepositoryInterface
{
    private const TABLE_NAME = 'osc_payment_transaction';

    public function __construct(
        private Connection $connection
    ) {
    }

    public function save(Transaction $transaction): void
    {
        $data = $this->prepareTransactionData($transaction);

        if ($this->exists($transaction->getId())) {
            $this->connection->update(self::TABLE_NAME, $data, ['OXID' => $transaction->getId()]);
        } else {
            $this->connection->insert(self::TABLE_NAME, $data);
        }
    }

    public function findById(string $id): ?Transaction
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXID = :id';
        $data = $this->connection->fetchAssociative($sql, ['id' => $id]);

        if ($data === false) {
            return null;
        }

        return $this->hydrateTransaction($data);
    }

    /**
     * @return Transaction[]
     */
    public function findByOrderId(string $orderId): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXORDERID = :orderId ORDER BY OXCREATED ASC';
        $rows = $this->connection->fetchAllAssociative($sql, ['orderId' => $orderId]);

        return array_map(fn($row) => $this->hydrateTransaction($row), $rows);
    }

    /**
     * @return Transaction[]
     */
    public function findByContractId(string $contractId): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXCONTRACTID = :contractId ORDER BY OXCREATED ASC';
        $rows = $this->connection->fetchAllAssociative($sql, ['contractId' => $contractId]);

        return array_map(fn($row) => $this->hydrateTransaction($row), $rows);
    }

    public function findByProviderTransactionId(string $transactionId): ?Transaction
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXTRANSACTIONID = :transactionId';
        $data = $this->connection->fetchAssociative($sql, ['transactionId' => $transactionId]);

        if ($data === false) {
            return null;
        }

        return $this->hydrateTransaction($data);
    }

    /**
     * @return Transaction[]
     */
    public function findByTypeAndStatus(string $type, string $status): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXTYPE = :type AND OXSTATUS = :status ORDER BY OXCREATED DESC';
        $rows = $this->connection->fetchAllAssociative($sql, [
            'type' => $type,
            'status' => $status
        ]);

        return array_map(fn($row) => $this->hydrateTransaction($row), $rows);
    }

    /**
     * @return Transaction[]
     */
    public function findChildTransactions(string $parentTransactionId): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXPARENTTRANSACTIONID = :parentId ORDER BY OXCREATED ASC';
        $rows = $this->connection->fetchAllAssociative($sql, ['parentId' => $parentTransactionId]);

        return array_map(fn($row) => $this->hydrateTransaction($row), $rows);
    }

    public function exists(string $id): bool
    {
        $sql = 'SELECT COUNT(*) FROM ' . self::TABLE_NAME . ' WHERE OXID = :id';
        $count = (int) $this->connection->fetchOne($sql, ['id' => $id]);

        return $count > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareTransactionData(Transaction $transaction): array
    {
        $transactionArray = $transaction->toArray();

        return [
            'OXID' => $transactionArray['id'],
            'OXSHOPID' => $transactionArray['shopId'],
            'OXORDERID' => $transactionArray['orderId'],
            'OXCONTRACTID' => $transactionArray['contractId'],
            'OXPROVIDER' => $transactionArray['provider'],
            'OXPROVIDERORDERID' => $transactionArray['providerOrderId'],
            'OXTRANSACTIONID' => $transactionArray['transactionId'],
            'OXTYPE' => $transactionArray['type'],
            'OXSTATUS' => $transactionArray['status'],
            'OXAMOUNT' => $transactionArray['amount'],
            'OXCURRENCY' => $transactionArray['currency'],
            'OXPAYMENTMETHODID' => $transactionArray['paymentMethodId'] ?? null,
            'OXPAYMENTMETHODTYPE' => $transactionArray['paymentMethodType'] ?? null,
            'OXPARENTTRANSACTIONID' => $transactionArray['parentTransactionId'] ?? null,
            'OXCREATED' => $transactionArray['createdAt'],
            'OXUPDATED' => $transactionArray['updatedAt'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateTransaction(array $data): Transaction
    {
        return Transaction::fromArray([
            'id' => (string) $data['OXID'],
            'shopId' => (int) $data['OXSHOPID'],
            'orderId' => (string) $data['OXORDERID'],
            'contractId' => $data['OXCONTRACTID'] ? (string) $data['OXCONTRACTID'] : null,
            'provider' => (string) $data['OXPROVIDER'],
            'providerOrderId' => $data['OXPROVIDERORDERID'] ? (string) $data['OXPROVIDERORDERID'] : null,
            'transactionId' => $data['OXTRANSACTIONID'] ? (string) $data['OXTRANSACTIONID'] : null,
            'type' => (string) $data['OXTYPE'],
            'status' => (string) $data['OXSTATUS'],
            'amount' => (float) $data['OXAMOUNT'],
            'currency' => (string) $data['OXCURRENCY'],
            'paymentMethodId' => $data['OXPAYMENTMETHODID'] ? (string) $data['OXPAYMENTMETHODID'] : null,
            'paymentMethodType' => $data['OXPAYMENTMETHODTYPE'] ? (string) $data['OXPAYMENTMETHODTYPE'] : null,
            'parentTransactionId' => $data['OXPARENTTRANSACTIONID'] ? (string) $data['OXPARENTTRANSACTIONID'] : null,
            'createdAt' => (string) $data['OXCREATED'],
            'updatedAt' => (string) $data['OXUPDATED'],
        ]);
    }
}
