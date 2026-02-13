<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Contract;

use InvalidArgumentException;
use OxidEsales\PaymentComponent\Contract\ConditionTypeProviderInterface;
use OxidEsales\PaymentComponent\Contract\ConditionTypeRegistry;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Contract\ContractCondition
 * @covers \OxidEsales\PaymentComponent\Contract\ConditionTypeRegistry
 * @group sprint-49
 * @group contract
 */
final class ContractConditionRegistryIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ContractCondition::setConditionTypeRegistry(null);
        parent::tearDown();
    }

    /**
     * @test
     */
    public function customTypeAcceptedWhenRegistryIsSet(): void
    {
        // Arrange
        $provider = $this->createMock(ConditionTypeProviderInterface::class);
        $provider->method('getConditionTypes')->willReturn([
            'payment_authorized',
            'fraud_check',
            'custom_agent_check',
        ]);

        $registry = new ConditionTypeRegistry([$provider]);
        ContractCondition::setConditionTypeRegistry($registry);

        // Act
        $condition = new ContractCondition('custom_agent_check');

        // Assert
        $this->assertSame('custom_agent_check', $condition->getType());
        $this->assertTrue($condition->isPending());
    }

    /**
     * @test
     */
    public function customTypeRejectedWithoutRegistry(): void
    {
        // Arrange - no registry set (tearDown already clears it)
        ContractCondition::setConditionTypeRegistry(null);

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid condition type: custom_agent_check');

        // Act
        new ContractCondition('custom_agent_check');
    }

    /**
     * @test
     */
    public function standardTypesStillWorkWithRegistry(): void
    {
        // Arrange
        $provider = $this->createMock(ConditionTypeProviderInterface::class);
        $provider->method('getConditionTypes')->willReturn([
            ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            ContractCondition::TYPE_FRAUD_CHECK,
            ContractCondition::TYPE_COMPLIANCE_CHECK,
            ContractCondition::TYPE_ADDRESS_VALIDATED,
        ]);

        $registry = new ConditionTypeRegistry([$provider]);
        ContractCondition::setConditionTypeRegistry($registry);

        // Act & Assert - all standard types should work
        $paymentAuth = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $this->assertSame('payment_authorized', $paymentAuth->getType());

        $fraudCheck = new ContractCondition(ContractCondition::TYPE_FRAUD_CHECK);
        $this->assertSame('fraud_check', $fraudCheck->getType());

        $compliance = new ContractCondition(ContractCondition::TYPE_COMPLIANCE_CHECK);
        $this->assertSame('compliance_check', $compliance->getType());

        $address = new ContractCondition(ContractCondition::TYPE_ADDRESS_VALIDATED);
        $this->assertSame('address_validated', $address->getType());
    }

    /**
     * @test
     */
    public function standardTypesWorkWithoutRegistry(): void
    {
        // Arrange - no registry (fallback to hardcoded types)
        ContractCondition::setConditionTypeRegistry(null);

        // Act & Assert
        $condition = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $this->assertSame('payment_authorized', $condition->getType());
    }

    /**
     * @test
     */
    public function unknownTypeRejectedByRegistryValidation(): void
    {
        // Arrange
        $provider = $this->createMock(ConditionTypeProviderInterface::class);
        $provider->method('getConditionTypes')->willReturn(['payment_authorized']);

        $registry = new ConditionTypeRegistry([$provider]);
        ContractCondition::setConditionTypeRegistry($registry);

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid condition type: totally_unknown');

        // Act
        new ContractCondition('totally_unknown');
    }

    /**
     * @test
     */
    public function factoryMethodsWorkWithRegistryIncludingAgentTypes(): void
    {
        // Arrange - registry that includes both core and agent types
        $coreProvider = $this->createMock(ConditionTypeProviderInterface::class);
        $coreProvider->method('getConditionTypes')->willReturn([
            ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            ContractCondition::TYPE_FRAUD_CHECK,
            ContractCondition::TYPE_COMPLIANCE_CHECK,
            ContractCondition::TYPE_ADDRESS_VALIDATED,
        ]);

        $agentProvider = $this->createMock(ConditionTypeProviderInterface::class);
        $agentProvider->method('getConditionTypes')->willReturn([
            'agent_identity_verified',
            'agent_consent_confirmed',
        ]);

        $registry = new ConditionTypeRegistry([$coreProvider, $agentProvider]);
        ContractCondition::setConditionTypeRegistry($registry);

        // Act & Assert
        $condition = ContractCondition::paymentAuthorized();
        $this->assertSame('payment_authorized', $condition->getType());

        $agentCondition = ContractCondition::agentIdentityVerified();
        $this->assertSame('agent_identity_verified', $agentCondition->getType());
    }
}
