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
 * Phase 3 TDD tests for the log-level resolver and per-channel helpers.
 *
 * Design:
 * - getLogLevel(): returns the validated effective log level string.
 *   - When sStripeLogLevel is explicitly set to a known value → return it.
 *   - When sStripeLogLevel is unset/empty → the default 'normal'.
 *   - Unknown/garbage value → safe default 'normal'.
 * - Per-channel helpers derived from getLogLevel() (single resolution path, DRY):
 *   - isRequestLoggingEnabled()      → level ∈ {errors, normal, debug}
 *   - isReconciliationLoggingEnabled() → level ∈ {normal, debug}
 *   - isEventLoggingEnabled()        → level == debug
 *   - isWebhookLoggingEnabled()      → blStripeLogWebhooks AND level ∈ {normal, debug}
 *   - isFrontendDebugEnabled()       → level == debug
 * - isLoggingEnabled() REMOVED (dead method reading non-existent key).
 */
#[\PHPUnit\Framework\Attributes\CoversMethod(\OxidEsales\Payments\Stripe\Service\ModuleConfigurationService::class, 'getLogLevel')]
#[\PHPUnit\Framework\Attributes\CoversMethod(\OxidEsales\Payments\Stripe\Service\ModuleConfigurationService::class, 'isRequestLoggingEnabled')]
#[\PHPUnit\Framework\Attributes\CoversMethod(\OxidEsales\Payments\Stripe\Service\ModuleConfigurationService::class, 'isReconciliationLoggingEnabled')]
#[\PHPUnit\Framework\Attributes\CoversMethod(\OxidEsales\Payments\Stripe\Service\ModuleConfigurationService::class, 'isEventLoggingEnabled')]
#[\PHPUnit\Framework\Attributes\CoversMethod(\OxidEsales\Payments\Stripe\Service\ModuleConfigurationService::class, 'isWebhookLoggingEnabled')]
#[\PHPUnit\Framework\Attributes\CoversMethod(\OxidEsales\Payments\Stripe\Service\ModuleConfigurationService::class, 'isFrontendDebugEnabled')]
#[\PHPUnit\Framework\Attributes\Group('logging')]
#[\PHPUnit\Framework\Attributes\Group('phase-3')]
final class ModuleConfigurationServiceLogLevelTest extends TestCase
{
    // =========================================================================
    // getLogLevel() — explicit sStripeLogLevel values
    // =========================================================================

