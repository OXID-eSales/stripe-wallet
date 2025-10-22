<?php
// tests/Component/Integration/Migration/PaymentContractMigrationTest.php

namespace Tests\Component\Integration\Migration;

use Tests\Component\Support\IntegrationTestCase;

/**
 * Test contract table migration
 *
 * @group migration
 * @group database
 * @group contract
 */
class PaymentContractMigrationTest extends IntegrationTestCase
{
    /** @test */
    public function migration_001_creates_payment_contract_table(): void
    {
        // Arrange
        $this->dropAllTables();

        // Act
        $this->runMigration('001_create_payment_contract_table.sql');

        // Assert - Table exists
        $this->assertTableExists('osc_payment_contract');

        // Assert - Core columns
        $this->assertColumnExists('osc_payment_contract', 'OXID');
        $this->assertColumnExists('osc_payment_contract', 'OXUSERID');
        $this->assertColumnExists('osc_payment_contract', 'OXORDERID');
        $this->assertColumnExists('osc_payment_contract', 'OXSTATE');
        $this->assertColumnExists('osc_payment_contract', 'OXBASKETDATA');
        $this->assertColumnExists('osc_payment_contract', 'OXCONDITIONS');
        $this->assertColumnExists('osc_payment_contract', 'OXPROVIDERORDERID');

        // Assert - Column types
        $this->assertColumnType('osc_payment_contract', 'OXID', 'CHAR', 32);
        $this->assertColumnType('osc_payment_contract', 'OXBASKETDATA', 'JSON');
        $this->assertColumnType('osc_payment_contract', 'OXCONDITIONS', 'JSON');

        // Assert - Primary key
        $this->assertPrimaryKeyExists('osc_payment_contract', 'OXID');

        // Assert - Indexes
        $this->assertIndexExists('osc_payment_contract', 'IDX_STATE');
        $this->assertIndexExists('osc_payment_contract', 'IDX_USER');
        $this->assertIndexExists('osc_payment_contract', 'IDX_ORDER');
        $this->assertIndexExists('osc_payment_contract', 'IDX_PROVIDER_ORDER');
    }

    /** @test */
    public function migration_001_creates_foreign_keys(): void
    {
        $this->runMigration('001_create_payment_contract_table.sql');

        // FK to oxuser
        $this->assertForeignKeyExists(
            'osc_payment_contract',
            'FK_CONTRACT_USER',
            'OXUSERID',
            'oxuser',
            'OXID'
        );

        // FK to oxorder (NULL until committed!)
        $this->assertForeignKeyExists(
            'osc_payment_contract',
            'FK_CONTRACT_ORDER',
            'OXORDERID',
            'oxorder',
            'OXID'
        );

        // FK behavior
        $this->assertForeignKeyOnDelete('osc_payment_contract', 'FK_CONTRACT_USER', 'CASCADE');
        $this->assertForeignKeyOnDelete('osc_payment_contract', 'FK_CONTRACT_ORDER', 'SET NULL');
    }

    /** @test */
    public function contract_created_with_null_order_id(): void
    {
        $this->runMigration('001_create_payment_contract_table.sql');

        $userId = $this->createTestUser(['OXID' => 'user-123']);

        // Create contract WITHOUT order (order created later!)
        $contractId = $this->insertContract([
            'OXID' => 'contract-123',
            'OXUSERID' => 'user-123',
            'OXORDERID' => null,  // NULL until committed!
            'OXSTATE' => 'pending',
            'OXBASKETDATA' => '{"items":[], "totals": {"gross": 99.99}}',
            'OXCONDITIONS' => '[{"type":"payment_authorized","status":"pending"}]',
            'OXPROVIDERORDERID' => 'pi_stripe_123'
        ]);

        // Assert contract created
        $this->assertDatabaseHas('osc_payment_contract', [
            'OXID' => 'contract-123',
            'OXUSERID' => 'user-123',
            'OXORDERID' => null  // NULL!
        ]);
    }

    /** @test */
    public function contract_linked_to_order_when_committed(): void
    {
        $this->runMigration('001_create_payment_contract_table.sql');

        $userId = $this->createTestUser(['OXID' => 'user-123']);
        $orderId = $this->createTestOrder(['OXID' => 'order-123', 'OXUSERID' => 'user-123']);

        $contractId = $this->insertContract([
            'OXID' => 'contract-123',
            'OXUSERID' => 'user-123',
            'OXORDERID' => null,
            'OXSTATE' => 'pending',
            'OXBASKETDATA' => '{}',
            'OXCONDITIONS' => '[]'
        ]);

        // Update contract to link order (commit)
        $this->updateContract('contract-123', [
            'OXORDERID' => 'order-123',
            'OXSTATE' => 'committed'
        ]);

        // Assert order linked
        $this->assertDatabaseHas('osc_payment_contract', [
            'OXID' => 'contract-123',
            'OXORDERID' => 'order-123',
            'OXSTATE' => 'committed'
        ]);
    }

    /** @test */
    public function contract_deleted_when_user_deleted_cascade(): void
    {
        $this->runMigration('001_create_payment_contract_table.sql');

        $userId = $this->createTestUser(['OXID' => 'user-123']);
        $contractId = $this->insertContract([
            'OXID' => 'contract-123',
            'OXUSERID' => 'user-123',
            'OXORDERID' => null,
            'OXSTATE' => 'draft',
            'OXBASKETDATA' => '{}',
            'OXCONDITIONS' => '[]'
        ]);

        // Delete user
        $this->deleteUser('user-123');

        // Contract should be cascade deleted
        $this->assertDatabaseNotHas('osc_payment_contract', ['OXID' => 'contract-123']);
    }

    /** @test */
    public function contract_order_id_set_to_null_when_order_deleted(): void
    {
        $this->runMigration('001_create_payment_contract.sql');

        $userId = $this->createTestUser(['OXID' => 'user-123']);
        $orderId = $this->createTestOrder(['OXID' => 'order-123', 'OXUSERID' => 'user-123']);

        $contractId = $this->insertContract([
            'OXID' => 'contract-123',
            'OXUSERID' => 'user-123',
            'OXORDERID' => 'order-123',
            'OXSTATE' => 'committed',
            'OXBASKETDATA' => '{}',
            'OXCONDITIONS' => '[]'
        ]);

        // Delete order
        $this->deleteOrder('order-123');

        // Contract still exists but OXORDERID set to NULL
        $this->assertDatabaseHas('osc_payment_contract', [
            'OXID' => 'contract-123',
            'OXORDERID' => null  // SET NULL!
        ]);
    }
}
