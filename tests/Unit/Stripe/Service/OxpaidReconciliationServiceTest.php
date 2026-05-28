<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentBase\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\OxpaidReconciliationService;
use OxidEsales\Payments\Stripe\Service\Result\ReconciliationResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OxpaidReconciliationService
 *
 * Sprint 10: Tests for OXPAID reconciliation with Stripe API.
 * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\OxpaidReconciliationService
 * @group sprint-10
 * @group sprint-14
 * @group sprint-15
 * @group sprint-18
 * @group reconciliation
 */
class OxpaidReconciliationServiceTest extends TestCase
{
    private Connection $connection;
    private StripeAdapterFactoryInterface $adapterFactory;
    private ContractRepositoryInterface $contractRepository;
    private ContractFulfillmentServiceInterface $contractFulfillmentService;
    private FileLoggerInterface $fileLogger;  // Sprint 14: LSP - type-hint interface
    private OxpaidReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createMock(Connection::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        // Sprint 18: Mock ContractFulfillmentService
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        // Sprint 14: Mock FileLogger - prevents tests writing to production log files
        $this->fileLogger = $this->createMock(FileLoggerInterface::class);

        $this->service = new OxpaidReconciliationService(
            $this->connection,
            $this->adapterFactory,
            $this->contractRepository,
            $this->contractFulfillmentService,  // Sprint 18
            $this->fileLogger  // Sprint 14: Inject mock - tests isolated from filesystem
        );
    }

    /**
     * @test
     * Verifies the service returns an empty array when no unpaid orders exist.
     */
    public function findUnpaidOrdersReturnsEmptyArrayWhenNoneExist(): void
    {
        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $result = $this->service->findUnpaidOrders(1);

        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function findUnpaidOrdersQueriesCorrectCriteria(): void
    {
        $expectedOrders = [
            ['OXID' => 'order1', 'OXTRANSID' => 'pi_123', 'OXORDERNR' => 1, 'OXORDERDATE' => '2025-12-05'],
            ['OXID' => 'order2', 'OXTRANSID' => 'pi_456', 'OXORDERNR' => 2, 'OXORDERDATE' => '2025-12-04'],
        ];

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->stringContains("OXPAID = '0000-00-00 00:00:00'"),
                $this->equalTo([7])
            )
            ->willReturn($expectedOrders);

        $result = $this->service->findUnpaidOrders(7);

        $this->assertCount(2, $result);
        $this->assertEquals('order1', $result[0]['OXID']);
        $this->assertEquals('pi_123', $result[0]['OXTRANSID']);
    }

