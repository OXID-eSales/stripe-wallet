<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Contract\Provider;

use OxidEsales\PaymentComponent\Contract\ConditionTypeProviderInterface;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\Provider\CoreConditionTypeProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Contract\Provider\CoreConditionTypeProvider
 * @group sprint-49
 * @group contract
 */
final class CoreConditionTypeProviderTest extends TestCase
{
    /**
     * @test
     */
    public function implementsInterface(): void
    {
        $provider = new CoreConditionTypeProvider();

        $this->assertInstanceOf(ConditionTypeProviderInterface::class, $provider);
    }

    /**
     * @test
     */
    public function returnsExactlyFourTypes(): void
    {
        // Arrange
        $provider = new CoreConditionTypeProvider();

        // Act
        $types = $provider->getConditionTypes();

        // Assert
        $this->assertCount(4, $types);
    }

    /**
     * @test
     */
    public function returnsTypesMatchingContractConditionConstants(): void
    {
        // Arrange
        $provider = new CoreConditionTypeProvider();

        // Act
        $types = $provider->getConditionTypes();

        // Assert
        $this->assertContains(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $types);
        $this->assertContains(ContractCondition::TYPE_FRAUD_CHECK, $types);
        $this->assertContains(ContractCondition::TYPE_COMPLIANCE_CHECK, $types);
        $this->assertContains(ContractCondition::TYPE_ADDRESS_VALIDATED, $types);
    }

    /**
     * @test
     */
    public function returnsTypesInExpectedOrder(): void
    {
        // Arrange
        $provider = new CoreConditionTypeProvider();

        // Act
        $types = $provider->getConditionTypes();

        // Assert
        $expected = [
            ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            ContractCondition::TYPE_FRAUD_CHECK,
            ContractCondition::TYPE_COMPLIANCE_CHECK,
            ContractCondition::TYPE_ADDRESS_VALIDATED,
        ];
        $this->assertSame($expected, $types);
    }
}
