<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
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

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->adapter);
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
    }

    private function createService(): CaptureService
    {
        return new CaptureService(
            $this->adapterFactory,
            $this->repository,
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
}
