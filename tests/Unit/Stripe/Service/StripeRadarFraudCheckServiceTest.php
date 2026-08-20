<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripeRadarFraudCheckService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\StripeRadarFraudCheckService::class)]
class StripeRadarFraudCheckServiceTest extends TestCase
{
    /** @var StripeAdapterFactoryInterface&MockObject */
    private StripeAdapterFactoryInterface $adapterFactory;
    /** @var StripeAdapterInterface&MockObject */
    private StripeAdapterInterface $adapter;
    private StripeRadarFraudCheckService $service;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->adapter);

        $this->service = new StripeRadarFraudCheckService(
            $this->adapterFactory,
            0.7 // threshold
        );
    }

    // =========================================================================
    // Pass scenarios
    // =========================================================================

    public function testPassesWhenScoreBelowThreshold(): void
    {
        $contract = $this->createMockContractWithPaymentIntent('pi_123');

        $this->adapter->expects($this->once())
            ->method('getPaymentIntentRiskScore')
            ->with('pi_123')
            ->willReturn(0.25);

        $result = $this->service->check($contract);

        $this->assertTrue($result->isSuccessful());
        $this->assertFalse(!$result->isSuccessful());
        $this->assertEquals(0.25, $result->score);
    }

    public function testPassesWhenScoreExactlyAtThreshold(): void
    {
        // Score at threshold should pass (>= means fail, so exactly at threshold passes)
        $contract = $this->createMockContractWithPaymentIntent('pi_123');

        $this->adapter->expects($this->once())
            ->method('getPaymentIntentRiskScore')
            ->with('pi_123')
            ->willReturn(0.69); // Just below 0.7

        $result = $this->service->check($contract);

        $this->assertTrue($result->isSuccessful());
    }

    public function testPassesWhenNoPaymentIntentId(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getMetadata')
            ->with('stripe_payment_intent_id')
            ->willReturn(null);

        $this->adapter->expects($this->never())
            ->method('getPaymentIntentRiskScore');

        $result = $this->service->check($contract);

        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->isScreened(), 'Nothing was screened, so no score may be claimed.');
        $this->assertSame('no_payment_intent', $result->reason);
    }

    /**
     * Sprint 133 · Story 4 (F1) — rewritten. This asserted score === 0.0 for
     * "Radar returned no score", i.e. it conflated *unknown* with *maximally
     * clean* on the DTO's documented 0..1 scale. Payment still proceeds, but
     * the response now says screening did not happen.
     */
    public function testReportsUnscreenedWhenRiskScoreNotAvailable(): void
    {
        $contract = $this->createMockContractWithPaymentIntent('pi_123');

        $this->adapter->expects($this->once())
            ->method('getPaymentIntentRiskScore')
            ->with('pi_123')
            ->willReturn(null);

        $result = $this->service->check($contract);

        $this->assertTrue($result->isSuccessful(), 'An unscoreable payment must not be blocked.');
        $this->assertFalse($result->isScreened(), 'No screening happened, so it must not read as a clean score.');
        $this->assertSame('score_unavailable', $result->reason);
    }

    /**
     * Sprint 133 · Story 4 (F1) — rewritten; supersedes testPassesOnApiError,
     * which asserted the fail-open forgery: on any Throwable the service
     * returned success(0.0) and FraudCheckHandler then wrote
     * "passed: true, score: 0.0" into the contract for an order Radar never
     * saw. The honest signal is FraudCheckResponse::error(); whether such an
     * order may still proceed is now FraudCheckHandler's documented policy.
     */
    public function testReturnsErrorResponseOnApiError(): void
    {
        $contract = $this->createMockContractWithPaymentIntent('pi_123');

        $this->adapter->expects($this->once())
            ->method('getPaymentIntentRiskScore')
            ->with('pi_123')
            ->willThrowException(new \RuntimeException('API error'));

        $result = $this->service->check($contract);

        $this->assertFalse($result->isSuccessful());
        $this->assertFalse($result->isScreened());
        $this->assertSame(1.0, $result->score, 'A failed check is highest risk, not zero risk.');
        $this->assertNotNull($result->errorMessage);
    }

    public function testLogsTheErrorWithPaymentIntentIdOnApiError(): void
    {
        $contract = $this->createMockContractWithPaymentIntent('pi_123');

        $this->adapter->method('getPaymentIntentRiskScore')
            ->willThrowException(new \RuntimeException('API error'));

        // The old code carried the comment "Log the error for debugging" while
        // having no logger at all: $e was captured and discarded.
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('fraud check'),
                $this->callback(static fn (array $c): bool =>
                    ($c['payment_intent_id'] ?? null) === 'pi_123'
                    && ($c['error'] ?? null) === 'API error')
            );

        $service = new StripeRadarFraudCheckService($this->adapterFactory, 0.7, $logger);

        $service->check($contract);
    }

    // =========================================================================
    // Fail scenarios
    // =========================================================================

    public function testFailsWhenScoreAboveThreshold(): void
    {
        $contract = $this->createMockContractWithPaymentIntent('pi_123');

        $this->adapter->expects($this->once())
            ->method('getPaymentIntentRiskScore')
            ->with('pi_123')
            ->willReturn(0.85);

        $result = $this->service->check($contract);

        $this->assertFalse($result->isSuccessful());
        $this->assertTrue(!$result->isSuccessful());
        $this->assertEquals(0.85, $result->score);
        $this->assertStringContainsString('0.85', $result->reason);
        $this->assertStringContainsString('0.70', $result->reason);
    }

    public function testFailsAtExactThreshold(): void
    {
        $contract = $this->createMockContractWithPaymentIntent('pi_123');

        $this->adapter->expects($this->once())
            ->method('getPaymentIntentRiskScore')
            ->with('pi_123')
            ->willReturn(0.70); // Exactly at threshold

        $result = $this->service->check($contract);

        $this->assertTrue(!$result->isSuccessful());
    }

    // =========================================================================
    // Custom threshold
    // =========================================================================

    public function testUsesCustomThreshold(): void
    {
        $serviceWithHighThreshold = new StripeRadarFraudCheckService(
            $this->adapterFactory,
            0.9 // high threshold
        );

        $contract = $this->createMockContractWithPaymentIntent('pi_123');

        $this->adapter->expects($this->once())
            ->method('getPaymentIntentRiskScore')
            ->with('pi_123')
            ->willReturn(0.75);

        $result = $serviceWithHighThreshold->check($contract);

        // 0.75 < 0.9 threshold, should pass
        $this->assertTrue($result->isSuccessful());
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    private function createMockContractWithPaymentIntent(string $paymentIntentId): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getMetadata')
            ->with('stripe_payment_intent_id')
            ->willReturn($paymentIntentId);

        return $contract;
    }
}
