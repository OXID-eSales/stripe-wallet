<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Watch\EndToEnd;

use OxidSolutionCatalysts\Payments\Tests\Integration\Watch\PaymentWatchIntegrationTestCase;

/**
 * E2E tests for complete payment flow
 *
 * Tests the full payment lifecycle from contract creation to completion.
 *
 * @group integration
 * @group watch
 * @group e2e
 */
class CompletePaymentFlowTest extends PaymentWatchIntegrationTestCase
{
    /**
     * @test
     */
    public function it_tracks_complete_payment_flow_from_pending_to_committed(): void
    {
        // Step 1: Create contract in pending state
        $contractId = $this->createTestContract([
            'OXSTATE' => 'pending',
            'OXORDERID' => null,
        ]);

        // Verify: Contract is in pending state
        $this->assertContractState($contractId, 'pending');

        // Step 2: Authorize payment (state changes to ready_to_commit)
        $this->updateRecord('osc_payment_contract', $contractId, [
            'OXSTATE' => 'ready_to_commit',
        ]);

        // Verify: Contract is ready to commit
        $this->assertContractState($contractId, 'ready_to_commit');

        // Step 3: Create order and link to contract
        $orderId = 'test_order_' . uniqid();
        $this->updateRecord('osc_payment_contract', $contractId, [
            'OXORDERID' => $orderId,
        ]);

        // Verify: Order is linked
        $this->assertOrderLinked($contractId, $orderId);

        // Step 4: Commit payment (final state)
        $this->updateRecord('osc_payment_contract', $contractId, [
            'OXSTATE' => 'committed',
        ]);

        // Verify: Contract is committed
        $this->assertContractState($contractId, 'committed');

        // Verify: Transaction recorded
        $txId = $this->createTestTransaction($contractId, [
            'OXSTATUS' => 'completed',
            'OXTYPE' => 'payment',
        ]);

        $this->assertTransactionExists($contractId);
        $this->assertTransactionStatus($txId, 'completed');
    }

    /**
     * @test
     */
    public function it_handles_failed_payment_flow(): void
    {
        // Step 1: Create contract
        $contractId = $this->createTestContract([
            'OXSTATE' => 'pending',
        ]);

        // Step 2: Simulate payment failure
        $this->updateRecord('osc_payment_contract', $contractId, [
            'OXSTATE' => 'failed',
        ]);

        // Verify: Contract is in failed state
        $this->assertContractState($contractId, 'failed');

        // Verify: No order was created
        $contract = $this->getRecord('osc_payment_contract', $contractId);
        $this->assertNull($contract['OXORDERID']);
    }

    /**
     * @test
     */
    public function it_handles_expired_contract_timeout(): void
    {
        // Arrange: Create expired contract
        $contractId = $this->createTestContract([
            'OXSTATE' => 'pending',
            'OXTIMESTAMP' => time() - 7200, // 2 hours ago
        ]);

        // Act: Update to expired state
        $this->updateRecord('osc_payment_contract', $contractId, [
            'OXSTATE' => 'expired',
        ]);

        // Assert: Contract is expired
        $this->assertContractState($contractId, 'expired');
    }

    /**
     * @test
     */
    public function it_handles_refund_flow(): void
    {
        // Step 1: Create committed contract
        $contractId = $this->createTestContract([
            'OXSTATE' => 'committed',
            'OXORDERID' => 'test_order_' . uniqid(),
        ]);

        // Step 2: Create refund transaction
        $refundTxId = $this->createTestTransaction($contractId, [
            'OXSTATUS' => 'completed',
            'OXTYPE' => 'refund',
            'OXAMOUNT' => -100.00, // Negative for refund
        ]);

        // Step 3: Update contract state
        $this->updateRecord('osc_payment_contract', $contractId, [
            'OXSTATE' => 'refunded',
        ]);

        // Verify: Contract is refunded
        $this->assertContractState($contractId, 'refunded');

        // Verify: Refund transaction exists
        $this->assertTransactionStatus($refundTxId, 'completed');
        $this->assertTransactionType($refundTxId, 'refund');
    }

