<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentDetailsResponse;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\OxpaidReconciliationService;
use OxidSolutionCatalysts\Payments\Stripe\Service\ReconciliationResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OxpaidReconciliationService
 *
 * Sprint 10: Tests for OXPAID reconciliation with Stripe API.
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\OxpaidReconciliationService
 * @group sprint-10
 * @group reconciliation
 */
class OxpaidReconciliationServiceTest extends TestCase
{
    private Connection $connection;
    private StripeAdapterFactoryInterface $adapterFactory;
    private ContractRepositoryInterface $contractRepository;
    private LoggerInterface $logger;
    private OxpaidReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createMock(Connection::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new OxpaidReconciliationService(
            $this->connection,
            $this->adapterFactory,
            $this->contractRepository,
            $this->logger
        );
    }

    /**
     * @test
     */
    public function serviceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(OxpaidReconciliationService::class, $this->service);
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
     */
    public function reconcileOrderUpdatesOxpaidWhenPaymentIsCaptured(): void
    {
        $orderId = 'test_order_123';
        $paymentIntentId = 'pi_test_456';
        $capturedAt = new \DateTimeImmutable('2025-12-05 10:30:00');

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

        // No contract found
        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn(null);

        $result = $this->service->reconcileOrder($orderId, $paymentIntentId);

        $this->assertInstanceOf(ReconciliationResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('updated', $result->action);
        $this->assertFalse($result->contractUpdated);
    }

    /**
     * @test
     */
    public function reconcileOrderSkipsWhenPaymentNotCaptured(): void
    {
        $orderId = 'test_order_123';
        $paymentIntentId = 'pi_test_456';

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
     */
    public function reconcileOrderHandlesApiError(): void
    {
        $orderId = 'test_order_123';
        $paymentIntentId = 'pi_test_456';

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
        $contract
            ->expects($this->once())
            ->method('fulfill');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $result = $this->service->reconcileOrder($orderId, $paymentIntentId);

        $this->assertTrue($result->success);
        $this->assertTrue($result->contractUpdated);
    }

    /**
     * @test
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
        $contract
            ->expects($this->never())
            ->method('fulfill');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $result = $this->service->reconcileOrder($orderId, $paymentIntentId);

        $this->assertTrue($result->success);
        $this->assertFalse($result->contractUpdated);
    }

    /**
     * @test
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

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn(null);

        $results = $this->service->reconcileAll(7, false);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->success);
        $this->assertTrue($results[1]->success);
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
