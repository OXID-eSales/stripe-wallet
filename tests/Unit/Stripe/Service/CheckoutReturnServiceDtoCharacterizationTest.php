<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutReturnService;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.10b: Characterization tests for CheckoutReturnService after DTO migration.
 *
 * Verifies behavior parity: CheckoutReturnService reads session data via
 * StripeCheckoutSessionDto instead of raw \Stripe\Checkout\Session.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\CheckoutReturnService::class)]
final class CheckoutReturnServiceDtoCharacterizationTest extends TestCase
{
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $adapter;
    private TokenServiceInterface&MockObject $tokenService;

    protected function setUp(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapter        = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->adapter);
        $this->tokenService   = $this->createMock(TokenServiceInterface::class);
        $this->tokenService->method('validateToken')->willReturn(true);
    }

    public function testValidateReturnSucceedsForPaidAutomaticCapture(): void
    {
        // Arrange — paid session (automatic capture)
        $session = new StripeCheckoutSessionDto(
            id: 'cs_test_auto',
            paymentStatus: 'paid',
            paymentIntentId: 'pi_auto_123',
            paymentIntentStatus: 'unknown',
            metadata: ['contract_id' => 'contract_42'],
            amountTotal: 19900,
            currency: 'eur',
        );
        $this->adapter->method('retrieveCheckoutSession')->willReturn($session);

        $service = new CheckoutReturnService($this->adapterFactory, $this->tokenService);

        // Act
        $result = $service->validateReturn('cs_test_auto', 'contract_42', 'token_valid');

        // Assert
        self::assertTrue($result->isSuccessful());
        self::assertSame('contract_42', $result->getContractId());
        self::assertSame('pi_auto_123', $result->getPaymentIntentId());
        self::assertSame(19900, $result->getAmountCents());
        self::assertSame('eur', $result->getCurrency());
        self::assertSame('paid', $result->getPaymentStatus());
    }

    public function testValidateReturnSucceedsForManualCaptureWithExpandedPi(): void
    {
        // Arrange — unpaid session with expanded PI requiring capture
        $session = new StripeCheckoutSessionDto(
            id: 'cs_test_manual',
            paymentStatus: 'unpaid',
            paymentIntentId: 'pi_manual_456',
            paymentIntentStatus: 'requires_capture',
            metadata: ['contract_id' => 'contract_99'],
            amountTotal: 5000,
            currency: 'usd',
        );
        $this->adapter->method('retrieveCheckoutSession')->willReturn($session);

        $service = new CheckoutReturnService($this->adapterFactory, $this->tokenService);

        // Act
        $result = $service->validateReturn('cs_test_manual', 'contract_99', 'token_valid');

        // Assert
        self::assertTrue($result->isSuccessful());
        self::assertSame('pi_manual_456', $result->getPaymentIntentId());
        self::assertSame('unpaid', $result->getPaymentStatus());
        self::assertSame('requires_capture', $result->getPaymentIntentStatus());
    }

    public function testValidateReturnFailsWhenPaymentStatusUnpaidWithoutRequiresCapture(): void
    {
        // Arrange — unpaid session with cancelled PI (not manual capture scenario)
        $session = new StripeCheckoutSessionDto(
            id: 'cs_test_invalid',
            paymentStatus: 'unpaid',
            paymentIntentId: 'pi_invalid',
            paymentIntentStatus: 'canceled',
            metadata: ['contract_id' => 'contract_77'],
            amountTotal: 3000,
            currency: 'eur',
        );
        $this->adapter->method('retrieveCheckoutSession')->willReturn($session);

        $service = new CheckoutReturnService($this->adapterFactory, $this->tokenService);

        // Act
        $result = $service->validateReturn('cs_test_invalid', 'contract_77', 'token_valid');

        // Assert
        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('not completed', $result->getErrorMessage() ?? '');
    }

    public function testValidateReturnFailsOnContractIdMismatch(): void
    {
        // Arrange — session metadata has different contract ID
        $session = new StripeCheckoutSessionDto(
            id: 'cs_mismatch',
            paymentStatus: 'paid',
            paymentIntentId: 'pi_mismatch',
            paymentIntentStatus: 'unknown',
            metadata: ['contract_id' => 'contract_OTHER'],
            amountTotal: 5000,
            currency: 'eur',
        );
        $this->adapter->method('retrieveCheckoutSession')->willReturn($session);

        $service = new CheckoutReturnService($this->adapterFactory, $this->tokenService);

        // Act
        $result = $service->validateReturn('cs_mismatch', 'contract_42', 'token_valid');

        // Assert
        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('mismatch', $result->getErrorMessage() ?? '');
    }

    public function testValidateReturnFailsWhenApiThrows(): void
    {
        // Arrange — adapter throws on session retrieve
        $this->adapter->method('retrieveCheckoutSession')
            ->willThrowException(new PaymentAdapterException('stripe', 'err', 'API error'));

        $service = new CheckoutReturnService($this->adapterFactory, $this->tokenService);

        // Act
        $result = $service->validateReturn('cs_throw', 'contract_42', 'token_valid');

        // Assert
        self::assertFalse($result->isSuccessful());
    }
}
