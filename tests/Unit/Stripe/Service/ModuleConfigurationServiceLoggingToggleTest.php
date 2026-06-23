<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0 characterization: the orphaned logging toggle methods.
 *
 * isTransactionLoggingEnabled() reads blStripeLogTransactionInfo:
 *   - '1' (truthy string stored by OXID) → true
 *   - '0' (falsy string stored by OXID)  → false
 *   - ''  (missing / unset)              → false
 *
 * Phase 3 update: isLoggingEnabled() was reading blStripeEnableLogging (a key
 * not present in metadata.php). It has been removed. The two tests that pinned
 * its always-false behavior are removed here since the method no longer exists.
 * The legacy bool-cast mapping is now preserved in
 * ModuleConfigurationServiceLogLevelTest (Phase 3) as seed tests.
 *
 * isTransactionLoggingEnabled() is kept as a public legacy accessor; it is
 * now the seed source for getLogLevel() when sStripeLogLevel is unset.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\ModuleConfigurationService::isTransactionLoggingEnabled
 * @group logging
 * @group phase-0-characterization
 */
final class ModuleConfigurationServiceLoggingToggleTest extends TestCase
{
    // -------------------------------------------------------------------------
    // isTransactionLoggingEnabled() — blStripeLogTransactionInfo mapping
    // -------------------------------------------------------------------------

    /**
     * Characterization: '1' (OXID truthy string) → true.
     * This is the legacy "logging on" seed value Phase 3 will map to 'normal'.
     */
    public function testIsTransactionLoggingEnabledReturnsTrueWhenValueIsOne(): void
    {
        $service = $this->makeService(['blStripeLogTransactionInfo' => '1']);

        $this->assertTrue($service->isTransactionLoggingEnabled());
    }

    /**
     * Characterization: '0' (OXID falsy string) → false.
     * This is the legacy "logging off" seed value Phase 3 will map to 'off'.
     */
    public function testIsTransactionLoggingEnabledReturnsFalseWhenValueIsZero(): void
    {
        $service = $this->makeService(['blStripeLogTransactionInfo' => '0']);

        $this->assertFalse($service->isTransactionLoggingEnabled());
    }

    /**
     * Characterization: empty string (key missing / module not activated) → false.
     */
    public function testIsTransactionLoggingEnabledReturnsFalseWhenValueIsEmpty(): void
    {
        $service = $this->makeService([]);

        $this->assertFalse($service->isTransactionLoggingEnabled());
    }

    /**
     * Characterization: integer 1 → true (PHP's (bool) cast is consistent).
     */
    public function testIsTransactionLoggingEnabledReturnsTrueForIntegerOne(): void
    {
        $service = $this->makeService(['blStripeLogTransactionInfo' => 1]);

        $this->assertTrue($service->isTransactionLoggingEnabled());
    }

    /**
     * Characterization: integer 0 → false.
     */
    public function testIsTransactionLoggingEnabledReturnsFalseForIntegerZero(): void
    {
        $service = $this->makeService(['blStripeLogTransactionInfo' => 0]);

        $this->assertFalse($service->isTransactionLoggingEnabled());
    }

    // -------------------------------------------------------------------------
    // Builder — testable subclass pattern (matches ModuleConfigurationServiceIsConfiguredTest)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $settings
     */
    private function makeService(array $settings): ModuleConfigurationService
    {
        return new class ($settings) extends ModuleConfigurationService {
            /** @param array<string, mixed> $testSettings */
            public function __construct(private readonly array $testSettings)
            {
                // Skip parent constructor — avoids OXID framework dependencies.
            }

            public function get(string $name): mixed
            {
                return $this->testSettings[$name] ?? '';
            }

            protected function readOxConfigVar(string $key): string
            {
                return '';
            }
        };
    }
}
