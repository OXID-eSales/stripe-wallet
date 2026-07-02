<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Admin\StripeTransactionHistoryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.11b (S4): StripeTransactionHistoryBuilder — extracted from OrderRefundViewDataProvider.
 *
 * Owns transaction-history assembly from StripePaymentIntentDto + StripeChargeDto.
 * Uses DTO fixtures matching the characterization tests in
 * OrderRefundViewDataProviderDtoCharacterizationTest.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Admin\StripeTransactionHistoryBuilder::class)]
final class StripeTransactionHistoryBuilderTest extends TestCase
{
    private StripeTransactionHistoryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new StripeTransactionHistoryBuilder();
    }

    public function testBuildReturnsAuthorizationCaptureAndRefundRows(): void
    {
        // Arrange — PI with one capture and one refund
        $refundDto = new StripeRefundDto(
            id: 'rf_test_1',
            amount: 3000,
            currency: 'eur',
            status: 'succeeded',
            reason: null,
            createdAt: 1700000300,
        );
        $chargeDto = new StripeChargeDto(
            id: 'ch_test',
            amount: 10000,
            amountCaptured: 10000,
            amountRefunded: 3000,
            currency: 'eur',
            captured: true,
            created: 1700000100,
            refunds: [$refundDto],
        );
        $piDto = new StripePaymentIntentDto(
            id: 'pi_test',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: 'ch_test',
            charge: $chargeDto,
        );

        // Act
        $history = $this->builder->build($piDto);

        // Assert — authorization + capture + refund rows
        self::assertCount(3, $history);

        self::assertSame('authorization', $history[0]['type']);
        self::assertSame('pi_test', $history[0]['transactionId']);
        self::assertSame(100.0, $history[0]['amount']); // 10000 cents → 100.0 EUR

        self::assertSame('capture', $history[1]['type']);
        self::assertSame('completed', $history[1]['status']);
        self::assertSame('ch_test', $history[1]['transactionId']);
        self::assertSame(100.0, $history[1]['amount']);

        self::assertSame('refund', $history[2]['type']);
        self::assertSame('succeeded', $history[2]['status']);
        self::assertSame('rf_test_1', $history[2]['transactionId']);
        self::assertSame(30.0, $history[2]['amount']); // 3000 cents → 30.0 EUR
    }

    public function testBuildReturnsOnlyAuthorizationWhenNoCharge(): void
    {
        // Arrange — PI without expanded charge (uncaptured)
        $piDto = new StripePaymentIntentDto(
            id: 'pi_no_charge',
            status: 'requires_capture',
            amount: 5000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: null,
            charge: null,
        );

        // Act
        $history = $this->builder->build($piDto);

        // Assert — only authorization row
        self::assertCount(1, $history);
        self::assertSame('authorization', $history[0]['type']);
        self::assertSame('pi_no_charge', $history[0]['transactionId']);
    }

    public function testBuildMapsRequiresCaptureStatusToAuthorized(): void
    {
        $piDto = new StripePaymentIntentDto(
            id: 'pi_authorized',
            status: 'requires_capture',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: null,
            charge: null,
        );

        $history = $this->builder->build($piDto);

        self::assertSame('authorized', $history[0]['status']);
    }

    public function testBuildMapsSucceededStatusToCompleted(): void
    {
        $piDto = new StripePaymentIntentDto(
            id: 'pi_succeeded',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: null,
            charge: null,
        );

        $history = $this->builder->build($piDto);

        self::assertSame('completed', $history[0]['status']);
    }

    public function testBuildSetsCreatedAtFromTimestamp(): void
    {
        $piDto = new StripePaymentIntentDto(
            id: 'pi_ts',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: null,
            charge: null,
        );

        $history = $this->builder->build($piDto);

        self::assertSame(date('Y-m-d H:i:s', 1700000000), $history[0]['createdAt']);
    }

    public function testBuildHandlesMultipleRefunds(): void
    {
        $refund1 = new StripeRefundDto(id: 'rf_1', amount: 1000, currency: 'eur', status: 'succeeded', reason: null, createdAt: 1700000200);
        $refund2 = new StripeRefundDto(id: 'rf_2', amount: 2000, currency: 'eur', status: 'succeeded', reason: null, createdAt: 1700000300);
        $chargeDto = new StripeChargeDto(
            id: 'ch_multi',
            amount: 10000,
            amountCaptured: 10000,
            amountRefunded: 3000,
            currency: 'eur',
            captured: true,
            created: 1700000100,
            refunds: [$refund1, $refund2],
        );
        $piDto = new StripePaymentIntentDto(
            id: 'pi_multi',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: 'ch_multi',
            charge: $chargeDto,
        );

        $history = $this->builder->build($piDto);

        // authorization + capture + 2 refunds
        self::assertCount(4, $history);
        self::assertSame('refund', $history[2]['type']);
        self::assertSame('rf_1', $history[2]['transactionId']);
        self::assertSame('refund', $history[3]['type']);
        self::assertSame('rf_2', $history[3]['transactionId']);
    }

    public function testBuildDoesNotAddCaptureRowWhenNotCaptured(): void
    {
        // Charge exists but is not yet captured (e.g. auth-only)
        $chargeDto = new StripeChargeDto(
            id: 'ch_uncaptured',
            amount: 10000,
            amountCaptured: 0,
            amountRefunded: 0,
            currency: 'eur',
            captured: false,
            created: 1700000100,
        );
        $piDto = new StripePaymentIntentDto(
            id: 'pi_uncaptured',
            status: 'requires_capture',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: 'ch_uncaptured',
            charge: $chargeDto,
        );

        $history = $this->builder->build($piDto);

        // Only authorization — no capture row since captured=false
        self::assertCount(1, $history);
        self::assertSame('authorization', $history[0]['type']);
    }
}
