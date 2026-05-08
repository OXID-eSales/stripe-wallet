<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutReturnResult;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutReturnService;
use OxidEsales\Payments\Stripe\Service\CheckoutReturnServiceInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;

/**
 * TDD Tests for CheckoutReturnService.
 *
 * Sprint 21: Extract business logic from StripeCheckoutReturnHandler.
 */
class CheckoutReturnServiceTest extends TestCase
{
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private TokenServiceInterface&MockObject $tokenService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->tokenService = $this->createMock(TokenServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->adapterFactory
            ->method('getStripeAdapter')
            ->willReturn($this->stripeAdapter);
    }

    private function createService(): CheckoutReturnService
    {
        return new CheckoutReturnService(
            $this->adapterFactory,
            $this->tokenService,
            $this->logger
        );
    }

    // --- CheckoutReturnResult DTO Tests ---

    public function testCheckoutReturnResultSuccessCreation(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_123',
            'pi_abc',
            10000,
            'eur',
            'paid'
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('contract_123', $result->getContractId());
        $this->assertEquals('pi_abc', $result->getPaymentIntentId());
        $this->assertEquals(10000, $result->getAmountCents());
        $this->assertEquals(100.00, $result->getAmount());
        $this->assertEquals('eur', $result->getCurrency());
        $this->assertEquals('paid', $result->getPaymentStatus());
        $this->assertEquals('thankyou', $result->getRedirectTarget());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testCheckoutReturnResultFailureCreation(): void
    {
        $result = CheckoutReturnResult::failure('Payment not completed', 'payment_failed');

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->getContractId());
        $this->assertNull($result->getPaymentIntentId());
        $this->assertNull($result->getAmountCents());
        $this->assertEquals('Payment not completed', $result->getErrorMessage());
        $this->assertEquals('payment_failed', $result->getErrorCode());
        $this->assertEquals('payment', $result->getRedirectTarget());
    }

    public function testCheckoutReturnResultSecurityFailure(): void
    {
        $result = CheckoutReturnResult::securityFailure('Security validation failed');

        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('security_check_failed', $result->getErrorCode());
        $this->assertEquals('payment', $result->getRedirectTarget());
    }

    // --- Service Interface Tests ---

