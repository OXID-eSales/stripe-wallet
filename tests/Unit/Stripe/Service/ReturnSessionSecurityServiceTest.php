<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\ReturnSessionSecurityService;
use OxidEsales\PaymentComponent\Contract\SecurityValidationResultInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\OxidEsales\Payments\Stripe\Service\ReturnSessionSecurityService::class)]
class ReturnSessionSecurityServiceTest extends TestCase
{
    private ReturnSessionSecurityService $service;

    protected function setUp(): void
    {
        $this->service = new ReturnSessionSecurityService();
    }

    // =========================================================================
    // IP Address Validation Tests
    // =========================================================================

    public function testValidateReturnGivesFullScoreWhenIpMatches(): void
    {
        // Arrange
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 300, // 5 minutes ago
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertEquals(100, $result->getScore());
        $this->assertTrue($result->isAllowed());
        $this->assertEmpty($result->getWarnings());
    }

    public function testValidateReturnReducesScoreWhenIpChanged(): void
    {
        // Arrange
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = ['ip' => '10.0.0.50'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertLessThan(100, $result->getScore());
        $this->assertTrue($result->hasWarning('ip_changed'));
    }

    public function testValidateReturnPartiallyRestoresScoreWhenSameCountry(): void
    {
        // Arrange: Both IPs from same country
        $contract = $this->createContractWithMetadata([
            'user_ip' => '85.214.132.117',
            'user_country' => 'DE',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '91.64.234.56',
            'country' => 'DE',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Score reduced but not as much as cross-country
        $this->assertGreaterThan(70, $result->getScore());
        $this->assertTrue($result->hasWarning('ip_changed_same_country'));
    }

    public function testValidateReturnHeavilyReducesScoreWhenCountryChanged(): void
    {
        // Arrange: IP changed from Germany to Russia
        $contract = $this->createContractWithMetadata([
            'user_ip' => '85.214.132.117',
            'user_country' => 'DE',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '95.165.12.34',
            'country' => 'RU',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Heavy penalty for country change (100 - 40 = 60)
        $this->assertLessThanOrEqual(60, $result->getScore());
        $this->assertTrue($result->hasWarning('country_changed'));
    }

    // =========================================================================
    // Timing Validation Tests
    // =========================================================================

    public function testValidateReturnAllowsQuickReturn(): void
    {
        // Arrange: Return within 5 minutes
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 300, // 5 minutes ago
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertEquals(100, $result->getScore());
    }

    public function testValidateReturnReducesScoreForSlowReturn(): void
    {
        // Arrange: Return after 30 minutes
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 1800, // 30 minutes ago
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertLessThan(100, $result->getScore());
        $this->assertTrue($result->hasWarning('slow_return'));
    }

    public function testValidateReturnBlocksVeryLateReturn(): void
    {
        // Arrange: Return after 2 hours
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 7200, // 2 hours ago
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Very late return is suspicious
        $this->assertLessThan(70, $result->getScore());
        $this->assertTrue($result->hasWarning('very_late_return'));
    }

    // =========================================================================
    // User Agent Validation Tests
    // =========================================================================

    public function testValidateReturnAllowsSameUserAgent(): void
    {
        // Arrange
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0';
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => $userAgent,
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '192.168.1.100',
            'user_agent' => $userAgent,
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertEquals(100, $result->getScore());
    }

    public function testValidateReturnAllowsSimilarUserAgent(): void
    {
        // Arrange: Same browser, different version (common after auto-update)
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/119.0',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Minor version change is OK
        $this->assertGreaterThan(90, $result->getScore());
    }

    public function testValidateReturnReducesScoreForDifferentBrowser(): void
    {
        // Arrange: Changed from Chrome to Firefox
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/121.0',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertLessThan(90, $result->getScore());
        $this->assertTrue($result->hasWarning('browser_changed'));
    }

    public function testValidateReturnReducesScoreForDifferentOS(): void
    {
        // Arrange: Changed from Windows to Linux
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertLessThan(80, $result->getScore());
        $this->assertTrue($result->hasWarning('os_changed'));
    }

    // =========================================================================
    // Multiple Factor Tests
    // =========================================================================

    public function testValidateReturnCumulatesMultipleRiskFactors(): void
    {
        // Arrange: IP changed + country changed + late return
        $contract = $this->createContractWithMetadata([
            'user_ip' => '85.214.132.117',
            'user_country' => 'DE',
            'user_agent' => 'Chrome/120.0',
            'created_timestamp' => time() - 3600, // 1 hour ago
        ]);
        $currentContext = [
            'ip' => '95.165.12.34',
            'country' => 'RU',
            'user_agent' => 'Firefox/121.0',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Multiple factors = very low score
        $this->assertLessThan(40, $result->getScore());
        $this->assertFalse($result->isAllowed());
        $this->assertGreaterThan(2, $result->getWarningCount());
    }

    // =========================================================================
    // Threshold Tests
    // =========================================================================

    public function testIsAllowedReturnsTrueAboveThreshold(): void
    {
        // Arrange: Score above default 50 threshold
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 1800, // 30 min - slight penalty
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertTrue($result->isAllowed());
    }

    public function testIsAllowedReturnsFalseBelowThreshold(): void
    {
        // Arrange: Many risk factors
        $contract = $this->createContractWithMetadata([
            'user_ip' => '85.214.132.117',
            'user_country' => 'DE',
            'created_timestamp' => time() - 7200,
        ]);
        $currentContext = [
            'ip' => '95.165.12.34',
            'country' => 'CN',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertFalse($result->isAllowed());
    }

    public function testCustomThresholdIsRespected(): void
    {
        // Arrange
        $service = new ReturnSessionSecurityService(threshold: 80);
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 1800, // Score ~85
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $service->validateReturn($contract, $currentContext);

        // Assert: Score ~85, threshold 80 = allowed
        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testHandlesMissingMetadata(): void
    {
        // Arrange: Contract with minimal metadata
        $contract = $this->createContractWithMetadata([
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Should not crash, just reduce score for missing data
        $this->assertInstanceOf(SecurityValidationResultInterface::class, $result);
        $this->assertTrue($result->hasWarning('missing_original_ip'));
    }

    public function testHandlesEmptyContext(): void
    {
        // Arrange
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Should not crash
        $this->assertInstanceOf(SecurityValidationResultInterface::class, $result);
    }

    // =========================================================================
    // LSP Compliance Test
    // =========================================================================

    public function testImplementsReturnSecurityValidatorInterface(): void
    {
        $this->assertInstanceOf(
            \OxidEsales\PaymentComponent\Service\ReturnSecurityValidatorInterface::class,
            $this->service
        );
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContractWithMetadata(array $metadata): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);

        $contract->method('getMetadata')
            ->willReturnCallback(fn(string $key) => $metadata[$key] ?? null);

        $contract->method('getAllMetadata')
            ->willReturn($metadata);

        return $contract;
    }
}