    /**
     * @test
     */
    public function findUnpaidOrdersReturnsEmptyArrayWhenNoOrders(): void
    {
        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $result = $this->service->findUnpaidOrders();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @test
     * Sprint 15: NO_CONTRACT is ERROR - contract is required for reconciliation
     */
    public function reconcileOrderFailsWithoutContract(): void
    {
        $orderId = 'test_order_no_contract';
        $paymentIntentId = 'pi_no_contract';

        // Given: No contract exists
        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn(null);

        // Should NOT call Stripe API without contract
        $this->adapterFactory
            ->expects($this->never())
            ->method('createDefaultAdapter');

        // Should NOT update OXPAID without contract
        $this->connection
            ->expects($this->never())
            ->method('update');

        // Should log error
        $this->fileLogger
            ->expects($this->once())
            ->method('log')
            ->with($this->stringContains('ERROR'));

        // When: Reconcile order
        $result = $this->service->reconcileOrder($orderId, $paymentIntentId);

        // Then: Error - contract required
        $this->assertFalse($result->success);
        $this->assertEquals('no_contract', $result->action);
        $this->assertStringContains('No contract found', $result->reason);
    }

    /**
     * @test
     * Sprint 15: OXPAID is only updated when contract exists and is COMMITTED
     * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
     */
    public function reconcileOrderUpdatesOxpaidWhenPaymentIsCaptured(): void
    {
        $orderId = 'test_order_123';
        $paymentIntentId = 'pi_test_456';
        $capturedAt = new \DateTimeImmutable('2025-12-05 10:30:00');

        // Given: Contract in COMMITTED state (required)
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract
            ->method('getState')
            ->willReturn(ContractState::committed());

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        // Sprint 18: ContractFulfillmentService handles fulfillment
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        // Mock adapter factory to return adapter
        $adapter = $this->createMock(PaymentAdapterInterface::class);
        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($adapter);

        // Mock payment details response - payment is captured
        $paymentDetails = new PaymentDetailsResponse(
            providerPaymentId: $paymentIntentId,
            status: 'succeeded',
            amount: 100.00,
            currency: 'EUR',
            amountCaptured: 100.00,
            amountRefunded: 0.0,
            isCaptured: true,
            isRefunded: false,
            isCancelled: false,
            createdAt: new \DateTimeImmutable(),
            capturedAt: $capturedAt
        );

        $adapter
            ->method('getPaymentDetails')
            ->with($paymentIntentId)
            ->willReturn($paymentDetails);

        // Expect order update
        $this->connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'oxorder',
                ['OXPAID' => '2025-12-05 10:30:00'],
                ['OXID' => $orderId]
            );

        $result = $this->service->reconcileOrder($orderId, $paymentIntentId);

        $this->assertInstanceOf(ReconciliationResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('updated', $result->action);
        $this->assertTrue($result->contractUpdated);
    }

    /**
     * @test
     * Sprint 15: Skips when payment not captured (but contract exists)
     */
    public function reconcileOrderSkipsWhenPaymentNotCaptured(): void
    {
        $orderId = 'test_order_123';
        $paymentIntentId = 'pi_test_456';

        // Contract is required
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::committed());

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        $adapter = $this->createMock(PaymentAdapterInterface::class);
        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($adapter);

        // Mock payment details - NOT captured
        $paymentDetails = new PaymentDetailsResponse(
            providerPaymentId: $paymentIntentId,
            status: 'requires_capture',
            amount: 100.00,
            currency: 'EUR',
            amountCaptured: 0.0,
            amountRefunded: 0.0,
            isCaptured: false,
            isRefunded: false,
            isCancelled: false,
            createdAt: new \DateTimeImmutable()
        );

        $adapter
            ->method('getPaymentDetails')
            ->willReturn($paymentDetails);

        // Should NOT update order
        $this->connection
            ->expects($this->never())
            ->method('update');

        $result = $this->service->reconcileOrder($orderId, $paymentIntentId);

        $this->assertFalse($result->success);
        $this->assertEquals('skipped', $result->action);
        $this->assertStringContains('not captured', $result->reason);
    }

    /**
     * @test
     * Sprint 15: API errors are handled (with contract)
     */
    public function reconcileOrderHandlesApiError(): void
    {
        $orderId = 'test_order_123';
        $paymentIntentId = 'pi_test_456';

        // Contract is required
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::committed());

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        $adapter = $this->createMock(PaymentAdapterInterface::class);
        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($adapter);

        // Mock API error
        $adapter
            ->method('getPaymentDetails')
            ->willThrowException(new \Exception('API Error: Payment not found'));

        $result = $this->service->reconcileOrder($orderId, $paymentIntentId);

