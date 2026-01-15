<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentComponent\Repository\PaymentCustomerRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\StripeCustomerService;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;

/**
 * TDD Tests for StripeCustomerService using PaymentCustomerRepositoryInterface
 *
 * Sprint 2 Phase 2: StripeCustomerService must use the repository interface
 * instead of raw SQL queries to osc_stripe_customer_mapping table.
 *
 * LSP Compliance: Service depends on interface, not concrete implementation.
 *
 * @group sprint-2
 * @group customer-consolidation
 */
class StripeCustomerServiceRepositoryTest extends TestCase
{
    /**
     * @test
     * RED: StripeCustomerService should accept PaymentCustomerRepositoryInterface
     */
    public function serviceAcceptsPaymentCustomerRepositoryInterface(): void
    {
        $config = $this->createMock(ModuleConfigurationService::class);
        $repository = $this->createMock(PaymentCustomerRepositoryInterface::class);

        $service = new StripeCustomerService($config, $repository);

        $this->assertInstanceOf(StripeCustomerService::class, $service);
    }

    /**
     * @test
     * RED: Service should use repository to get stored customer ID
     */
    public function serviceUsesRepositoryToGetStoredCustomerId(): void
    {
        $config = $this->createMock(ModuleConfigurationService::class);
        $repository = $this->createMock(PaymentCustomerRepositoryInterface::class);

        $repository
            ->expects($this->once())
            ->method('findPaymentCustomerId')
            ->with('user_123')
            ->willReturn('cus_stripe_abc');

        $service = new StripeCustomerService($config, $repository);

        // Use reflection to call private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('getStoredStripeCustomerId');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'user_123');

        $this->assertEquals('cus_stripe_abc', $result);
    }

    /**
     * @test
     * RED: Service should use repository to store customer ID
     */
    public function serviceUsesRepositoryToStoreCustomerId(): void
    {
        $config = $this->createMock(ModuleConfigurationService::class);
        $repository = $this->createMock(PaymentCustomerRepositoryInterface::class);

        $repository
            ->expects($this->once())
            ->method('savePaymentCustomerId')
            ->with('user_456', 'cus_new_xyz');

        $service = new StripeCustomerService($config, $repository);

        // Use reflection to call private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('storeStripeCustomerId');
        $method->setAccessible(true);

        $method->invoke($service, 'user_456', 'cus_new_xyz');
    }

    /**
     * @test
     * RED: Service should return null when repository returns null
     */
    public function serviceReturnsNullWhenRepositoryReturnsNull(): void
    {
        $config = $this->createMock(ModuleConfigurationService::class);
        $repository = $this->createMock(PaymentCustomerRepositoryInterface::class);

        $repository
            ->expects($this->once())
            ->method('findPaymentCustomerId')
            ->with('nonexistent_user')
            ->willReturn(null);

        $service = new StripeCustomerService($config, $repository);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('getStoredStripeCustomerId');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'nonexistent_user');

        $this->assertNull($result);
    }

    /**
     * @test
     * RED: Repository parameter should be optional for backward compatibility
     */
    public function repositoryParameterShouldBeOptional(): void
    {
        $config = $this->createMock(ModuleConfigurationService::class);

        // Should not throw - repository is optional
        $service = new StripeCustomerService($config);

        $this->assertInstanceOf(StripeCustomerService::class, $service);
    }
}
