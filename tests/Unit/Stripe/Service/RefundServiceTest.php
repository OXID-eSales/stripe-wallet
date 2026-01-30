<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\Response\RefundResponse;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\RefundService;
use OxidEsales\Payments\Stripe\Service\RefundServiceInterface;
use OxidEsales\PaymentComponent\Service\StockRestorationServiceInterface;
use OxidEsales\PaymentComponent\Adapter\Exception\PaymentAdapterException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stripe\PaymentIntent;
use Stripe\Refund;

/**
 * TDD Tests for RefundService.
 *
 * Sprint 21: Extract business logic from StripeRefundRequestHandler.
 * Sprint 24: Added StockRestorationService mock.
 */
class RefundServiceTest extends TestCase
{
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private StockRestorationServiceInterface&MockObject $stockRestorationService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->stockRestorationService = $this->createMock(StockRestorationServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->adapterFactory
            ->method('getStripeAdapter')
            ->willReturn($this->stripeAdapter);
    }

    private function createService(): RefundService
    {
        return new RefundService(
            $this->adapterFactory,
            $this->stockRestorationService,
            $this->logger
        );
    }

    // --- RefundResponse DTO Tests ---

    public function testRefundResponseSuccessCreation(): void
    {
        $refundedAt = new DateTimeImmutable();
        $result = RefundResponse::success(
            providerPaymentId: 'pi_123',
            refundId: 're_123',
            amountRefunded: 25.50,
            currency: 'eur',
            status: 'succeeded',
            refundedAt: $refundedAt
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('re_123', $result->refundId);
        $this->assertEquals(25.50, $result->amountRefunded);
        $this->assertEquals('eur', $result->currency);
        $this->assertEquals('succeeded', $result->status);
        $this->assertNull($result->errorMessage);
        $this->assertNull($result->errorCode);
    }

    public function testRefundResponseFailureCreation(): void
    {
        $result = RefundResponse::failure('Charge already refunded', 'charge_already_refunded');

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->refundId);
        $this->assertNull($result->amountRefunded);
        $this->assertEquals('Charge already refunded', $result->errorMessage);
        $this->assertEquals('charge_already_refunded', $result->errorCode);
    }

    public function testRefundResponsePendingStatus(): void
    {
        $result = RefundResponse::success(
            providerPaymentId: 'pi_pending',
            refundId: 're_pending',
            amountRefunded: 10.00,
            currency: 'usd',
            status: 'pending',
            refundedAt: new DateTimeImmutable()
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('pending', $result->status);
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
        $reason = 'requested_by_customer';
        $metadata = ['order_id' => 'order_123'];

        $refund = Refund::constructFrom([
            'id' => 're_success_123',
            'amount' => 5000,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with($chargeId, null, $reason, $metadata)
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge($chargeId, $reason, $metadata);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('re_success_123', $result->refundId);
        $this->assertEquals(50.00, $result->amountRefunded); // Amount in major units
        $this->assertEquals('eur', $result->currency);
        $this->assertEquals('succeeded', $result->status);
    }

    public function testProcessRefundByChargeFullRefund(): void
    {
        // Arrange - always full refund (Sprint 22)
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
        $this->assertEquals(100.00, $result->amountRefunded); // Amount in major units
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
        $this->assertEquals('pending', $result->status);
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
        $this->assertStringContainsString('failed', $result->errorMessage ?? '');
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
        $this->assertEquals('Charge already refunded', $result->errorMessage);
        $this->assertEquals('charge_already_refunded', $result->errorCode);
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
        $this->assertEquals(100.00, $result->amountRefunded); // Amount in major units
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
        $this->assertStringContainsString('No charge found', $result->errorMessage ?? '');
    }

    // --- Validation Tests ---

    public function testValidRefundReasons(): void
    {
        // Arrange
        $validReasons = ['duplicate', 'fraudulent', 'requested_by_customer'];

        // Act & Assert - each valid reason should pass through to adapter
        foreach ($validReasons as $reason) {
            $refund = Refund::constructFrom([
                'id' => 're_' . $reason,
                'amount' => 1000,
                'currency' => 'eur',
                'status' => 'succeeded',
            ]);

            // Reset mocks for each iteration
            $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
            $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
            $this->stockRestorationService = $this->createMock(StockRestorationServiceInterface::class);
            $this->adapterFactory->method('getStripeAdapter')->willReturn($this->stripeAdapter);

            $this->stripeAdapter
                ->expects($this->once())
                ->method('createRefundByCharge')
                ->with('ch_test', null, $reason, null)
                ->willReturn($refund);

            $service = $this->createService();
            $result = $service->processRefundByCharge('ch_test', $reason);
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

        // Sprint 24: Now logs twice - once for stock restoration (if orderId present), once for refund
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Refund processed'),
                $this->callback(function ($context) {
                    return isset($context['refund_id']) && $context['refund_id'] === 're_logged';
                })
            );

        // Act - no metadata means no orderId, so no stock restoration log
        $service = $this->createService();
        $service->processRefundByCharge('ch_test'); // Full refund (no amount parameter)
    }

    // --- Stock Restoration Tests (Sprint 24) ---

    public function testSuccessfulRefundCallsStockRestoration(): void
    {
        // Arrange
        $orderId = 'order_stock_test';
        $metadata = ['order_id' => $orderId];

        $refund = Refund::constructFrom([
            'id' => 're_stock',
            'amount' => 5000,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        $this->stockRestorationService
            ->expects($this->once())
            ->method('restoreStockForOrder')
            ->with($orderId)
            ->willReturn(2);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test', null, $metadata);

        // Assert
        $this->assertTrue($result->isSuccessful());
    }

    public function testRefundWithoutOrderIdSkipsStockRestoration(): void
    {
        // Arrange - no orderId in metadata
        $refund = Refund::constructFrom([
            'id' => 're_no_stock',
            'amount' => 3000,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        // Stock restoration should NOT be called
        $this->stockRestorationService
            ->expects($this->never())
            ->method('restoreStockForOrder');

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test');

        // Assert
        $this->assertTrue($result->isSuccessful());
    }

    public function testFailedRefundDoesNotCallStockRestoration(): void
    {
        // Arrange
        $refund = Refund::constructFrom([
            'id' => 're_failed',
            'amount' => 2500,
            'currency' => 'eur',
            'status' => 'failed',
        ]);

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        // Stock restoration should NOT be called on failed refund
        $this->stockRestorationService
            ->expects($this->never())
            ->method('restoreStockForOrder');

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test', null, ['order_id' => 'order_123']);

        // Assert
        $this->assertFalse($result->isSuccessful());
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
