<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Service\PaymentIntentResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for PaymentIntentResolver.
 *
 * Sprint 114.8: Extracted PI-id resolution from StripeCaptureRequestHandler and
 * StripeCancelAuthorizationRequestHandler (D4).
 *
 * @covers \OxidEsales\Payments\Stripe\Service\PaymentIntentResolver
 * @group sprint-114-8
 */
final class PaymentIntentResolverTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private PaymentIntentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->resolver = new PaymentIntentResolver($this->contractRepository);
    }

    /**
     * @test
     * Explicit PI id provided → returns it directly, no repository call.
     */
    public function returnsExplicitPaymentIntentId(): void
    {
        $this->contractRepository->expects($this->never())->method('findById');

        $result = $this->resolver->resolve(
            explicitPaymentIntentId: 'pi_explicit_123',
            contractId: null
        );

        $this->assertSame('pi_explicit_123', $result);
    }

    /**
     * @test
     * No explicit id, contract has providerOrderId → returns providerOrderId.
     */
    public function returnsProviderOrderIdFromContract(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_from_contract');
        $contract->method('getMetadata')->willReturn(null);

        $this->contractRepository
            ->method('findById')
            ->with('contract_abc')
            ->willReturn($contract);

        $result = $this->resolver->resolve(
            explicitPaymentIntentId: null,
            contractId: 'contract_abc'
        );

        $this->assertSame('pi_from_contract', $result);
    }

    /**
     * @test
     * No explicit id, contract has no providerOrderId but has metadata → returns from metadata.
     */
    public function returnsPaymentIntentFromContractMetadata(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);
        $contract->method('getMetadata')
            ->with('payment_intent_id')
            ->willReturn('pi_from_metadata');

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $result = $this->resolver->resolve(
            explicitPaymentIntentId: null,
            contractId: 'contract_abc'
        );

        $this->assertSame('pi_from_metadata', $result);
    }

    /**
     * @test
     * No explicit id, no contract id → throws with "missing" message.
     */
    public function throwsWhenNeitherIdNorContractProvided(): void
    {
        $this->contractRepository->expects($this->never())->method('findById');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PaymentIntent ID is missing');

        $this->resolver->resolve(
            explicitPaymentIntentId: null,
            contractId: null
        );
    }

    /**
     * @test
     * Contract not found → throws with "Contract not found" message.
     */
    public function throwsWhenContractNotFound(): void
    {
        $this->contractRepository
            ->method('findById')
            ->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Contract not found');

        $this->resolver->resolve(
            explicitPaymentIntentId: null,
            contractId: 'contract_missing'
        );
    }

    /**
     * @test
     * Contract found but no PI id anywhere → throws "No PaymentIntent ID found".
     */
    public function throwsWhenNoPaymentIntentIdFoundOnContract(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);
        $contract->method('getMetadata')->willReturn(null);

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No PaymentIntent ID found');

        $this->resolver->resolve(
            explicitPaymentIntentId: null,
            contractId: 'contract_abc'
        );
    }
}
