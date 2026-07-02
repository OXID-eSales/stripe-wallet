<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Response\CancellationResponse;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationService;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * Unit tests for CancelAuthorizationService.
 *
 * Sprint 11: Tests for the extracted cancel authorization service.
 * Sprint 26: Updated to use factory instead of direct adapter injection.
 * Sprint 31: Updated to use CancellationResponse instead of CancellationResult.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\CancelAuthorizationService::class)]
class CancelAuthorizationServiceTest extends TestCase
{
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;

    protected function setUp(): void
    {
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->stripeAdapter);
    }

    private function createService(): CancelAuthorizationService
    {
        return new CancelAuthorizationService($this->adapterFactory, new NullLogger());
    }

    public function testImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(CancelAuthorizationServiceInterface::class, $service);
    }

    public function testConstructorAcceptsNullLogger(): void
    {
        $service = new CancelAuthorizationService($this->adapterFactory, null);

        $this->assertInstanceOf(CancelAuthorizationServiceInterface::class, $service);
    }

    public function testCancelAuthorizationReturnsSuccessResult(): void
    {
        // Arrange
        $paymentIntent = $this->buildCancelledPiDto('canceled');

        $this->stripeAdapter->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_test_123', 'requested_by_customer')
            ->willReturn($paymentIntent);

        $service = $this->createService();

        // Act
        $result = $service->cancelAuthorization('pi_test_123', 'requested_by_customer');

        // Assert
        $this->assertInstanceOf(CancellationResponse::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('pi_test_123', $result->providerPaymentId);
        $this->assertSame('canceled', $result->status);
        $this->assertNull($result->errorMessage);
        $this->assertNull($result->errorCode);
    }

    public function testCancelAuthorizationReturnsSuccessWithNullReason(): void
    {
        // Arrange
        $paymentIntent = $this->buildCancelledPiDto('canceled');

        $this->stripeAdapter->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_test_456', null)
            ->willReturn($paymentIntent);

        $service = $this->createService();

        // Act
        $result = $service->cancelAuthorization('pi_test_456', null);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('canceled', $result->status);
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
        $this->assertInstanceOf(CancellationResponse::class, $result);
        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->providerPaymentId);
        $this->assertSame('failed', $result->status);
        $this->assertSame('API Error: Payment already canceled', $result->errorMessage);
    }

    public function testCancelAuthorizationReturnsCanceledStatus(): void
    {
        // Arrange — StripePaymentIntentDto always has a string status (never null)
        $paymentIntent = $this->buildCancelledPiDto('canceled');

        $this->stripeAdapter->expects($this->once())
            ->method('cancelPaymentIntent')
            ->willReturn($paymentIntent);

        $service = $this->createService();

        // Act
        $result = $service->cancelAuthorization('pi_test_canceled', null);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('canceled', $result->status);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildCancelledPiDto(string $status): StripePaymentIntentDto
    {
        return new StripePaymentIntentDto(
            id: 'pi_test',
            status: $status,
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: null,
        );
    }
}
