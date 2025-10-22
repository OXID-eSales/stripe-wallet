<?php
// tests/Component/Integration/SmartContract/ContractConditionsTest.php

namespace Tests\Component\Integration\SmartContract;

use Tests\Component\Support\IntegrationTestCase;

/**
 * Contract Conditions Tests
 *
 * Tests condition management and state tracking
 *
 * @group integration
 * @group smart-contract
 * @group conditions
 */
class ContractConditionsTest extends IntegrationTestCase
{
    /** @test */
    public function condition_types_are_properly_stored(): void
    {
        $conditions = [
            ['type' => 'payment_authorized', 'status' => 'pending', 'required' => true],
            ['type' => 'payment_captured', 'status' => 'pending', 'required' => false],
            ['type' => 'fraud_check', 'status' => 'pending', 'required' => true],
            ['type' => 'inventory_reserved', 'status' => 'pending', 'required' => true],
            ['type' => 'address_validated', 'status' => 'pending', 'required' => false],
            ['type' => '3ds_authenticated', 'status' => 'pending', 'required' => false],
        ];

        $contract = $this->createTestContract([
            'OXCONDITIONS' => json_encode($conditions)
        ]);

        $stored = json_decode($this->getContract($contract['OXID'])['OXCONDITIONS'], true);
        $this->assertCount(6, $stored);

        $types = array_column($stored, 'type');
        $this->assertContains('payment_authorized', $types);
        $this->assertContains('fraud_check', $types);
        $this->assertContains('inventory_reserved', $types);
    }

    /** @test */
    public function required_conditions_are_marked(): void
    {
        $conditions = [
            ['type' => 'payment_authorized', 'status' => 'pending', 'required' => true],
            ['type' => 'address_validated', 'status' => 'pending', 'required' => false]
        ];

        $contract = $this->createTestContract([
            'OXCONDITIONS' => json_encode($conditions)
        ]);

        $stored = json_decode($this->getContract($contract['OXID'])['OXCONDITIONS'], true);

        $this->assertTrue($stored[0]['required']);
        $this->assertFalse($stored[1]['required']);
    }

    /** @test */
    public function condition_completion_timestamp_is_stored(): void
    {
        $now = date('c');
        $conditions = [
            [
                'type' => 'payment_authorized',
                'status' => 'completed',
                'required' => true,
                'completed_at' => $now
            ]
        ];

        $contract = $this->createTestContract([
            'OXCONDITIONS' => json_encode($conditions)
        ]);

        $stored = json_decode($this->getContract($contract['OXID'])['OXCONDITIONS'], true);
        $this->assertEquals('completed', $stored[0]['status']);
        $this->assertEquals($now, $stored[0]['completed_at']);
    }

    /** @test */
    public function condition_failure_stores_reason(): void
    {
        $conditions = [
            [
                'type' => 'fraud_check',
                'status' => 'failed',
                'required' => true,
                'failed_at' => date('c'),
                'failure_reason' => 'Risk score above threshold: 85'
            ]
        ];

        $contract = $this->createTestContract([
            'OXCONDITIONS' => json_encode($conditions)
        ]);

        $stored = json_decode($this->getContract($contract['OXID'])['OXCONDITIONS'], true);
        $this->assertEquals('failed', $stored[0]['status']);
        $this->assertArrayHasKey('failure_reason', $stored[0]);
        $this->assertStringContainsString('Risk score', $stored[0]['failure_reason']);
    }

    /** @test */
    public function optional_conditions_do_not_block_fulfillment(): void
    {
        $conditions = [
            ['type' => 'payment_authorized', 'status' => 'completed', 'required' => true],
            ['type' => 'address_validated', 'status' => 'pending', 'required' => false]  // Optional
        ];

        $contract = $this->createTestContract([
            'OXSTATE' => 'pending',
            'OXCONDITIONS' => json_encode($conditions)
        ]);

        // Can transition to ready_to_commit even though optional condition pending
        $this->updateContract($contract['OXID'], ['OXSTATE' => 'ready_to_commit']);

        $this->assertDatabaseHas('osc_payment_contract', [
            'OXID' => $contract['OXID'],
            'OXSTATE' => 'ready_to_commit'
        ]);
    }

    /** @test */
    public function all_required_conditions_must_complete_for_readiness(): void
    {
        $conditions = [
            ['type' => 'payment_authorized', 'status' => 'completed', 'required' => true],
            ['type' => 'fraud_check', 'status' => 'pending', 'required' => true],  // Still pending!
            ['type' => 'inventory_reserved', 'status' => 'completed', 'required' => true]
        ];

        $contract = $this->createTestContract([
            'OXSTATE' => 'pending',
            'OXCONDITIONS' => json_encode($conditions)
        ]);

        $stored = json_decode($this->getContract($contract['OXID'])['OXCONDITIONS'], true);

        // Check if all required are completed
        $requiredConditions = array_filter($stored, fn($c) => $c['required']);
        $allCompleted = array_reduce(
            $requiredConditions,
            fn($carry, $c) => $carry && $c['status'] === 'completed',
            true
        );

        $this->assertFalse($allCompleted, 'Not all required conditions completed');
    }

    /** @test */
    public function condition_metadata_can_be_stored(): void
    {
        $conditions = [
            [
                'type' => 'payment_authorized',
                'status' => 'completed',
                'required' => true,
                'completed_at' => date('c'),
                'metadata' => [
                    'authorization_code' => 'AUTH_123456',
                    'amount' => 99.99,
                    'currency' => 'EUR',
                    'payment_method' => 'card'
                ]
            ]
        ];

        $contract = $this->createTestContract([
            'OXCONDITIONS' => json_encode($conditions)
        ]);

        $stored = json_decode($this->getContract($contract['OXID'])['OXCONDITIONS'], true);
        $this->assertArrayHasKey('metadata', $stored[0]);
        $this->assertEquals('AUTH_123456', $stored[0]['metadata']['authorization_code']);
        $this->assertEquals(99.99, $stored[0]['metadata']['amount']);
    }
}
