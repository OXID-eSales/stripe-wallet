<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\DTO\CancellationResult;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationService;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Stripe\PaymentIntent;

/**
 * Unit tests for CancelAuthorizationService.
 *
 * Sprint 11: Tests for the extracted cancel authorization service.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\CancelAuthorizationService
 */
class CancelAuthorizationServiceTest extends TestCase
{
    private StripeAdapterInterface&MockObject $stripeAdapter;

    protected function setUp(): void
    {
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
    }

    private function createService(): CancelAuthorizationService
    {
        return new CancelAuthorizationService($this->stripeAdapter, new NullLogger());
    }

    public function testImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(CancelAuthorizationServiceInterface::class, $service);
    }

    public function testConstructorAcceptsNullLogger(): void
    {
        $service = new CancelAuthorizationService($this->stripeAdapter, null);

        $this->assertInstanceOf(CancelAuthorizationServiceInterface::class, $service);
    }

    public function testCancelAuthorizationReturnsSuccessResult(): void
    {
        // Arrange
        $paymentIntent = $this->createMock(PaymentIntent::class);
        $paymentIntent->status = 'canceled';

        $this->stripeAdapter->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_test_123', 'requested_by_customer')
            ->willReturn($paymentIntent);

        $service = $this->createService();

        // Act
        $result = $service->cancelAuthorization('pi_test_123', 'requested_by_customer');

        // Assert
        $this->assertInstanceOf(CancellationResult::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('pi_test_123', $result->getPaymentIntentId());
        $this->assertSame('canceled', $result->getStatus());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testCancelAuthorizationReturnsSuccessWithNullReason(): void
    {
        // Arrange
        $paymentIntent = $this->createMock(PaymentIntent::class);
        $paymentIntent->status = 'canceled';

        $this->stripeAdapter->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_test_456', null)
            ->willReturn($paymentIntent);

        $service = $this->createService();

        // Act
        $result = $service->cancelAuthorization('pi_test_456', null);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('canceled', $result->getStatus());
    }

    public function testCancelAuthorizationReturnsFailureOnException(): void
    {
        // Arrange
        $this->stripeAdapter->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_test_789', 'duplicate')
            ->willThrowException(new \Exception('API Error: Payment already canceled'));

        $service = $this->createService();

        // Act
        $result = $service->cancelAuthorization('pi_test_789', 'duplicate');

        // Assert
        $this->assertInstanceOf(CancellationResult::class, $result);
        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->getPaymentIntentId());
        $this->assertNull($result->getStatus());
        $this->assertSame('API Error: Payment already canceled', $result->getErrorMessage());
    }

    public function testCancelAuthorizationHandlesDefaultStatusWhenNull(): void
    {
        // Arrange
        $paymentIntent = $this->createMock(PaymentIntent::class);
        $paymentIntent->status = null;

        $this->stripeAdapter->expects($this->once())
            ->method('cancelPaymentIntent')
            ->willReturn($paymentIntent);

        $service = $this->createService();

        // Act
        $result = $service->cancelAuthorization('pi_test_null', null);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('canceled', $result->getStatus());
    }
}