        $this->assertFalse($result->success);
        $this->assertEquals('error', $result->action);
        $this->assertStringContains('API Error', $result->reason);
    }

    /**
     * @test
     * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
     */
    public function reconcileOrderFulfillsContractWhenFound(): void
    {
        $orderId = 'test_order_123';
        $paymentIntentId = 'pi_test_456';

        $adapter = $this->createMock(PaymentAdapterInterface::class);
        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($adapter);

        // Payment is captured
        $paymentDetails = new PaymentDetailsResponse(
            providerPaymentId: $paymentIntentId,
            status: 'succeeded',
            amount: 100.00,
            currency: 'EUR',
            amountCaptured: 100.00,
            amountRefunded: 0.0,
            isCaptured: true,
            isRefunded: false,
            isCancelled: false,
            createdAt: new \DateTimeImmutable(),
            capturedAt: new \DateTimeImmutable()
        );

        $adapter
            ->method('getPaymentDetails')
            ->willReturn($paymentDetails);

        $this->connection
            ->method('update')
            ->willReturn(1);

        // Mock contract in committed state
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract
            ->method('getState')
            ->willReturn(ContractState::committed());

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        // Sprint 18: ContractFulfillmentService handles fulfillment
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        $result = $this->service->reconcileOrder($orderId, $paymentIntentId);

        $this->assertTrue($result->success);
        $this->assertTrue($result->contractUpdated);
    }

    /**
     * @test
     * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
     */
    public function reconcileOrderDoesNotFulfillContractIfNotCommitted(): void
    {
        $orderId = 'test_order_123';
        $paymentIntentId = 'pi_test_456';

        $adapter = $this->createMock(PaymentAdapterInterface::class);
        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($adapter);

        $paymentDetails = new PaymentDetailsResponse(
            providerPaymentId: $paymentIntentId,
            status: 'succeeded',
            amount: 100.00,
            currency: 'EUR',
            amountCaptured: 100.00,
            amountRefunded: 0.0,
            isCaptured: true,
            isRefunded: false,
            isCancelled: false,
            createdAt: new \DateTimeImmutable(),
            capturedAt: new \DateTimeImmutable()
        );

        $adapter
            ->method('getPaymentDetails')
            ->willReturn($paymentDetails);

        $this->connection
            ->method('update')
            ->willReturn(1);

        // Contract already fulfilled
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract
            ->method('getState')
            ->willReturn(ContractState::fulfilled());

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        // Sprint 18: Service returns false for already fulfilled
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(false);

        $result = $this->service->reconcileOrder($orderId, $paymentIntentId);

        $this->assertTrue($result->success);
        $this->assertFalse($result->contractUpdated);
    }

    /**
     * @test
     * Sprint 15: reconcileAll requires contracts for each order
     */
    public function reconcileAllProcessesAllOrders(): void
    {
        $orders = [
            ['OXID' => 'order1', 'OXTRANSID' => 'pi_111', 'OXORDERNR' => 1, 'OXORDERDATE' => '2025-12-05'],
            ['OXID' => 'order2', 'OXTRANSID' => 'pi_222', 'OXORDERNR' => 2, 'OXORDERDATE' => '2025-12-04'],
        ];

        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn($orders);

        // Both orders have contracts in COMMITTED state
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract
            ->method('getState')
            ->willReturn(ContractState::committed());

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $adapter = $this->createMock(PaymentAdapterInterface::class);
        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($adapter);

        // Both payments captured
        $paymentDetails = new PaymentDetailsResponse(
            providerPaymentId: 'pi_xxx',
            status: 'succeeded',
            amount: 100.00,
            currency: 'EUR',
            amountCaptured: 100.00,
            amountRefunded: 0.0,
            isCaptured: true,
            isRefunded: false,
            isCancelled: false,
            createdAt: new \DateTimeImmutable(),
            capturedAt: new \DateTimeImmutable()
        );

        $adapter
            ->method('getPaymentDetails')
            ->willReturn($paymentDetails);

        $results = $this->service->reconcileAll(7, false);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->success);
        $this->assertTrue($results[1]->success);
    }

    /**
     * @test
     * Sprint 15: reconcileAll skips orders without contracts
     */
    public function reconcileAllFailsOrdersWithoutContracts(): void
    {
        $orders = [
            ['OXID' => 'order1', 'OXTRANSID' => 'pi_111', 'OXORDERNR' => 1, 'OXORDERDATE' => '2025-12-05'],
        ];

        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn($orders);

        // No contract found
        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn(null);

        // Should NOT call Stripe API
        $this->adapterFactory
            ->expects($this->never())
            ->method('createDefaultAdapter');

        $results = $this->service->reconcileAll(7, false);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->success);
        $this->assertEquals('no_contract', $results[0]->action);
    }

    /**
     * @test
     */
    public function reconcileAllDryRunDoesNotMakeChanges(): void
    {
        $orders = [
            ['OXID' => 'order1', 'OXTRANSID' => 'pi_111', 'OXORDERNR' => 1, 'OXORDERDATE' => '2025-12-05'],
        ];

        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn($orders);

        // Should NOT call adapter or update
        $this->adapterFactory
            ->expects($this->never())
            ->method('createDefaultAdapter');

        $this->connection
            ->expects($this->never())
            ->method('update');

        $results = $this->service->reconcileAll(7, true);

        $this->assertCount(1, $results);
        $this->assertEquals('dry_run', $results[0]->action);
    }

    /**
     * Helper to assert string contains substring
     */
    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertStringContainsString($needle, $haystack);
    }
}
