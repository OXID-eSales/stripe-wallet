<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\Response\RefundResponse;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentComponent\Service\Exception\RefundFailedException;
use OxidEsales\Payments\Stripe\Service\StripeRefundService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\Payments\Stripe\Service\StripeRefundService
 *
 * Sprint 22: Updated tests - Stripe module only supports full refunds.
 * Sprint 26: Service now uses LazyStripeAdapter via PaymentAdapterInterface.
 */
class StripeRefundServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private PaymentAdapterInterface&MockObject $stripeAdapter;
    private LoggerInterface&MockObject $logger;
    private StripeRefundService $service;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->stripeAdapter = $this->createMock(PaymentAdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new StripeRefundService(
            $this->contractRepository,
            $this->transactionRepository,
            $this->stripeAdapter,
            $this->logger
        );
    }

    // 1. Process full refund for fulfilled contract
    public function testProcessesFullRefund(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $capturedAmount = 100.00;

        $contract = $this->createMockContract($contractId, $providerOrderId, $capturedAmount, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $refundResponse = $this->createRefundResponse('re_123', $capturedAmount);
        $this->stripeAdapter->method('refundPayment')->willReturn($refundResponse);

        $result = $this->service->refund($contractId);

        $this->assertArrayHasKey('response', $result);
        $this->assertInstanceOf(RefundResponse::class, $result['response']);
        $this->assertEquals('re_123', $result['response']->refundId);
        $this->assertEquals($capturedAmount, $result['response']->amountRefunded);
    }

    // 2. Sprint 87: Partial refund is now accepted (was rejected in Sprint 22)
    public function testAcceptsPartialRefund(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $capturedAmount = 100.00;
        $partialAmount = 30.00;

        $contract = $this->createMockContract($contractId, $providerOrderId, $capturedAmount, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $this->stripeAdapter->method('refundPayment')->willReturn(
            \OxidEsales\PaymentComponent\Adapter\Response\RefundResponse::success(
                providerPaymentId: $providerOrderId,
                refundId: 're_partial',
                amountRefunded: $partialAmount,
                currency: 'eur',
                status: 'succeeded',
                refundedAt: new \DateTimeImmutable()
            )
        );

        $result = $this->service->refund($contractId, $partialAmount);

        $this->assertArrayHasKey('response', $result);
        $this->assertEquals($partialAmount, $result['response']->amountRefunded);
    }

    // 3. Cannot refund uncommitted contract
    public function testCannotRefundUncommittedContract(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMockContract($contractId, 'pi_123', 99.99, ContractState::committed());

        $this->contractRepository->method('findById')->willReturn($contract);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Can only refund fulfilled (captured) payments');

        $this->service->refund($contractId);
    }

    // 4. Cannot refund more than captured amount
    public function testCannotRefundMoreThanCaptured(): void
    {
        $contractId = 'contract123';
        $capturedAmount = 100.00;
        $requestedAmount = 150.00;

        $contract = $this->createMockContract($contractId, 'pi_123', $capturedAmount, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Cannot refund');

        $this->service->refund($contractId, $requestedAmount);
    }

    // 5. Logs refund to transaction repository
    public function testLogsRefundToTransactionRepository(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $capturedAmount = 100.00;
        $reason = 'requested_by_customer';

        $contract = $this->createMockContract($contractId, $providerOrderId, $capturedAmount, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $refundResponse = $this->createRefundResponse('re_123', $capturedAmount);
        $this->stripeAdapter->method('refundPayment')->willReturn($refundResponse);

        $this->transactionRepository
            ->expects($this->once())
            ->method('logRefund')
            ->with($contractId, $capturedAmount, 're_123', $reason);

        $this->service->refund($contractId, null, $reason);
    }

    // 6. Handle contract not found
    public function testHandlesContractNotFound(): void
    {
        $contractId = 'nonexistent';

        $this->contractRepository->method('findById')->willReturn(null);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Contract not found');

        $this->service->refund($contractId);
    }

    // 7. Handle provider API error
    public function testHandlesProviderApiError(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMockContract($contractId, 'pi_123', 99.99, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $this->stripeAdapter
            ->method('refundPayment')
            ->willThrowException(new \Exception('Stripe error: charge_already_refunded'));

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Stripe error: charge_already_refunded');

        $this->service->refund($contractId);
    }

    // 8. Passes reason to Stripe adapter
    public function testPassesReasonToStripeAdapter(): void
    {
        $contractId = 'contract123';
        $providerOrderId = 'pi_stripe_123';
        $capturedAmount = 100.00;
        $reason = 'fraudulent';

        $contract = $this->createMockContract($contractId, $providerOrderId, $capturedAmount, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $refundResponse = $this->createRefundResponse('re_123', $capturedAmount);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('refundPayment')
            ->with($this->callback(function ($request) use ($reason) {
                return $request->reason === $reason;
            }))
            ->willReturn($refundResponse);

        $this->service->refund($contractId, null, $reason);
    }

    // 9. Cannot refund without provider order ID
    public function testCannotRefundWithoutProviderOrderId(): void
    {
        $contractId = 'contract123';

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $contract->method('getProviderOrderId')->willReturn(null);

        $this->contractRepository->method('findById')->willReturn($contract);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('Cannot refund: Contract has no provider order ID');

        $this->service->refund($contractId);
    }

    // 10. Logs successful full refund
    public function testLogsSuccessfulRefund(): void
    {
        $contractId = 'contract123';
        $amount = 100.00; // Full refund

        $contract = $this->createMockContract($contractId, 'pi_123', $amount, ContractState::fulfilled());

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $refundResponse = $this->createRefundResponse('re_123', $amount);
        $this->stripeAdapter->method('refundPayment')->willReturn($refundResponse);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                'Payment refunded successfully',
                $this->callback(function ($context) use ($contractId) {
                    return $context['contractId'] === $contractId;
                })
            );

        $this->service->refund($contractId); // null amount = full refund
    }

    // ==========================================
    // Sprint 47: Fix 7 - Refund ceiling uses captured amount (STRP-99)
    // ==========================================

    public function testRefundUsesContractCapturedAmountNotTotalGross(): void
    {
        $contractId = 'contract_partial_capture';
        $providerOrderId = 'pi_stripe_789';
        $totalGross = 100.00;
        $capturedAmount = 50.00;

        $contract = $this->createMockContractWithCapturedAmount(
            $contractId,
            $providerOrderId,
            $totalGross,
            $capturedAmount,
            0.0,
            ContractState::fulfilled()
        );

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $refundResponse = $this->createRefundResponse('re_789', $capturedAmount);
        $this->stripeAdapter->method('refundPayment')->willReturn($refundResponse);

        $result = $this->service->refund($contractId);

        // Max refundable should be 50 (capturedAmount), NOT 100 (totalGross)
        $this->assertEquals(50.00, $result['response']->amountRefunded);
        $this->assertEquals(50.00, $result['totalRefunded']);
        $this->assertEquals(0.00, $result['availableForRefund']);
    }

    public function testRefundFailsWhenNoCapturedAmountRecorded(): void
    {
        $contractId = 'contract_no_capture';
        $contract = $this->createMockContractWithCapturedAmount(
            $contractId,
            'pi_123',
            100.00,
            null,
            0.0,
            ContractState::fulfilled()
        );

        $this->contractRepository->method('findById')->willReturn($contract);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('no captured amount');

        $this->service->refund($contractId);
    }

    public function testRefundWithPartialCaptureAndPriorRefund(): void
    {
        $contractId = 'contract_partial';
        $capturedAmount = 50.00;
        $alreadyRefunded = 10.00;

        $contract = $this->createMockContractWithCapturedAmount(
            $contractId,
            'pi_partial',
            100.00,
            $capturedAmount,
            0.0,
            ContractState::fulfilled()
        );

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn($alreadyRefunded);

        // Available = 50 - 10 = 40, but StripeRefundService only allows full refunds
        // So it should reject because 40 != 40 is false... actually full remaining = 40
        $refundResponse = $this->createRefundResponse('re_partial', 40.00);
        $this->stripeAdapter->method('refundPayment')->willReturn($refundResponse);

        $result = $this->service->refund($contractId);

        $this->assertEquals(40.00, $result['response']->amountRefunded);
        $this->assertEquals(50.00, $result['totalRefunded']);
    }

    // ==========================================
    // Sprint 47: Fix 9 - is_finite validation (STRP-99)
    // ==========================================

    public function testRefundRejectsNanAmount(): void
    {
        $contractId = 'contract_nan';
        $contract = $this->createMockContractWithCapturedAmount(
            $contractId,
            'pi_nan',
            100.00,
            100.00,
            0.0,
            ContractState::fulfilled()
        );

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('finite');

        $this->service->refund($contractId, NAN);
    }

    public function testRefundRejectsInfinityAmount(): void
    {
        $contractId = 'contract_inf';
        $contract = $this->createMockContractWithCapturedAmount(
            $contractId,
            'pi_inf',
            100.00,
            100.00,
            0.0,
            ContractState::fulfilled()
        );

        $this->contractRepository->method('findById')->willReturn($contract);
        $this->transactionRepository->method('getTotalRefundedForContract')->willReturn(0.00);

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('finite');

        $this->service->refund($contractId, INF);
    }

    // Helper methods

    private function createMockContract(
        string $id,
        string $providerOrderId,
        float $amount,
        ContractState $state
    ): PaymentContractInterface&MockObject {
        return $this->createMockContractWithCapturedAmount(
            $id,
            $providerOrderId,
            $amount,
            $amount,
            0.0,
            $state
        );
    }

    private function createMockContractWithCapturedAmount(
        string $id,
        string $providerOrderId,
        float $totalGross,
        ?float $capturedAmount,
        float $refundedAmount,
        ContractState $state
    ): PaymentContractInterface&MockObject {
        $contract = $this->createMock(PaymentContractInterface::class);

        $contract->method('getId')->willReturn($id);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getState')->willReturn($state);
        $contract->method('getCapturedAmount')->willReturn($capturedAmount);
        $contract->method('getRefundedAmount')->willReturn($refundedAmount);

        $basketSnapshot = $this->createMock(BasketSnapshot::class);
        $basketSnapshot->method('getTotalGross')->willReturn($totalGross);
        $basketSnapshot->method('getCurrency')->willReturn('EUR');

        $contract->method('getBasketSnapshot')->willReturn($basketSnapshot);

        return $contract;
    }

    private function createRefundResponse(string $refundId, float $amount): RefundResponse
    {
        return RefundResponse::success(
            providerPaymentId: 'pi_test',
            refundId: $refundId,
            amountRefunded: $amount,
            currency: 'EUR',
            status: 'succeeded',
            refundedAt: new DateTimeImmutable()
        );
    }
}
