<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Adapter;

use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeStatusMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeStatusMapper
 */
final class StripeStatusMapperTest extends TestCase
{
    public function testToNormalizedMapsRequiresPaymentMethod(): void
    {
        $normalized = StripeStatusMapper::toNormalized('requires_payment_method');

        $this->assertSame('pending', $normalized);
    }

    public function testToNormalizedMapsRequiresConfirmation(): void
    {
        $normalized = StripeStatusMapper::toNormalized('requires_confirmation');

        $this->assertSame('pending', $normalized);
    }

    public function testToNormalizedMapsRequiresAction(): void
    {
        $normalized = StripeStatusMapper::toNormalized('requires_action');

        $this->assertSame('pending', $normalized);
    }

    public function testToNormalizedMapsProcessing(): void
    {
        $normalized = StripeStatusMapper::toNormalized('processing');

        $this->assertSame('pending', $normalized);
    }

    public function testToNormalizedMapsRequiresCapture(): void
    {
        $normalized = StripeStatusMapper::toNormalized('requires_capture');

        $this->assertSame('authorized', $normalized);
    }

    public function testToNormalizedMapsSucceeded(): void
    {
        $normalized = StripeStatusMapper::toNormalized('succeeded');

        $this->assertSame('captured', $normalized);
    }

    public function testToNormalizedMapsCanceled(): void
    {
        $normalized = StripeStatusMapper::toNormalized('canceled');

        $this->assertSame('cancelled', $normalized);
    }

    public function testToNormalizedReturnsDefaultForUnknownStatus(): void
    {
        $normalized = StripeStatusMapper::toNormalized('unknown_status');

        $this->assertSame('pending', $normalized);
    }

    public function testFromPaymentIntentWithSucceeded(): void
    {
        $status = StripeStatusMapper::fromPaymentIntent(
            status: 'succeeded',
            amountCapturable: 0,
            amountReceived: 9999
        );

        $this->assertSame('captured', $status);
    }

    public function testFromPaymentIntentWithRequiresCapture(): void
    {
        $status = StripeStatusMapper::fromPaymentIntent(
            status: 'requires_capture',
            amountCapturable: 9999,
            amountReceived: 0
        );

        $this->assertSame('authorized', $status);
    }

    public function testFromPaymentIntentWithFullyRefunded(): void
    {
        $status = StripeStatusMapper::fromPaymentIntent(
            status: 'succeeded',
            amountCapturable: 0,
            amountReceived: 0  // Fully refunded
        );

        $this->assertSame('refunded', $status);
    }

    public function testFromPaymentIntentWithCanceled(): void
    {
        $status = StripeStatusMapper::fromPaymentIntent(
            status: 'canceled',
            amountCapturable: 0,
            amountReceived: 0
        );

        $this->assertSame('cancelled', $status);
    }

    public function testRequiresActionReturnsTrueForRequiresAction(): void
    {
        $this->assertTrue(StripeStatusMapper::requiresAction('requires_action'));
    }

    public function testRequiresActionReturnsFalseForOtherStatuses(): void
    {
        $this->assertFalse(StripeStatusMapper::requiresAction('succeeded'));
        $this->assertFalse(StripeStatusMapper::requiresAction('requires_capture'));
        $this->assertFalse(StripeStatusMapper::requiresAction('processing'));
    }

    public function testIsAuthorizedReturnsTrueForRequiresCapture(): void
    {
        $this->assertTrue(StripeStatusMapper::isAuthorized('requires_capture'));
    }

    public function testIsAuthorizedReturnsFalseForOtherStatuses(): void
    {
        $this->assertFalse(StripeStatusMapper::isAuthorized('succeeded'));
        $this->assertFalse(StripeStatusMapper::isAuthorized('processing'));
        $this->assertFalse(StripeStatusMapper::isAuthorized('canceled'));
    }

    public function testIsCapturedReturnsTrueForSucceeded(): void
    {
        $this->assertTrue(StripeStatusMapper::isCaptured('succeeded'));
    }

    public function testIsCapturedReturnsFalseForOtherStatuses(): void
    {
        $this->assertFalse(StripeStatusMapper::isCaptured('requires_capture'));
        $this->assertFalse(StripeStatusMapper::isCaptured('processing'));
        $this->assertFalse(StripeStatusMapper::isCaptured('canceled'));
    }

    public function testIsCancelledReturnsTrueForCanceled(): void
    {
        $this->assertTrue(StripeStatusMapper::isCancelled('canceled'));
    }

    public function testIsCancelledReturnsFalseForOtherStatuses(): void
    {
        $this->assertFalse(StripeStatusMapper::isCancelled('succeeded'));
        $this->assertFalse(StripeStatusMapper::isCancelled('requires_capture'));
        $this->assertFalse(StripeStatusMapper::isCancelled('processing'));
    }

    public function testIsProcessingReturnsTrueForProcessingStatuses(): void
    {
        $this->assertTrue(StripeStatusMapper::isProcessing('requires_payment_method'));
        $this->assertTrue(StripeStatusMapper::isProcessing('requires_confirmation'));
        $this->assertTrue(StripeStatusMapper::isProcessing('requires_action'));
        $this->assertTrue(StripeStatusMapper::isProcessing('processing'));
    }

    public function testIsProcessingReturnsFalseForFinalStatuses(): void
    {
        $this->assertFalse(StripeStatusMapper::isProcessing('succeeded'));
        $this->assertFalse(StripeStatusMapper::isProcessing('requires_capture'));
        $this->assertFalse(StripeStatusMapper::isProcessing('canceled'));
    }

    public function testAllStripeStatusesMapToNormalizedStatuses(): void
    {
        $stripeStatuses = [
            'requires_payment_method',
            'requires_confirmation',
            'requires_action',
            'processing',
            'requires_capture',
            'canceled',
            'succeeded',
        ];

        $normalizedStatuses = ['pending', 'authorized', 'captured', 'cancelled'];

        foreach ($stripeStatuses as $stripeStatus) {
            $normalized = StripeStatusMapper::toNormalized($stripeStatus);
            $this->assertContains(
                $normalized,
                $normalizedStatuses,
                "Stripe status '{$stripeStatus}' should map to a valid normalized status"
            );
        }
    }

    public function testNormalizedStatusesAreProviderAgnostic(): void
    {
        // Verify that all normalized statuses are generic (not Stripe-specific)
        $normalizedStatuses = [
            StripeStatusMapper::STATUS_PENDING,
            StripeStatusMapper::STATUS_AUTHORIZED,
            StripeStatusMapper::STATUS_CAPTURED,
            StripeStatusMapper::STATUS_FAILED,
            StripeStatusMapper::STATUS_CANCELLED,
            StripeStatusMapper::STATUS_REFUNDED,
            StripeStatusMapper::STATUS_PARTIALLY_REFUNDED,
        ];

        $genericStatuses = ['pending', 'authorized', 'captured', 'failed', 'cancelled', 'refunded', 'partially_refunded'];

        foreach ($normalizedStatuses as $status) {
            $this->assertContains($status, $genericStatuses);
            $this->assertStringNotContainsString('stripe', strtolower($status));
            $this->assertStringNotContainsString('unzer', strtolower($status));
            $this->assertStringNotContainsString('paypal', strtolower($status));
        }
    }
}
