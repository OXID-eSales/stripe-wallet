<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Factory;

use OxidEsales\PaymentComponent\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\IdempotentStripeAdapter;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapter;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\IdempotentStripeAdapterFactory;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactory;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IdempotentStripeAdapterFactory.
 *
 * Sprint 42: Idempotency implementation.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\Factory\IdempotentStripeAdapterFactory
 * @group sprint-42
 * @group idempotency
 */
final class IdempotentStripeAdapterFactoryTest extends TestCase
{
    private StripeAdapterFactory&MockObject $innerFactory;
    private IdempotencyRepositoryInterface&MockObject $repository;
    private IdempotentStripeAdapterFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->innerFactory = $this->createMock(StripeAdapterFactory::class);
        $this->repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $this->factory = new IdempotentStripeAdapterFactory($this->innerFactory, $this->repository);
    }

    /**
     * @test
     */
    public function implementsStripeAdapterFactoryInterface(): void
    {
        $this->assertInstanceOf(StripeAdapterFactoryInterface::class, $this->factory);
    }

    /**
     * @test
     */
    public function getStripeAdapterReturnsIdempotentAdapter(): void
    {
        $innerAdapter = $this->createMock(StripeAdapterInterface::class);

        $this->innerFactory
            ->expects($this->once())
            ->method('getStripeAdapter')
            ->willReturn($innerAdapter);

        $result = $this->factory->getStripeAdapter();

        $this->assertInstanceOf(IdempotentStripeAdapter::class, $result);
    }

    /**
     * @test
     */
    public function delegatesCreateAdapterToInner(): void
    {
        $innerAdapter = $this->createMock(StripeAdapterInterface::class);

        $this->innerFactory
            ->expects($this->once())
            ->method('createAdapter')
            ->with('stripe')
            ->willReturn($innerAdapter);

        $result = $this->factory->createAdapter('stripe');

        $this->assertSame($innerAdapter, $result);
    }

    /**
     * @test
     */
    public function delegatesCreateDefaultAdapterToInner(): void
    {
        $innerAdapter = $this->createMock(StripeAdapterInterface::class);

        $this->innerFactory
            ->expects($this->once())
            ->method('createDefaultAdapter')
            ->willReturn($innerAdapter);

        $result = $this->factory->createDefaultAdapter();

        $this->assertSame($innerAdapter, $result);
    }

    /**
     * @test
     */
    public function delegatesIsProviderSupportedToInner(): void
    {
        $this->innerFactory
            ->expects($this->once())
            ->method('isProviderSupported')
            ->with('stripe')
            ->willReturn(true);

        $this->assertTrue($this->factory->isProviderSupported('stripe'));
    }

    /**
     * @test
     */
    public function delegatesGetSupportedProvidersToInner(): void
    {
        $this->innerFactory
            ->expects($this->once())
            ->method('getSupportedProviders')
            ->willReturn(['stripe']);

        $this->assertSame(['stripe'], $this->factory->getSupportedProviders());
    }

    /**
     * @test
     */
    public function delegatesIsTestModeToInner(): void
    {
        $this->innerFactory
            ->expects($this->once())
            ->method('isTestMode')
            ->willReturn(true);

        $this->assertTrue($this->factory->isTestMode());
    }
}