    public function testServiceImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(CheckoutReturnServiceInterface::class, $service);
    }

    // --- validateReturn Tests ---

    public function testValidateReturnSuccessful(): void
    {
        // Arrange
        $checkoutSessionId = 'cs_test_123';
        $contractId = 'contract_abc';
        $contractToken = 'valid_token';

        $this->tokenService
            ->expects($this->once())
            ->method('validateToken')
            ->with($contractToken, $contractId)
            ->willReturn(true);

        $session = Session::constructFrom([
            'id' => $checkoutSessionId,
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_123',
            'amount_total' => 10000,
            'currency' => 'eur',
            'metadata' => ['contract_id' => $contractId],
        ]);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('retrieveCheckoutSession')
            ->with($checkoutSessionId, ['payment_intent'])
            ->willReturn($session);

        // Act
        $service = $this->createService();
        $result = $service->validateReturn($checkoutSessionId, $contractId, $contractToken);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals($contractId, $result->getContractId());
        $this->assertEquals('pi_test_123', $result->getPaymentIntentId());
        $this->assertEquals(10000, $result->getAmountCents());
        $this->assertEquals('eur', $result->getCurrency());
        $this->assertEquals('paid', $result->getPaymentStatus());
    }

    public function testValidateReturnInvalidToken(): void
    {
        // Arrange
        $this->tokenService
            ->expects($this->once())
            ->method('validateToken')
            ->willReturn(false);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Invalid contract token'));

        // Act
        $service = $this->createService();
        $result = $service->validateReturn('cs_test', 'contract_id', 'invalid_token');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Invalid contract token', $result->getErrorMessage());
        $this->assertEquals('payment', $result->getRedirectTarget());
    }

    public function testValidateReturnPaymentNotCompleted(): void
    {
        // Arrange
        $this->tokenService
            ->method('validateToken')
            ->willReturn(true);

        $session = Session::constructFrom([
            'id' => 'cs_unpaid',
            'payment_status' => 'unpaid',
            'payment_intent' => 'pi_unpaid',
            'amount_total' => 5000,
            'currency' => 'eur',
            'metadata' => ['contract_id' => 'contract_123'],
        ]);

        $this->stripeAdapter
            ->method('retrieveCheckoutSession')
            ->willReturn($session);

        // Act
        $service = $this->createService();
        $result = $service->validateReturn('cs_unpaid', 'contract_123', 'token');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Payment not completed', $result->getErrorMessage() ?? '');
        $this->assertEquals('payment', $result->getRedirectTarget());
    }

    public function testValidateReturnContractIdMismatch(): void
    {
        // Arrange
        $this->tokenService
            ->method('validateToken')
            ->willReturn(true);

        $session = Session::constructFrom([
            'id' => 'cs_mismatch',
            'payment_status' => 'paid',
            'payment_intent' => [
                'id' => 'pi_mismatch',
                'status' => 'succeeded',
            ],
            'amount_total' => 5000,
            'currency' => 'eur',
            'metadata' => ['contract_id' => 'different_contract'],
        ]);

        $this->stripeAdapter
            ->method('retrieveCheckoutSession')
            ->willReturn($session);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Contract ID mismatch'));

        // Act
        $service = $this->createService();
        $result = $service->validateReturn('cs_mismatch', 'expected_contract', 'token');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Contract ID mismatch', $result->getErrorMessage());
    }

    public function testValidateReturnMissingContractIdInMetadata(): void
    {
        // Arrange
        $this->tokenService
            ->method('validateToken')
            ->willReturn(true);

        $session = Session::constructFrom([
            'id' => 'cs_no_metadata',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_no_meta',
            'amount_total' => 5000,
            'currency' => 'eur',
            'metadata' => [],
        ]);

        $this->stripeAdapter
            ->method('retrieveCheckoutSession')
            ->willReturn($session);

        // Act
        $service = $this->createService();
        $result = $service->validateReturn('cs_no_metadata', 'contract_123', 'token');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Contract ID not found', $result->getErrorMessage() ?? '');
    }

    public function testValidateReturnHandlesStripeApiError(): void
    {
        // Arrange
        $this->tokenService
            ->method('validateToken')
            ->willReturn(true);

        $exception = new PaymentAdapterException(
            'stripe',
            'resource_missing',
            'No such checkout session'
        );

        $this->stripeAdapter
            ->method('retrieveCheckoutSession')
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to retrieve checkout session'));

        // Act
        $service = $this->createService();
        $result = $service->validateReturn('cs_invalid', 'contract_123', 'token');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('checkout session', $result->getErrorMessage() ?? '');
    }

    public function testValidateReturnWithPaymentIntentObject(): void
    {
        // Arrange - PaymentIntent can be object instead of string
        $this->tokenService
            ->method('validateToken')
            ->willReturn(true);

        $session = Session::constructFrom([
            'id' => 'cs_object_pi',
            'payment_status' => 'paid',
            'payment_intent' => [
                'id' => 'pi_from_object',
                'amount' => 10000,
            ],
            'amount_total' => 10000,
            'currency' => 'usd',
            'metadata' => ['contract_id' => 'contract_obj'],
        ]);

        $this->stripeAdapter
            ->method('retrieveCheckoutSession')
            ->willReturn($session);

        // Act
        $service = $this->createService();
        $result = $service->validateReturn('cs_object_pi', 'contract_obj', 'token');

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('pi_from_object', $result->getPaymentIntentId());
    }

    // --- getSessionDetails Tests ---

    public function testGetSessionDetailsSuccess(): void
    {
        // Arrange
        $session = Session::constructFrom([
            'id' => 'cs_details',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_details',
            'amount_total' => 15000,
            'currency' => 'gbp',
            'metadata' => ['contract_id' => 'contract_details'],
        ]);

        $this->stripeAdapter
            ->method('retrieveCheckoutSession')
            ->willReturn($session);

        // Act
        $service = $this->createService();
        $details = $service->getSessionDetails('cs_details');

        // Assert
        $this->assertNotNull($details);
        $this->assertEquals('paid', $details['payment_status']);
        $this->assertEquals('pi_details', $details['payment_intent_id']);
        $this->assertEquals(15000, $details['amount_total']);
        $this->assertEquals('gbp', $details['currency']);
        $this->assertEquals('contract_details', $details['contract_id']);
    }

    public function testGetSessionDetailsReturnsNullOnError(): void
    {
        // Arrange
        $this->stripeAdapter
            ->method('retrieveCheckoutSession')
            ->willThrowException(new PaymentAdapterException('stripe', 'error', 'Failed'));

        // Act
        $service = $this->createService();
        $details = $service->getSessionDetails('cs_invalid');

        // Assert
        $this->assertNull($details);
    }

    // --- Logging Tests ---

    public function testSuccessfulValidationIsLogged(): void
    {
        // Arrange
        $this->tokenService
            ->method('validateToken')
            ->willReturn(true);

        $session = Session::constructFrom([
            'id' => 'cs_logged',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_logged',
            'amount_total' => 5000,
            'currency' => 'eur',
            'metadata' => ['contract_id' => 'contract_logged'],
        ]);

        $this->stripeAdapter
            ->method('retrieveCheckoutSession')
            ->willReturn($session);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Checkout return validated'),
                $this->callback(function ($context) {
                    return isset($context['contract_id']) && $context['contract_id'] === 'contract_logged';
                })
            );

        // Act
        $service = $this->createService();
        $service->validateReturn('cs_logged', 'contract_logged', 'token');
    }
}