    public function testGetLogLevelReturnsOffWhenExplicitlySetToOff(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'off']);

        $this->assertSame('off', $service->getLogLevel());
    }

    public function testGetLogLevelReturnsErrorsWhenExplicitlySetToErrors(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'errors']);

        $this->assertSame('errors', $service->getLogLevel());
    }

    public function testGetLogLevelReturnsNormalWhenExplicitlySetToNormal(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'normal']);

        $this->assertSame('normal', $service->getLogLevel());
    }

    public function testGetLogLevelReturnsDebugWhenExplicitlySetToDebug(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'debug']);

        $this->assertSame('debug', $service->getLogLevel());
    }

    // =========================================================================
    // getLogLevel() — unknown/garbage value → safe default 'normal'
    // =========================================================================

    public function testGetLogLevelReturnsNormalForUnknownValue(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'garbage_value_xyz']);

        $this->assertSame('normal', $service->getLogLevel());
    }

    public function testGetLogLevelReturnsNormalForNumericString(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => '1']);

        $this->assertSame('normal', $service->getLogLevel());
    }

    // =========================================================================
    // getLogLevel() — sStripeLogLevel unset/empty
    // =========================================================================

    /**
     * An unset or empty sStripeLogLevel gives 'normal' (safe production default); the removed legacy
     * blStripeLogTransactionInfo toggle is not consulted any more.
     */
    public function testGetLogLevelDefaultsToNormalWhenUnsetOrEmpty(): void
    {
        $this->assertSame('normal', $this->makeService([])->getLogLevel());
        $this->assertSame('normal', $this->makeService(['sStripeLogLevel' => ''])->getLogLevel());
        $this->assertSame('normal', $this->makeService(['blStripeLogTransactionInfo' => '0'])->getLogLevel());
    }

    public function testGetLogLevelReturnsAnExplicitKnownLevel(): void
    {
        $this->assertSame('debug', $this->makeService(['sStripeLogLevel' => 'debug'])->getLogLevel());
        $this->assertSame('off', $this->makeService(['sStripeLogLevel' => 'off'])->getLogLevel());
    }

    // =========================================================================
    // isRequestLoggingEnabled() — level ∈ {errors, normal, debug}
    // =========================================================================

    public function testRequestLoggingDisabledAtLevelOff(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'off']);

        $this->assertFalse($service->isRequestLoggingEnabled());
    }

    public function testRequestLoggingEnabledAtLevelErrors(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'errors']);

        $this->assertTrue($service->isRequestLoggingEnabled());
    }

    public function testRequestLoggingEnabledAtLevelNormal(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'normal']);

        $this->assertTrue($service->isRequestLoggingEnabled());
    }

    public function testRequestLoggingEnabledAtLevelDebug(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'debug']);

        $this->assertTrue($service->isRequestLoggingEnabled());
    }

    // =========================================================================
    // isReconciliationLoggingEnabled() — level ∈ {normal, debug}
    // =========================================================================

    public function testReconciliationLoggingDisabledAtLevelOff(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'off']);

        $this->assertFalse($service->isReconciliationLoggingEnabled());
    }

    public function testReconciliationLoggingDisabledAtLevelErrors(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'errors']);

        $this->assertFalse($service->isReconciliationLoggingEnabled());
    }

    public function testReconciliationLoggingEnabledAtLevelNormal(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'normal']);

        $this->assertTrue($service->isReconciliationLoggingEnabled());
    }

    public function testReconciliationLoggingEnabledAtLevelDebug(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'debug']);

        $this->assertTrue($service->isReconciliationLoggingEnabled());
    }

    // =========================================================================
    // isEventLoggingEnabled() — level == debug only
    // =========================================================================

    public function testEventLoggingDisabledAtLevelOff(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'off']);

        $this->assertFalse($service->isEventLoggingEnabled());
    }

    public function testEventLoggingDisabledAtLevelErrors(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'errors']);

        $this->assertFalse($service->isEventLoggingEnabled());
    }

    public function testEventLoggingDisabledAtLevelNormal(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'normal']);

        $this->assertFalse($service->isEventLoggingEnabled());
    }

    public function testEventLoggingEnabledAtLevelDebug(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'debug']);

        $this->assertTrue($service->isEventLoggingEnabled());
    }

    // =========================================================================
    // isWebhookLoggingEnabled() — blStripeLogWebhooks AND level ∈ {normal, debug}
    // =========================================================================

    public function testWebhookLoggingDisabledWhenLevelOff(): void
    {
        $service = $this->makeService([
            'sStripeLogLevel'    => 'off',
            'blStripeLogWebhooks' => '1',
        ]);

        $this->assertFalse($service->isWebhookLoggingEnabled());
    }

    public function testWebhookLoggingDisabledWhenLevelErrors(): void
    {
        $service = $this->makeService([
            'sStripeLogLevel'    => 'errors',
            'blStripeLogWebhooks' => '1',
        ]);

        $this->assertFalse($service->isWebhookLoggingEnabled());
    }

    public function testWebhookLoggingEnabledWhenLevelNormalAndSwitchOn(): void
    {
        $service = $this->makeService([
            'sStripeLogLevel'    => 'normal',
            'blStripeLogWebhooks' => '1',
        ]);

        $this->assertTrue($service->isWebhookLoggingEnabled());
    }

    public function testWebhookLoggingEnabledWhenLevelDebugAndSwitchOn(): void
    {
        $service = $this->makeService([
            'sStripeLogLevel'    => 'debug',
            'blStripeLogWebhooks' => '1',
        ]);

        $this->assertTrue($service->isWebhookLoggingEnabled());
    }

    /**
     * blStripeLogWebhooks off forces webhook logging off regardless of level.
     */
    public function testWebhookLoggingDisabledWhenSwitchOffEvenAtNormalLevel(): void
    {
        $service = $this->makeService([
            'sStripeLogLevel'    => 'normal',
            'blStripeLogWebhooks' => '0',
        ]);

        $this->assertFalse($service->isWebhookLoggingEnabled());
    }

    public function testWebhookLoggingDisabledWhenSwitchOffAtDebugLevel(): void
    {
        $service = $this->makeService([
            'sStripeLogLevel'    => 'debug',
            'blStripeLogWebhooks' => '0',
        ]);

        $this->assertFalse($service->isWebhookLoggingEnabled());
    }

    /**
     * Default state (no settings): level seeds to 'normal', webhook switch absent → off.
     * blStripeLogWebhooks default is '1' but when unset get() returns '' → (bool)'' = false.
     * The metadata.php default applies to newly activated modules; an absent key = disabled.
     */
    public function testWebhookLoggingDisabledWhenSwitchAbsent(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'normal']);

        $this->assertFalse($service->isWebhookLoggingEnabled());
    }

    // =========================================================================
    // isFrontendDebugEnabled() — level == debug only
    // =========================================================================

    public function testFrontendDebugDisabledAtLevelOff(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'off']);

        $this->assertFalse($service->isFrontendDebugEnabled());
    }

    public function testFrontendDebugDisabledAtLevelNormal(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'normal']);

        $this->assertFalse($service->isFrontendDebugEnabled());
    }

    public function testFrontendDebugEnabledAtLevelDebug(): void
    {
        $service = $this->makeService(['sStripeLogLevel' => 'debug']);

        $this->assertTrue($service->isFrontendDebugEnabled());
    }

    // =========================================================================
    // Builder — same testable-subclass pattern as existing tests
    // =========================================================================

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
