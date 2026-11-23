<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\LegacyOrderAssessment;
use OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * STRP-181 / sprint 144 (AW): one rule set for the dry run and for --apply.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(LegacyOrderAssessment::class)]
final class LegacyOrderAssessmentTest extends TestCase
{
    private StripeAdapterInterface&MockObject $stripe;
    private LegacyOrderAssessment $assessment;

    protected function setUp(): void
    {
        $this->stripe = $this->createMock(StripeAdapterInterface::class);
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($this->stripe);
        $this->assessment = new LegacyOrderAssessment($factory, new StripeChargeAmountResolver());
    }

    public function testAssess_WhenTransactionIdIsEmpty_SkipsWithoutAskingStripe(): void
    {
        $this->stripe->expects($this->never())->method('retrievePaymentIntent');

        $verdict = $this->assessment->assess($this->row(['OXTRANSID' => '']));

        $this->assertStringContainsString('no transaction id', (string) $verdict->skipReason);
    }

    public function testAssess_WhenTransactionIdIsACharge_SkipsWithoutAskingStripe(): void
    {
        $this->stripe->expects($this->never())->method('retrievePaymentIntent');

        $verdict = $this->assessment->assess($this->row(['OXTRANSID' => 'ch_old']));

        $this->assertStringContainsString('not a PaymentIntent', (string) $verdict->skipReason);
    }

    public function testAssess_WhenOrderIsStorno_Skips(): void
    {
        $this->assertStringContainsString('cancelled', (string) $this->assessment->assess($this->row(['OXSTORNO' => '1']))->skipReason);
    }

    public function testAssess_WhenOrderWasNeverPaid_Skips(): void
    {
        $verdict = $this->assessment->assess($this->row(['OXPAID' => '0000-00-00 00:00:00']));

        $this->assertStringContainsString('never paid', (string) $verdict->skipReason);
    }

    public function testAssess_WhenOrderTotalIsZero_Skips(): void
    {
        $verdict = $this->assessment->assess($this->row(['OXTOTALORDERSUM' => '0.00']));

        $this->assertStringContainsString('total is zero', (string) $verdict->skipReason);
    }

    public function testAssess_WhenStripeDoesNotKnowThePaymentIntent_SkipsAndNamesTheAccount(): void
    {
        $this->stripe->method('retrievePaymentIntent')->willThrowException(
            new PaymentAdapterException('stripe', 'resource_missing', 'No such payment_intent')
        );

        $verdict = $this->assessment->assess($this->row());

        $this->assertStringContainsString("has no PaymentIntent 'pi_legacy_1'", (string) $verdict->skipReason);
        $this->assertStringContainsString('another Stripe account or mode', (string) $verdict->skipReason);
    }

    /**
     * A rate limit or an outage is no verdict on the order: the run reports it as a failure.
     */
    public function testAssess_WhenStripeFailsForAnotherReason_LetsTheErrorThrough(): void
    {
        $this->stripe->method('retrievePaymentIntent')->willThrowException(
            new PaymentAdapterException('stripe', 'rate_limit', 'Too many requests')
        );

        $this->expectException(PaymentAdapterException::class);

        $this->assessment->assess($this->row());
    }

    public function testAssess_WhenThePaymentIntentIsNotPaid_Skips(): void
    {
        $this->stripe->method('retrievePaymentIntent')->willReturn($this->intent(status: 'requires_capture'));

        $verdict = $this->assessment->assess($this->row());

        $this->assertStringContainsString("'requires_capture'", (string) $verdict->skipReason);
    }

    public function testAssess_WhenThePaymentIntentIsPaid_TakesCapturedAndRefundedAmountsFromStripe(): void
    {
        $this->stripe->expects($this->once())->method('retrievePaymentIntent')
            ->with('pi_legacy_1', ['latest_charge'])
            ->willReturn($this->intent(amountReceived: 11900, refundedCents: 2488));

        $verdict = $this->assessment->assess($this->row(['OXTOTALORDERSUM' => '120.00']));

        $this->assertNull($verdict->skipReason);
        $this->assertSame(119.0, $verdict->capturedAmount, 'Stripe is the source, not OXTOTALORDERSUM');
        $this->assertSame(24.88, $verdict->refundedAmount);
        $this->assertSame('2025-04-01 12:00:00', $verdict->paidAt?->format('Y-m-d H:i:s'));
    }

    public function testAssess_WhenThePaymentIntentHasNoCharge_ReportsNoRefund(): void
    {
        $this->stripe->method('retrievePaymentIntent')->willReturn($this->intent(withCharge: false));

        $this->assertSame(0.0, $this->assessment->assess($this->row())->refundedAmount);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'OXID' => 'ord-1',
            'OXTRANSID' => 'pi_legacy_1',
            'OXTOTALORDERSUM' => '119.00',
            'OXPAID' => '2025-04-01 12:00:00',
            'OXSTORNO' => '0',
        ], $overrides);
    }

    private function intent(
        string $status = 'succeeded',
        int $amountReceived = 11900,
        int $refundedCents = 0,
        bool $withCharge = true
    ): StripePaymentIntentDto {
        $charge = $withCharge
            ? new StripeChargeDto('ch_1', $amountReceived, $amountReceived, $refundedCents, 'eur', true, 1743508800)
            : null;

        return new StripePaymentIntentDto('pi_legacy_1', $status, $amountReceived, 'eur', 1743508800, 'ch_1', $charge, 0, $amountReceived);
    }
}
