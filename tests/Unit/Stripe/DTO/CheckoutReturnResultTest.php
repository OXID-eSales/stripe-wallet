<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\DTO;

use OxidEsales\Payments\Stripe\DTO\CheckoutReturnResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CheckoutReturnResult DTO.
 *
 * Sprint 8: Comprehensive test coverage for delayed capture feature.
 *
 * Tests the immutable Result Object pattern implementation:
 * - Named constructors (success, failure, securityFailure)
 * - PaymentIntent status handling (requires_capture, succeeded)
 * - Amount conversion (cents to decimal)
 */
class CheckoutReturnResultTest extends TestCase
{
    // =========================================================================
    // Success factory method tests
    // =========================================================================

    /**
     * @test
     */
    public function successCreatesSuccessfulResult(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test_456',
            9999,
            'eur'
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('contract_123', $result->getContractId());
        $this->assertEquals('pi_test_456', $result->getPaymentIntentId());
        $this->assertEquals(9999, $result->getAmountCents());
        $this->assertEquals('eur', $result->getCurrency());
    }

    /**
     * @test
     */
    public function successDefaultsPaymentStatusToPaid(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            1000,
            'eur'
        );

        $this->assertEquals('paid', $result->getPaymentStatus());
    }

    /**
     * @test
     */
    public function successDefaultsPaymentIntentStatusToSucceeded(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            1000,
            'eur'
        );

        $this->assertEquals('succeeded', $result->getPaymentIntentStatus());
    }

    /**
     * @test
     */
    public function successDefaultsRedirectTargetToThankyou(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            1000,
            'eur'
        );

        $this->assertEquals('thankyou', $result->getRedirectTarget());
    }

    /**
     * @test
     */
    public function successHasNoErrorDetails(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            1000,
            'eur'
        );

        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    // =========================================================================
    // Failure factory method tests
    // =========================================================================

    /**
     * @test
     */
    public function failureCreatesFailedResult(): void
    {
        $result = CheckoutReturnResult::failure(
            'Payment declined',
            'card_declined'
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Payment declined', $result->getErrorMessage());
        $this->assertEquals('card_declined', $result->getErrorCode());
    }

    /**
     * @test
     */
    public function failureDefaultsRedirectTargetToPayment(): void
    {
        $result = CheckoutReturnResult::failure('Error');

        $this->assertEquals('payment', $result->getRedirectTarget());
    }

    /**
     * @test
     */
    public function failureHasNullPaymentDetails(): void
    {
        $result = CheckoutReturnResult::failure('Error');

        $this->assertNull($result->getContractId());
        $this->assertNull($result->getPaymentIntentId());
        $this->assertNull($result->getAmountCents());
        $this->assertNull($result->getCurrency());
        $this->assertNull($result->getPaymentStatus());
        $this->assertNull($result->getPaymentIntentStatus());
    }

    /**
     * @test
     */
    public function failureAllowsCustomRedirectTarget(): void
    {
        $result = CheckoutReturnResult::failure(
            'Error',
            'custom_error',
            'basket'
        );

        $this->assertEquals('basket', $result->getRedirectTarget());
    }

    // =========================================================================
    // Security failure factory method tests
    // =========================================================================

    /**
     * @test
     */
    public function securityFailureCreatesFailedResult(): void
    {
        $result = CheckoutReturnResult::securityFailure('Token mismatch');

        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Token mismatch', $result->getErrorMessage());
        $this->assertEquals('security_check_failed', $result->getErrorCode());
    }

    /**
     * @test
     */
    public function securityFailureRedirectsToPayment(): void
    {
        $result = CheckoutReturnResult::securityFailure('Invalid token');

        $this->assertEquals('payment', $result->getRedirectTarget());
    }

    // =========================================================================
    // PaymentIntent status tests (Manual Capture Mode)
    // =========================================================================

    /**
     * @test
     */
    public function isRequiresCaptureReturnsTrueForRequiresCaptureStatus(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            5000,
            'eur',
            'paid',
            'requires_capture'
        );

        $this->assertTrue($result->isRequiresCapture());
        $this->assertFalse($result->isCaptured());
    }

    /**
     * @test
     */
    public function isCapturedReturnsTrueForSucceededStatus(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            5000,
            'eur',
            'paid',
            'succeeded'
        );

        $this->assertTrue($result->isCaptured());
        $this->assertFalse($result->isRequiresCapture());
    }

    /**
     * @test
     */
    public function isRequiresCaptureReturnsFalseForOtherStatuses(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            5000,
            'eur',
            'paid',
            'processing'
        );

        $this->assertFalse($result->isRequiresCapture());
        $this->assertFalse($result->isCaptured());
    }

    /**
     * @test
     */
    public function failureResultIsNeitherCapturedNorRequiresCapture(): void
    {
        $result = CheckoutReturnResult::failure('Error');

        $this->assertFalse($result->isRequiresCapture());
        $this->assertFalse($result->isCaptured());
    }

    // =========================================================================
    // Amount conversion tests
    // =========================================================================

    /**
     * @test
     */
    public function getAmountConvertsCentsToDecimal(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            2550,
            'eur'
        );

        $this->assertEquals(25.50, $result->getAmount());
    }

    /**
     * @test
     */
    public function getAmountReturnsWholeNumbersCorrectly(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            10000,
            'eur'
        );

        $this->assertEquals(100.00, $result->getAmount());
    }

    /**
     * @test
     */
    public function getAmountReturnsNullForFailure(): void
    {
        $result = CheckoutReturnResult::failure('Error');

        $this->assertNull($result->getAmount());
    }

    /**
     * @test
     */
    public function getAmountHandlesZeroCents(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            0,
            'eur'
        );

        $this->assertEquals(0.0, $result->getAmount());
    }

    // =========================================================================
    // Custom payment status tests
    // =========================================================================

    /**
     * @test
     */
    public function successAcceptsCustomPaymentStatus(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            1000,
            'eur',
            'unpaid'
        );

        $this->assertEquals('unpaid', $result->getPaymentStatus());
    }

    /**
     * @test
     */
    public function successAcceptsCustomPaymentIntentStatus(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_test',
            1000,
            'eur',
            'paid',
            'requires_action'
        );

        $this->assertEquals('requires_action', $result->getPaymentIntentStatus());
    }
}