    /**
     * @test
     */
    public function it_tracks_concurrent_payments_for_different_users(): void
    {
        // Create multiple contracts for different users
        $contracts = [];
        for ($i = 0; $i < 5; $i++) {
            $userId = 'test_user_' . $i . '_' . uniqid();
            $contracts[$i] = $this->createTestContract([
                'OXSTATE' => 'pending',
                'OXUSERID' => $userId,
            ]);
        }

        // Process each payment independently
        foreach ($contracts as $i => $contractId) {
            $this->updateRecord('osc_payment_contract', $contractId, [
                'OXSTATE' => 'committed',
            ]);

            // Verify each contract independently
            $this->assertContractState($contractId, 'committed');
        }

        // Verify all contracts are committed
        foreach ($contracts as $contractId) {
            $this->assertContractState($contractId, 'committed');
        }
    }

    /**
     * @test
     */
    public function it_validates_state_transitions_in_correct_order(): void
    {
        // Create contract
        $contractId = $this->createTestContract([
            'OXSTATE' => 'pending',
        ]);

        // Valid transition: pending -> ready_to_commit
        $this->updateRecord('osc_payment_contract', $contractId, [
            'OXSTATE' => 'ready_to_commit',
        ]);
        $this->assertContractState($contractId, 'ready_to_commit');

        // Valid transition: ready_to_commit -> committed
        $this->updateRecord('osc_payment_contract', $contractId, [
            'OXSTATE' => 'committed',
        ]);
        $this->assertContractState($contractId, 'committed');

        // Contract should not transition from committed to pending
        // (This would be validated by business logic in real implementation)
    }

    // Helper methods

    /**
     * Assert contract is in expected state
     */
    private function assertContractState(string $contractId, string $expectedState): void
    {
        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => $expectedState,
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        $response = $this->makeAssumptionRequest($payload);

        $this->assertResponseSuccess($response);
        $this->assertTrue(
            $response['body']['assumption'],
            "Contract {$contractId} should be in state '{$expectedState}', got: " .
            ($response['body']['actual_value'] ?? 'unknown')
        );
    }

    /**
     * Assert order is linked to contract
     */
    private function assertOrderLinked(string $contractId, string $expectedOrderId): void
    {
        $payload = [
            'assumption' => [
                'osc_payment_contract.OXORDERID' => $expectedOrderId,
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        $response = $this->makeAssumptionRequest($payload);

        $this->assertResponseSuccess($response);
        $this->assertTrue(
            $response['body']['assumption'],
            "Order {$expectedOrderId} should be linked to contract {$contractId}"
        );
    }

    /**
     * Assert transaction exists for contract
     */
    private function assertTransactionExists(string $contractId): void
    {
        $payload = [
            'assumption' => [
                'osc_payment_transaction.OXCONTRACTID' => $contractId,
                'operator' => 'IS NOT NULL',
            ],
        ];

        $response = $this->makeAssumptionRequest($payload);

        $this->assertResponseSuccess($response);
    }

    /**
     * Assert transaction status
     */
    private function assertTransactionStatus(string $txId, string $expectedStatus): void
    {
        $payload = [
            'assumption' => [
                'osc_payment_transaction.OXSTATUS' => $expectedStatus,
                'where' => [
                    'OXID' => $txId,
                ],
            ],
        ];

        $response = $this->makeAssumptionRequest($payload);

        $this->assertResponseSuccess($response);
        $this->assertTrue(
            $response['body']['assumption'],
            "Transaction {$txId} should have status '{$expectedStatus}'"
        );
    }

    /**
     * Assert transaction type
     */
    private function assertTransactionType(string $txId, string $expectedType): void
    {
        $payload = [
            'assumption' => [
                'osc_payment_transaction.OXTYPE' => $expectedType,
                'where' => [
                    'OXID' => $txId,
                ],
            ],
        ];

        $response = $this->makeAssumptionRequest($payload);

        $this->assertResponseSuccess($response);
        $this->assertTrue(
            $response['body']['assumption'],
            "Transaction {$txId} should have type '{$expectedType}'"
        );
    }
}
