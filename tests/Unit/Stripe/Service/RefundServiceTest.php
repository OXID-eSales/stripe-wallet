<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidSolutionCatalysts\Payments\Stripe\DTO\RefundResult;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\RefundService;
use OxidSolutionCatalysts\Payments\Stripe\Service\RefundServiceInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\PaymentAdapterException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stripe\PaymentIntent;
use Stripe\Refund;

/**
 * TDD Tests for RefundService.
 *
 * Sprint 21: Extract business logic from StripeRefundRequestHandler.
 */
class RefundServiceTest extends TestCase
{
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->adapterFactory
            ->method('getStripeAdapter')
            ->willReturn($this->stripeAdapter);
    }

    private function createService(): RefundService
    {
        return new RefundService(
            $this->adapterFactory,
            $this->logger
        );
    }

    // --- RefundResult DTO Tests ---

    public function testRefundResultSuccessCreation(): void
    {
        $result = RefundResult::success('re_123', 2550, 'eur', 'succeeded');

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('re_123', $result->getRefundId());
        $this->assertEquals(2550, $result->getRefundedAmountCents());
        $this->assertEquals(25.50, $result->getRefundedAmount());
        $this->assertEquals('eur', $result->getCurrency());
        $this->assertEquals('succeeded', $result->getStatus());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testRefundResultFailureCreation(): void
    {
        $result = RefundResult::failure('Charge already refunded', 'charge_already_refunded');

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->getRefundId());
        $this->assertNull($result->getRefundedAmountCents());
        $this->assertNull($result->getRefundedAmount());
        $this->assertEquals('Charge already refunded', $result->getErrorMessage());
        $this->assertEquals('charge_already_refunded', $result->getErrorCode());
    }

    public function testRefundResultPendingStatus(): void
    {
        $result = RefundResult::success('re_pending', 1000, 'usd', 'pending');

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('pending', $result->getStatus());
    }

    // --- Service Interface Tests ---

    public function testServiceImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(RefundServiceInterface::class, $service);
    }

    // --- processRefundByCharge Tests ---

    public function testProcessRefundByChargeSuccessful(): void
    {
        // Arrange
        $chargeId = 'ch_test_123';
        $amountCents = 5000;
        $reason = 'requested_by_customer';
        $metadata = ['order_id' => 'order_123'];

        $refund = Refund::constructFrom([
            'id' => 're_success_123',
            'amount' => $amountCents,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with($chargeId, $amountCents, $reason, $metadata)
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge($chargeId, $amountCents, $reason, $metadata);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('re_success_123', $result->getRefundId());
        $this->assertEquals(5000, $result->getRefundedAmountCents());
        $this->assertEquals('eur', $result->getCurrency());
        $this->assertEquals('succeeded', $result->getStatus());
    }

    public function testProcessRefundByChargeFullRefund(): void
    {
        // Arrange - null amount means full refund
        $chargeId = 'ch_full_456';

        $refund = Refund::constructFrom([
            'id' => 're_full_456',
            'amount' => 10000,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with($chargeId, null, null, null)
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge($chargeId);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(10000, $result->getRefundedAmountCents());
    }

    public function testProcessRefundByChargePendingStatus(): void
    {
        // Arrange
        $refund = Refund::constructFrom([
            'id' => 're_pending',
            'amount' => 2500,
            'currency' => 'usd',
            'status' => 'pending',
        ]);

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test');

        // Assert - pending is still successful
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('pending', $result->getStatus());
    }

    public function testProcessRefundByChargeFailedStatus(): void
    {
        // Arrange
        $refund = Refund::constructFrom([
            'id' => 're_failed',
            'amount' => 2500,
            'currency' => 'usd',
            'status' => 'failed',
        ]);

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('failed', $result->getErrorMessage() ?? '');
    }

    public function testProcessRefundByChargeHandlesAdapterException(): void
    {
        // Arrange
        $exception = new PaymentAdapterException(
            'stripe',
            'charge_already_refunded',
            'Charge already refunded'
        );

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error');

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Charge already refunded', $result->getErrorMessage());
        $this->assertEquals('charge_already_refunded', $result->getErrorCode());
    }

    // --- processPartialRefund Tests ---

    public function testProcessPartialRefundWithPaymentIntentId(): void
    {
        // Arrange
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_test_123',
            'latest_charge' => 'ch_derived_123',
        ]);

        $refund = Refund::constructFrom([
            'id' => 're_partial_123',
            'amount' => 2500,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_test_123')
            ->willReturn($paymentIntent);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with(
                'ch_derived_123',
                2500,
                'requested_by_customer',
                $this->callback(function ($metadata) {
                    return $metadata['order_id'] === 'order_123'
                        && $metadata['initiator'] === 'admin';
                })
            )
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processPartialRefund(
            'order_123',
            2500,
            'pi_test_123',
            'requested_by_customer'
        );

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(2500, $result->getRefundedAmountCents());
    }

    public function testProcessPartialRefundRequiresPaymentIntentId(): void
    {
        // Arrange - no payment intent ID provided
        // Note: In real scenario, this would look up the order to get PI ID
        // For unit test, we expect failure when no PI ID is available

        // Act
        $service = $this->createService();
        $result = $service->processPartialRefund(
            'order_123',
            2500,
            null // No payment intent ID
        );

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Payment intent ID', $result->getErrorMessage() ?? '');
    }

    public function testProcessPartialRefundIncludesMetadata(): void
    {
        // Arrange
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_meta_test',
            'latest_charge' => 'ch_meta_test',
        ]);

        $refund = Refund::constructFrom([
            'id' => 're_meta',
            'amount' => 1500,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        $capturedMetadata = null;
        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->willReturnCallback(function ($chargeId, $amount, $reason, $metadata) use ($refund, &$capturedMetadata) {
                $capturedMetadata = $metadata;
                return $refund;
            });

        // Act
        $service = $this->createService();
        $service->processPartialRefund(
            'order_xyz',
            1500,
            'pi_meta_test',
            'duplicate',
            'Customer requested partial refund',
            'mcp'
        );

        // Assert
        $this->assertIsArray($capturedMetadata);
        $this->assertEquals('order_xyz', $capturedMetadata['order_id']);
        $this->assertEquals('mcp', $capturedMetadata['initiator']);
        $this->assertEquals('Customer requested partial refund', $capturedMetadata['description']);
    }

    // --- processFullRefund Tests ---

    public function testProcessFullRefundSuccess(): void
    {
        // Arrange
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_full_test',
            'latest_charge' => 'ch_full_test',
        ]);

        $refund = Refund::constructFrom([
            'id' => 're_full',
            'amount' => 10000,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with(
                'ch_full_test',
                null, // Full refund = null amount
                'requested_by_customer',
                $this->anything()
            )
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processFullRefund(
            'order_full',
            'pi_full_test',
            'requested_by_customer'
        );

        // Assert - Full refund returns amount from Stripe response
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(10000, $result->getRefundedAmountCents());
    }

    public function testProcessFullRefundHandlesChargeObjectInPaymentIntent(): void
    {
        // Arrange - PaymentIntent with expanded charge object (not just ID)
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_expanded',
            'latest_charge' => [
                'id' => 'ch_from_object',
                'amount' => 5000,
            ],
        ]);

        $refund = Refund::constructFrom([
            'id' => 're_from_object',
            'amount' => 5000,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with('ch_from_object', null, null, $this->anything())
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processFullRefund('order_obj', 'pi_expanded');

        // Assert
        $this->assertTrue($result->isSuccessful());
    }

    public function testProcessFullRefundFailsWhenNoCharge(): void
    {
        // Arrange
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_no_charge',
            'latest_charge' => null,
        ]);

        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        // Act
        $service = $this->createService();
        $result = $service->processFullRefund('order_no_charge', 'pi_no_charge');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('No charge found', $result->getErrorMessage() ?? '');
    }

    // --- Validation Tests ---

    public function testValidRefundReasons(): void
    {
        // Arrange
        $validReasons = ['duplicate', 'fraudulent', 'requested_by_customer'];

        // Act & Assert - each valid reason should pass through to adapter
        $service = $this->createService();
        foreach ($validReasons as $reason) {
            $refund = Refund::constructFrom([
                'id' => 're_' . $reason,
                'amount' => 1000,
                'currency' => 'eur',
                'status' => 'succeeded',
            ]);

            // Reset mock for each iteration
            $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
            $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
            $this->adapterFactory->method('getStripeAdapter')->willReturn($this->stripeAdapter);

            $this->stripeAdapter
                ->expects($this->once())
                ->method('createRefundByCharge')
                ->with('ch_test', 1000, $reason, null)
                ->willReturn($refund);

            $service = $this->createService();
            $result = $service->processRefundByCharge('ch_test', 1000, $reason);
            $this->assertTrue($result->isSuccessful(), "Reason '{$reason}' should be valid");
        }
    }

    // --- Logging Tests ---

    public function testSuccessfulRefundIsLogged(): void
    {
        // Arrange
        $refund = Refund::constructFrom([
            'id' => 're_logged',
            'amount' => 3000,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Refund processed'),
                $this->callback(function ($context) {
                    return isset($context['refund_id']) && $context['refund_id'] === 're_logged';
                })
            );

        // Act
        $service = $this->createService();
        $service->processRefundByCharge('ch_test', 3000);
    }

    public function testFailedRefundIsLoggedAsError(): void
    {
        // Arrange
        $exception = new PaymentAdapterException(
            'stripe',
            'test_code',
            'Test error'
        );

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Refund failed'),
                $this->callback(function ($context) {
                    return isset($context['error']) && isset($context['charge_id']);
                })
            );

        // Act
        $service = $this->createService();
        $service->processRefundByCharge('ch_test');
    }
}
