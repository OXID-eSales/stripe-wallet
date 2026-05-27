<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\CaptureService;
use OxidEsales\Payments\Stripe\Service\CaptureServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CaptureService.
 *
 * Sprint 9: Tests for the extracted capture business logic.
 * Sprint 26: Updated to use factory instead of direct adapter injection.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\CaptureService
 */
class CaptureServiceTest extends TestCase
{
    private StripeAdapterInterface&MockObject $adapter;
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private ContractRepositoryInterface&MockObject $repository;
    private ContractFulfillmentServiceInterface&MockObject $fulfillmentService;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private ShopAdapterInterface&MockObject $shopAdapter;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->adapter);
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->fulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');
    }

    private function createService(): CaptureService
    {
        return new CaptureService(
            $this->adapterFactory,
            $this->repository,
            $this->fulfillmentService,
            $this->transactionRepository,
            $this->shopAdapter,
            new NullLogger()
        );
    }

    public function testImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(CaptureServiceInterface::class, $service);
    }

    public function testProcessCaptureWithContractReturnsSuccess(): void
    {
        $capturedAt = new \DateTimeImmutable();
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_123',
            captureId: 'ch_123',
            amountCaptured: 10.00,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: $capturedAt
        );

        $this->adapter->expects($this->once())
            ->method('capturePayment')
            ->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        // Sprint 82: transitionContractState() now checks state before calling transition
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );
        $contract->expects($this->once())->method('captureAuthorization');

        $this->repository->expects($this->once())->method('save')->with($contract);

        $service = $this->createService();

        $result = $service->processCapture($contract, 10.00, ['initiator' => 'admin']);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('ch_123', $result->captureId);
        $this->assertSame(10.00, $result->amountCaptured);
        $this->assertSame('eur', $result->currency);
    }

    public function testProcessCaptureTransitionsContractState(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_123',
            captureId: 'ch_123',
            amountCaptured: 10.00,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        );

        $this->adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        // Sprint 82: transitionContractState() now checks state before calling transition
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );
        $contract->expects($this->once())->method('captureAuthorization');

        $this->repository->expects($this->once())->method('save');

        $service = $this->createService();

        $service->processCapture($contract, null, []);
    }

    public function testProcessCapturePassesAmountAsFloat(): void
    {
        $this->adapter->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function (CapturePaymentRequest $request) {
                return $request->amount === 10.50; // Amount in major units
            }))
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_123',
                captureId: 'ch_123',
                amountCaptured: 10.50,
                currency: 'eur',
                status: 'succeeded',
                capturedAt: new \DateTimeImmutable()
            ));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');

        $service = $this->createService();

        $service->processCapture($contract, 10.50, []);
    }

    public function testProcessCaptureWithNullAmountDoesFullCapture(): void
    {
        $this->adapter->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function (CapturePaymentRequest $request) {
                return $request->amount === null;
            }))
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_123',
                captureId: 'ch_123',
                amountCaptured: 50.00,
                currency: 'eur',
                status: 'succeeded',
                capturedAt: new \DateTimeImmutable()
            ));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');

        $service = $this->createService();

        $result = $service->processCapture($contract, null, []);

        $this->assertTrue($result->isSuccessful());
    }

    public function testProcessCaptureReturnsFailureOnException(): void
    {
        $this->adapter->method('capturePayment')
            ->willThrowException(new \Exception('API Error'));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');

        $this->repository->expects($this->never())->method('save');

        $service = $this->createService();

        $result = $service->processCapture($contract, 10.00, []);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('API Error', $result->errorMessage);
    }

    public function testProcessCaptureReturnsFailureWhenNoPaymentIntentId(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);

        $this->adapter->expects($this->never())->method('capturePayment');
        $this->repository->expects($this->never())->method('save');

        $service = $this->createService();

        $result = $service->processCapture($contract, 10.00, []);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('PaymentIntent ID', $result->errorMessage);
    }

    public function testProcessCaptureReturnsFailureWhenEmptyPaymentIntentId(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('');

        $this->adapter->expects($this->never())->method('capturePayment');

        $service = $this->createService();

        $result = $service->processCapture($contract, 10.00, []);

        $this->assertFalse($result->isSuccessful());
    }

    public function testProcessDirectCaptureWithoutContract(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_123',
            captureId: 'ch_123',
            amountCaptured: 10.00,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        );

        $this->adapter->expects($this->once())
            ->method('capturePayment')
            ->willReturn($response);

        $this->repository->expects($this->never())->method('save');

        $service = $this->createService();

        $result = $service->processDirectCapture('pi_123', 10.00, ['order_id' => 'order_123']);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('ch_123', $result->captureId);
    }

    public function testProcessDirectCaptureReturnsFailureOnException(): void
    {
        $this->adapter->method('capturePayment')
            ->willThrowException(new \Exception('Direct capture failed'));

        $service = $this->createService();

        $result = $service->processDirectCapture('pi_123', 10.00, []);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('Direct capture failed', $result->errorMessage);
    }

    // --- Sprint 82: Manual capture fix — COMMITTED state support ---

    /**
     * Sprint 82: When contract is in COMMITTED state (manual capture order that
     * skipped AUTHORIZED), capture should use ContractFulfillmentService to
     * dispatch ContractFulfilledEvent (which triggers OXPAID update).
     */
    public function testProcessCaptureCallsFulfillmentServiceForCommittedContract(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_committed',
            captureId: 'ch_committed',
            amountCaptured: 130.39,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        );

        $this->adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_committed');
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::committed()
        );

        // Must use ContractFulfillmentService (dispatches event + updates OXPAID)
        $this->fulfillmentService->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        // captureAuthorization must NOT be called for committed contracts
        $contract->expects($this->never())->method('captureAuthorization');

        // Repository save is handled by ContractFulfillmentService, not directly
        $this->repository->expects($this->never())->method('save');

        $service = $this->createService();

        $result = $service->processCapture($contract, null, []);

        $this->assertTrue($result->isSuccessful());
    }

    /**
     * Sprint 82: Authorized contracts still use captureAuthorization() (unchanged behavior).
     */
    public function testProcessCaptureCallsCaptureAuthorizationForAuthorizedContract(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_authorized',
            captureId: 'ch_authorized',
            amountCaptured: 50.00,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        );

        $this->adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_authorized');
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );

        // Must call captureAuthorization() for authorized contracts
        $contract->expects($this->once())->method('captureAuthorization');
        $contract->expects($this->never())->method('fulfill');

        $this->repository->expects($this->once())->method('save');

        $service = $this->createService();

        $result = $service->processCapture($contract, null, []);

        $this->assertTrue($result->isSuccessful());
    }

    // --- Sprint 84: Transaction recording ---

    /**
     * Sprint 84: Successful capture records a transaction in the repository.
     */
    public function testProcessCaptureRecordsTransaction(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_record',
            captureId: 'ch_record',
            amountCaptured: 99.99,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        );

        $this->adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_record');
        $contract->method('getId')->willReturn('contract_rec');
        $contract->method('getOrderId')->willReturn('order_rec');
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::committed()
        );

        $this->transactionRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Transaction $tx) {
                return $tx->getType() === 'capture'
                    && $tx->getStatus() === 'completed'
                    && $tx->getAmount() === 99.99
                    && $tx->getCurrency() === 'eur'
                    && $tx->getContractId() === 'contract_rec'
                    && $tx->getOrderId() === 'order_rec'
                    && $tx->getTransactionId() === 'ch_record';
            }));

        $service = $this->createService();
        $service->processCapture($contract, null, []);
    }

    /**
     * Sprint 84: Failed capture does NOT record a transaction.
     */
    public function testProcessCaptureDoesNotRecordTransactionOnFailure(): void
    {
        $this->adapter->method('capturePayment')
            ->willThrowException(new \Exception('Capture failed'));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_fail');

        $this->transactionRepository->expects($this->never())->method('save');

        $service = $this->createService();
        $service->processCapture($contract, 10.00, []);
    }

    public function testProcessCapturePassesMetadataToAdapter(): void
    {
        $expectedMetadata = [
            'initiator' => 'admin',
            'contract_id' => 'contract_abc',
        ];

        $this->adapter->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function (CapturePaymentRequest $request) use ($expectedMetadata) {
                return $request->metadata === $expectedMetadata;
            }))
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_123',
                captureId: 'ch_123',
                amountCaptured: 10.00,
                currency: 'eur',
                status: 'succeeded',
                capturedAt: new \DateTimeImmutable()
            ));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');

        $service = $this->createService();

        $service->processCapture($contract, 10.00, $expectedMetadata);
    }

    // --- Sprint 114.1: Shop ID injection ---

    /**
     * Sprint 114.1: recordCaptureTransaction uses the injected shop id, not a hardcoded 1.
     */
    public function testCaptureTransactionUsesInjectedShopId(): void
    {
        $shopAdapterWithShopId5 = $this->createMock(ShopAdapterInterface::class);
        $shopAdapterWithShopId5->method('getShopId')->willReturn('5');

        $response = CaptureResponse::success(
            providerPaymentId: 'pi_shop5',
            captureId: 'ch_shop5',
            amountCaptured: 20.00,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        );

        $this->adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_shop5');
        $contract->method('getId')->willReturn('contract_s5');
        $contract->method('getOrderId')->willReturn('order_s5');
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::committed()
        );

        $capturedTransaction = null;
        $this->transactionRepository
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (Transaction $tx) use (&$capturedTransaction): void {
                $capturedTransaction = $tx;
            });

        $service = new CaptureService(
            $this->adapterFactory,
            $this->repository,
            $this->fulfillmentService,
            $this->transactionRepository,
            $shopAdapterWithShopId5,
            new NullLogger()
        );
        $service->processCapture($contract, null, []);

        $this->assertNotNull($capturedTransaction);
        $this->assertSame(5, $capturedTransaction->getShopId());
    }
}
