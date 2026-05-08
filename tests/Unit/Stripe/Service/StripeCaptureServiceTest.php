<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Exception\CaptureFailedException;
use OxidEsales\Payments\Stripe\Service\StripeCaptureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\Payments\Stripe\Service\StripeCaptureService
 *
 * Sprint 26: Service now uses LazyStripeAdapter via PaymentAdapterInterface.
 * Sprint 31: Updated to use CaptureResponse instead of CaptureResult.
 */
class StripeCaptureServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private PaymentAdapterInterface&MockObject $stripeAdapter;
    private LoggerInterface&MockObject $logger;
    private StripeCaptureService $service;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->stripeAdapter = $this->createMock(PaymentAdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new StripeCaptureService(
            $this->contractRepository,
            $this->stripeAdapter,
            $this->logger
        );
    }

    // 1. Captures when contract is in AUTHORIZED state (Stripe-specific behavior)
    public function testCapturesWhenContractIsAuthorized(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $amount = 99.99;

        $contract = $this->createMockContract($contractId, $providerOrderId, $amount, ContractState::authorized());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $captureResponse = $this->createMockCaptureResponse('ch_123', $amount);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willReturn($captureResponse);

        $contract->expects($this->once())
            ->method('captureAuthorization');

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $result = $this->service->capture($contractId);

        $this->assertEquals('ch_123', $result->captureId);
        $this->assertEquals($amount, $result->amountCaptured);
    }

    // 2. Cannot capture when contract is in COMMITTED state (Stripe uses AUTHORIZED, not COMMITTED)
    public function testCannotCaptureCommittedContract(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMockContract($contractId, 'pi_123', 99.99, ContractState::committed());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->expectException(CaptureFailedException::class);
        $this->expectExceptionMessage('Contract must be in authorized state for Stripe capture');

        $this->service->capture($contractId);
    }

    // 3. Cannot capture PENDING contract
    public function testCannotCapturePendingContract(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMockContract($contractId, 'pi_123', 99.99, ContractState::pending());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->expectException(CaptureFailedException::class);
        $this->expectExceptionMessage('Contract must be in authorized state for Stripe capture');

        $this->service->capture($contractId);
    }

    // 4. Calls captureAuthorization instead of fulfill (Stripe-specific behavior)
    public function testCallsCaptureAuthorizationNotFulfill(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $amount = 99.99;

        $contract = $this->createMockContract($contractId, $providerOrderId, $amount, ContractState::authorized());

        $this->contractRepository->method('findById')->willReturn($contract);

        $captureResponse = $this->createMockCaptureResponse('ch_123', $amount);
        $this->stripeAdapter->method('capturePayment')->willReturn($captureResponse);

        // captureAuthorization SHOULD be called
        $contract->expects($this->once())
            ->method('captureAuthorization');

        // fulfill should NOT be called (that's the default behavior, not Stripe)
        $contract->expects($this->never())
            ->method('fulfill');

        $this->service->capture($contractId);
    }

    // 5. Capture partial amount
    public function testCapturesPartialAmount(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $authorizedAmount = 100.00;
        $partialAmount = 50.00;

        $contract = $this->createMockContract($contractId, $providerOrderId, $authorizedAmount, ContractState::authorized());

        $this->contractRepository->method('findById')->willReturn($contract);

        $captureResponse = $this->createMockCaptureResponse('ch_partial', $partialAmount);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function ($request) use ($partialAmount) {
                return $request->amount === $partialAmount;
            }))
            ->willReturn($captureResponse);

        $result = $this->service->capture($contractId, $partialAmount);

        $this->assertEquals($partialAmount, $result->amountCaptured);
    }

    // 6. Cannot capture already fulfilled contract
    public function testCannotCaptureAlreadyFulfilledContract(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMockContract($contractId, 'pi_123', 99.99, ContractState::fulfilled());

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->willReturn($contract);

        $this->expectException(CaptureFailedException::class);
        $this->expectExceptionMessage('Payment already captured');

        $this->service->capture($contractId);
    }

    // 7. Cannot capture without authorization (no provider order ID)
    public function testCannotCaptureWithoutProviderOrderId(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);
        $contract->method('getState')->willReturn(ContractState::authorized());
        $contract->method('getProviderOrderId')->willReturn(null);

        $this->contractRepository->method('findById')->willReturn($contract);

        $this->expectException(CaptureFailedException::class);
        $this->expectExceptionMessage('No authorization found for this contract');

        $this->service->capture($contractId);
    }

    // 8. Handle contract not found
    public function testHandlesContractNotFound(): void
    {
        $contractId = 'nonexistent';

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn(null);

        $this->expectException(CaptureFailedException::class);
        $this->expectExceptionMessage('Contract not found');

        $this->service->capture($contractId);
    }

    // 9. Handle provider API error
    public function testHandlesProviderApiError(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';

        $contract = $this->createMockContract($contractId, $providerOrderId, 99.99, ContractState::authorized());

        $this->contractRepository->method('findById')->willReturn($contract);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willThrowException(new \Exception('Stripe error: card_declined'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Payment capture failed', $this->arrayHasKey('error'));

        $this->expectException(CaptureFailedException::class);
        $this->expectExceptionMessage('Stripe error: card_declined');

        $this->service->capture($contractId);
    }

    // 10. Logs capture operation
    public function testLogsCaptureOperation(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $amount = 99.99;

        $contract = $this->createMockContract($contractId, $providerOrderId, $amount, ContractState::authorized());

        $this->contractRepository->method('findById')->willReturn($contract);

        $captureResponse = $this->createMockCaptureResponse('ch_123', $amount);
        $this->stripeAdapter->method('capturePayment')->willReturn($captureResponse);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                'Payment captured successfully',
                $this->callback(function ($context) use ($contractId, $amount) {
                    return $context['contractId'] === $contractId
                        && $context['amount'] === $amount;
                })
            );

        $this->service->capture($contractId);
    }

    // Helper methods

    private function createMockContract(
        string $id,
        string $providerOrderId,
        float $amount,
        ContractState $state
    ): PaymentContractInterface&MockObject {
        $contract = $this->createMock(PaymentContractInterface::class);

        $contract->method('getId')->willReturn($id);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getState')->willReturn($state);

        $basketSnapshot = $this->createMock(BasketSnapshot::class);
        $basketSnapshot->method('getTotalGross')->willReturn($amount);
        $basketSnapshot->method('getCurrency')->willReturn('EUR');

        $contract->method('getBasketSnapshot')->willReturn($basketSnapshot);

        return $contract;
    }

    private function createMockCaptureResponse(string $captureId, float $amount): CaptureResponse
    {
        return CaptureResponse::success(
            providerPaymentId: 'pi_test',
            captureId: $captureId,
            amountCaptured: $amount,
            currency: 'EUR',
            status: 'succeeded',
            capturedAt: new DateTimeImmutable()
        );
    }
}
