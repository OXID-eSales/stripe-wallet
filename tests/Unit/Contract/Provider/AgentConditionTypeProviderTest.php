<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Contract\Provider;

use OxidEsales\PaymentComponent\Contract\ConditionTypeProviderInterface;
use OxidEsales\PaymentComponent\Contract\Provider\AgentConditionTypeProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Contract\Provider\AgentConditionTypeProvider
 * @group sprint-49
 * @group contract
 */
final class AgentConditionTypeProviderTest extends TestCase
{
    /**
     * @test
     */
    public function implementsInterface(): void
    {
        $provider = new AgentConditionTypeProvider();

        $this->assertInstanceOf(ConditionTypeProviderInterface::class, $provider);
    }

    /**
     * @test
     */
    public function returnsExactlyTwoTypes(): void
    {
        // Arrange
        $provider = new AgentConditionTypeProvider();

        // Act
        $types = $provider->getConditionTypes();

        // Assert
        $this->assertCount(2, $types);
    }

    /**
     * @test
     */
    public function returnsAgentIdentityVerifiedType(): void
    {
        // Arrange
        $provider = new AgentConditionTypeProvider();

        // Act
        $types = $provider->getConditionTypes();

        // Assert
        $this->assertContains(AgentConditionTypeProvider::TYPE_AGENT_IDENTITY_VERIFIED, $types);
        $this->assertSame('agent_identity_verified', AgentConditionTypeProvider::TYPE_AGENT_IDENTITY_VERIFIED);
    }

    /**
     * @test
     */
    public function returnsAgentConsentConfirmedType(): void
    {
        // Arrange
        $provider = new AgentConditionTypeProvider();

        // Act
        $types = $provider->getConditionTypes();

        // Assert
        $this->assertContains(AgentConditionTypeProvider::TYPE_AGENT_CONSENT_CONFIRMED, $types);
        $this->assertSame('agent_consent_confirmed', AgentConditionTypeProvider::TYPE_AGENT_CONSENT_CONFIRMED);
    }

    /**
     * @test
     */
    public function returnsTypesInExpectedOrder(): void
    {
        // Arrange
        $provider = new AgentConditionTypeProvider();

        // Act
        $types = $provider->getConditionTypes();

        // Assert
        $expected = [
            AgentConditionTypeProvider::TYPE_AGENT_IDENTITY_VERIFIED,
            AgentConditionTypeProvider::TYPE_AGENT_CONSENT_CONFIRMED,
        ];
        $this->assertSame($expected, $types);
    }
}
