<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Contract;

use OxidEsales\PaymentComponent\Contract\ConditionTypeProviderInterface;
use OxidEsales\PaymentComponent\Contract\ConditionTypeRegistry;
use OxidEsales\PaymentComponent\Contract\ConditionTypeRegistryInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Contract\ConditionTypeRegistry
 * @group sprint-49
 * @group contract
 */
final class ConditionTypeRegistryTest extends TestCase
{
    /**
     * @test
     */
    public function implementsInterface(): void
    {
        $registry = new ConditionTypeRegistry([]);

        $this->assertInstanceOf(ConditionTypeRegistryInterface::class, $registry);
    }

    /**
     * @test
     */
    public function collectsTypesFromMultipleProviders(): void
    {
        // Arrange
        $providerA = $this->createMock(ConditionTypeProviderInterface::class);
        $providerA->method('getConditionTypes')->willReturn(['payment_authorized', 'fraud_check']);

        $providerB = $this->createMock(ConditionTypeProviderInterface::class);
        $providerB->method('getConditionTypes')->willReturn(['agent_identity_verified']);

        // Act
        $registry = new ConditionTypeRegistry([$providerA, $providerB]);

        // Assert
        $types = $registry->getRegisteredTypes();
        $this->assertCount(3, $types);
        $this->assertContains('payment_authorized', $types);
        $this->assertContains('fraud_check', $types);
        $this->assertContains('agent_identity_verified', $types);
    }

    /**
     * @test
     */
    public function isValidReturnsTrueForKnownType(): void
    {
        // Arrange
        $provider = $this->createMock(ConditionTypeProviderInterface::class);
        $provider->method('getConditionTypes')->willReturn(['payment_authorized', 'fraud_check']);

        $registry = new ConditionTypeRegistry([$provider]);

        // Act & Assert
        $this->assertTrue($registry->isValid('payment_authorized'));
        $this->assertTrue($registry->isValid('fraud_check'));
    }

    /**
     * @test
     */
    public function isValidReturnsFalseForUnknownType(): void
    {
        // Arrange
        $provider = $this->createMock(ConditionTypeProviderInterface::class);
        $provider->method('getConditionTypes')->willReturn(['payment_authorized']);

        $registry = new ConditionTypeRegistry([$provider]);

        // Act & Assert
        $this->assertFalse($registry->isValid('unknown_type'));
        $this->assertFalse($registry->isValid(''));
        $this->assertFalse($registry->isValid('fraud_check'));
    }

    /**
     * @test
     */
    public function getRegisteredTypesReturnsAllTypes(): void
    {
        // Arrange
        $provider = $this->createMock(ConditionTypeProviderInterface::class);
        $provider->method('getConditionTypes')->willReturn([
            'payment_authorized',
            'fraud_check',
            'compliance_check',
        ]);

        $registry = new ConditionTypeRegistry([$provider]);

        // Act
        $types = $registry->getRegisteredTypes();

        // Assert
        $this->assertCount(3, $types);
        $this->assertSame(['payment_authorized', 'fraud_check', 'compliance_check'], $types);
    }

    /**
     * @test
     */
    public function emptyProvidersResultInEmptyRegistry(): void
    {
        // Arrange & Act
        $registry = new ConditionTypeRegistry([]);

        // Assert
        $this->assertSame([], $registry->getRegisteredTypes());
        $this->assertFalse($registry->isValid('payment_authorized'));
    }

    /**
     * @test
     */
    public function deduplicatesTypesFromOverlappingProviders(): void
    {
        // Arrange
        $providerA = $this->createMock(ConditionTypeProviderInterface::class);
        $providerA->method('getConditionTypes')->willReturn(['payment_authorized', 'fraud_check']);

        $providerB = $this->createMock(ConditionTypeProviderInterface::class);
        $providerB->method('getConditionTypes')->willReturn(['fraud_check', 'compliance_check']);

        // Act
        $registry = new ConditionTypeRegistry([$providerA, $providerB]);

        // Assert
        $types = $registry->getRegisteredTypes();
        $this->assertCount(3, $types);
        $this->assertTrue($registry->isValid('payment_authorized'));
        $this->assertTrue($registry->isValid('fraud_check'));
        $this->assertTrue($registry->isValid('compliance_check'));
    }
}
