<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Config;

use OxidEsales\Payments\Stripe\Config\StatusMappingConfig;
use PHPUnit\Framework\TestCase;

/**
 * TDD Tests for StatusMappingConfig.
 *
 * Sprint 29: Extract status mapping to configuration class.
 *
 * @covers \OxidEsales\Payments\Stripe\Config\StatusMappingConfig
 */
final class StatusMappingConfigTest extends TestCase
{
    /**
     * @test
     */
    public function pendingStatusIsPending(): void
    {
        $this->assertEquals('PENDING', StatusMappingConfig::STRIPE_PENDING);
    }

    /**
     * @test
     */
    public function processingStatusIsOk(): void
    {
        $this->assertEquals('OK', StatusMappingConfig::STRIPE_PROCESSING);
    }

    /**
     * @test
     */
    public function cancelledStatusIsError(): void
    {
        $this->assertEquals('ERROR', StatusMappingConfig::STRIPE_CANCELLED);
    }

    /**
     * @test
     */
    public function getAllReturnsAllMappings(): void
    {
        $mappings = StatusMappingConfig::getAll();

        $this->assertIsArray($mappings);
        $this->assertCount(3, $mappings);
        $this->assertArrayHasKey('pending', $mappings);
        $this->assertArrayHasKey('processing', $mappings);
        $this->assertArrayHasKey('cancelled', $mappings);
    }

    /**
     * @test
     */
    public function getAllMappingsMatchConstants(): void
    {
        $mappings = StatusMappingConfig::getAll();

        $this->assertEquals(StatusMappingConfig::STRIPE_PENDING, $mappings['pending']);
        $this->assertEquals(StatusMappingConfig::STRIPE_PROCESSING, $mappings['processing']);
        $this->assertEquals(StatusMappingConfig::STRIPE_CANCELLED, $mappings['cancelled']);
    }

    /**
     * @test
     */
    public function getOxidStatusReturnsMappedValueForPending(): void
    {
        $result = StatusMappingConfig::getOxidStatus('pending');

        $this->assertEquals('PENDING', $result);
    }

    /**
     * @test
     */
    public function getOxidStatusReturnsMappedValueForProcessing(): void
    {
        $result = StatusMappingConfig::getOxidStatus('processing');

        $this->assertEquals('OK', $result);
    }

    /**
     * @test
     */
    public function getOxidStatusReturnsMappedValueForCancelled(): void
    {
        $result = StatusMappingConfig::getOxidStatus('cancelled');

        $this->assertEquals('ERROR', $result);
    }

    /**
     * @test
     */
    public function getOxidStatusReturnsNullForUnknownState(): void
    {
        $result = StatusMappingConfig::getOxidStatus('unknown_state');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function getOxidStatusReturnsNullForEmptyString(): void
    {
        $result = StatusMappingConfig::getOxidStatus('');

        $this->assertNull($result);
    }

    /**
     * @test
     * @dataProvider validOxidStatusesProvider
     */
    public function constantsAreValidOxidStatuses(string $constant): void
    {
        $validOxidStatuses = ['NOT_FINISHED', 'OK', 'ERROR', 'PENDING', 'PROBLEMS'];

        $this->assertContains($constant, $validOxidStatuses);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validOxidStatusesProvider(): array
    {
        return [
            'STRIPE_PENDING' => [StatusMappingConfig::STRIPE_PENDING],
            'STRIPE_PROCESSING' => [StatusMappingConfig::STRIPE_PROCESSING],
            'STRIPE_CANCELLED' => [StatusMappingConfig::STRIPE_CANCELLED],
        ];
    }
}
