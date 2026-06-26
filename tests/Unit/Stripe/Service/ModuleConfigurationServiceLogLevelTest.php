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
 *   - When sStripeLogLevel is unset/empty → seed from blStripeLogTransactionInfo:
 *       '1'/true → 'normal', '0'/false → 'off'.
 *   - Unknown/garbage value → safe default 'normal'.
 * - Per-channel helpers derived from getLogLevel() (single resolution path, DRY):
 *   - isRequestLoggingEnabled()      → level ∈ {errors, normal, debug}
 *   - isReconciliationLoggingEnabled() → level ∈ {normal, debug}
 *   - isEventLoggingEnabled()        → level == debug
 *   - isWebhookLoggingEnabled()      → blStripeLogWebhooks AND level ∈ {normal, debug}
 *   - isFrontendDebugEnabled()       → level == debug
 * - isLoggingEnabled() REMOVED (dead method reading non-existent key).
 * - isTransactionLoggingEnabled() kept for legacy-seed source; its direct read
 *   is now superseded by getLogLevel() legacy-seed logic.
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
    // getLogLevel() — legacy seed (sStripeLogLevel unset/empty)
    // =========================================================================

    /**
     * Back-compat: legacy bool '1' (logging on) seeds to 'normal'.
     * Once the merchant sets sStripeLogLevel explicitly, legacy bool is ignored.
     */
    public function testGetLogLevelSeedsNormalFromLegacyBoolOneWhenSelectUnset(): void
    {
        $service = $this->makeService([
            // sStripeLogLevel absent → seed from legacy
            'blStripeLogTransactionInfo' => '1',
        ]);

        $this->assertSame('normal', $service->getLogLevel());
    }

    /**
     * Back-compat: legacy bool '0' (logging off) seeds to 'off'.
     */
    public function testGetLogLevelSeedsOffFromLegacyBoolZeroWhenSelectUnset(): void
    {
        $service = $this->makeService([
            'blStripeLogTransactionInfo' => '0',
        ]);

        $this->assertSame('off', $service->getLogLevel());
    }

    /**
     * Back-compat: legacy bool integer 1 (truthy) → seeds to 'normal'.
     */
    public function testGetLogLevelSeedsNormalFromLegacyIntOneWhenSelectUnset(): void
    {
        $service = $this->makeService([
            'blStripeLogTransactionInfo' => 1,
        ]);

        $this->assertSame('normal', $service->getLogLevel());
    }

    /**
     * Back-compat: legacy bool integer 0 → seeds to 'off'.
     */
    public function testGetLogLevelSeedsOffFromLegacyIntZeroWhenSelectUnset(): void
    {
        $service = $this->makeService([
            'blStripeLogTransactionInfo' => 0,
        ]);

        $this->assertSame('off', $service->getLogLevel());
    }

    /**
     * When both sStripeLogLevel is unset AND blStripeLogTransactionInfo is empty
     * (module not yet configured), default to 'normal' (safe production default).
     */
    public function testGetLogLevelDefaultsToNormalWhenBothSettingsAbsent(): void
    {
        $service = $this->makeService([]);

        $this->assertSame('normal', $service->getLogLevel());
    }

    /**
     * Explicit sStripeLogLevel takes precedence — legacy bool is NOT consulted.
     */
    public function testGetLogLevelUsesExplicitSelectAndIgnoresLegacyBoolWhenSelectIsSet(): void
    {
        $service = $this->makeService([
            'sStripeLogLevel'            => 'debug',
            'blStripeLogTransactionInfo' => '0', // would seed 'off' if consulted
        ]);

        $this->assertSame('debug', $service->getLogLevel());
    }

    public function testGetLogLevelUsesOffSelectAndIgnoresLegacyBoolWhenSelectIsOff(): void
    {
        $service = $this->makeService([
            'sStripeLogLevel'            => 'off',
            'blStripeLogTransactionInfo' => '1', // would seed 'normal' if consulted
        ]);

        $this->assertSame('off', $service->getLogLevel());
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
    // Legacy-seed tests — coverage of the Phase 0 invariant preserved here
    // =========================================================================

    /**
     * isTransactionLoggingEnabled() still works after refactor
     * (used internally as seed source; public API kept for back-compat).
     */
    public function testIsTransactionLoggingEnabledReturnsTrueWhenValueIsOne(): void
    {
        $service = $this->makeService(['blStripeLogTransactionInfo' => '1']);

        $this->assertTrue($service->isTransactionLoggingEnabled());
    }

    public function testIsTransactionLoggingEnabledReturnsFalseWhenValueIsZero(): void
    {
        $service = $this->makeService(['blStripeLogTransactionInfo' => '0']);

        $this->assertFalse($service->isTransactionLoggingEnabled());
    }

    public function testIsTransactionLoggingEnabledReturnsFalseWhenValueAbsent(): void
    {
        $service = $this->makeService([]);

        $this->assertFalse($service->isTransactionLoggingEnabled());
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
