<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripeOrderApiService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.10b: Characterization tests for StripeOrderApiService with DTOs.
 *
 * StripeOrderApiService is final and reads OXID magic fields ($order->oxorder__oxtransid->value)
 * that are impossible to inject. We test via a testable subclass that overrides the
 * protected trans-ID extraction seam. After Phase 4 (interface flip), the adapter mock
 * directly returns StripePaymentIntentDto objects — the mapper has moved into StripeAdapter.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\StripeOrderApiService::class)]
final class StripeOrderApiServiceDtoCharacterizationTest extends TestCase
{
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $adapter;

    protected function setUp(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapter        = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->adapter);
    }

    public function testGetPaymentIntentReturnsDto(): void
    {
        // Arrange — adapter now returns StripePaymentIntentDto directly (Phase 4 flip)
        $rawPi = new StripePaymentIntentDto(
            id: 'pi_svc_test',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: 'ch_svc_abc',
        );
        $this->adapter->method('retrievePaymentIntent')->willReturn($rawPi);

        $service = $this->buildServiceWithTransId('pi_svc_test');
        $order   = $this->buildOrderWithTransId('pi_svc_test');

        // Act
        $dto = $service->getPaymentIntent($order);

        // Assert
        self::assertInstanceOf(StripePaymentIntentDto::class, $dto);
        self::assertSame('pi_svc_test', $dto->id);
        self::assertSame('succeeded', $dto->status);
        self::assertSame(10000, $dto->amount);
        self::assertSame('eur', $dto->currency);
        self::assertSame('ch_svc_abc', $dto->latestChargeId);
        self::assertNull($dto->charge);
    }

    public function testGetPaymentIntentReturnsNullWhenNoTransId(): void
    {
        // Arrange — empty trans ID → must short-circuit to null
        $service = $this->buildServiceWithTransId('');
        $order   = $this->buildOrderWithTransId('');

        // Act
        $dto = $service->getPaymentIntent($order);

        // Assert
        self::assertNull($dto);
    }

    public function testGetPaymentIntentWithRefundsReturnsExpandedDto(): void
    {
        // Arrange — adapter returns StripePaymentIntentDto with expanded StripeChargeDto
        $chargeDto = new StripeChargeDto(
            id: 'ch_expanded',
            amount: 5000,
            amountCaptured: 5000,
            amountRefunded: 0,
            currency: 'eur',
            captured: true,
            created: 1700000100,
        );
        $rawPi = new StripePaymentIntentDto(
            id: 'pi_expanded_test',
            status: 'succeeded',
            amount: 5000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: 'ch_expanded',
            charge: $chargeDto,
        );
        $this->adapter->method('retrievePaymentIntent')->willReturn($rawPi);

        $service = $this->buildServiceWithTransId('pi_expanded_test');
        $order   = $this->buildOrderWithTransId('pi_expanded_test');

        // Act
        $dto = $service->getPaymentIntentWithRefunds($order);

        // Assert PI
        self::assertInstanceOf(StripePaymentIntentDto::class, $dto);
        self::assertSame('pi_expanded_test', $dto->id);

        // Assert expanded charge
        $chargeDto = $dto->charge;
        self::assertNotNull($chargeDto);
        self::assertInstanceOf(StripeChargeDto::class, $chargeDto);
        self::assertSame('ch_expanded', $chargeDto->id);
        self::assertSame(5000, $chargeDto->amountCaptured);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a StripeOrderApiService wrapping the adapter factory mock,
     * paired with an anonymous Order subclass that exposes a synthetic
     * oxorder__oxtransid->value without needing OXID bootstrap.
     *
     * StripeOrderApiService is final — cannot be subclassed. We supply a
     * concrete Order subclass (skipping its parent constructor) and set the
     * oxorder__oxtransid dynamic property directly. OXID's BaseModel uses
     * PHP's dynamic property mechanism, which works on anonymous subclasses.
     *
     * @return array{StripeOrderApiService, Order}
     */
    private function buildServiceWithTransId(string $transId): StripeOrderApiService
    {
        return new StripeOrderApiService($this->adapterFactory);
    }

    private function buildOrderWithTransId(string $transId): Order
    {
        $order = new class extends Order {
            public function __construct() {}
        };

        $magicProp = new \stdClass();
        $magicProp->value = $transId;
        $order->oxorder__oxtransid = $magicProp;

        return $order;
    }
}

