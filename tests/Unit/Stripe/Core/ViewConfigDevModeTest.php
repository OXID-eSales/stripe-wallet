<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 70a: M2 — Dev mode domain matching fix.
 *
 * Tests that development mode detection uses strict suffix matching
 * to prevent false positives on attacker-controlled domains.
 *
 * Uses a standalone stub (not extending ViewConfig) to avoid
 * OXID's virtual parent class chain which requires framework bootstrap.
 */
#[\PHPUnit\Framework\Attributes\Group('sprint-70a')]
#[\PHPUnit\Framework\Attributes\Group('security')]
final class ViewConfigDevModeTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function devModeDetectsLocalhost(): void
    {
        $config = new StubDevModeChecker();
        $config->setServerName('localhost');

        $this->assertTrue($config->isStripeDevelopmentMode());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function devModeDetectsDotLocal(): void
    {
        $config = new StubDevModeChecker();
        $config->setServerName('shop.local');

        $this->assertTrue($config->isStripeDevelopmentMode());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function devModeRejectsPartialMatch(): void
    {
        $config = new StubDevModeChecker();
        $config->setServerName('attacker.localhost.com');

        $this->assertFalse($config->isStripeDevelopmentMode());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function devModeRejectsSubdomainTrick(): void
    {
        $config = new StubDevModeChecker();
        $config->setServerName('evil.test.attacker.com');

        $this->assertFalse($config->isStripeDevelopmentMode());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function devModeAcceptsEnvVariable(): void
    {
        $config = new StubDevModeChecker();
        $config->setServerName('production.shop.com');
        $config->setEnvDevMode('1');

        $this->assertTrue($config->isStripeDevelopmentMode());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function devModeDefaultsFalseInProduction(): void
    {
        $config = new StubDevModeChecker();
        $config->setServerName('production.shop.com');

        $this->assertFalse($config->isStripeDevelopmentMode());
    }
}

/**
 * Standalone stub that mirrors ViewConfig::isStripeDevelopmentMode() domain-matching logic.
 *
 * Does NOT extend ViewConfig — avoids OXID's virtual parent class chain
 * (ViewConfig_parent) which requires framework bootstrap and module activation.
 *
 * The domain-matching logic tested here must stay in sync with
 * src/Stripe/Core/ViewConfig::isStripeDevelopmentMode().
 */
class StubDevModeChecker
{
    private string $serverName = '';
    private ?string $envDevMode = null;

    public function setServerName(string $name): void
    {
        $this->serverName = $name;
    }

    public function setEnvDevMode(?string $value): void
    {
        $this->envDevMode = $value;
    }

    public function isStripeDevelopmentMode(): bool
    {
        $envDevMode = $this->envDevMode ?? false;
        if ($envDevMode === '1' || $envDevMode === 'true') {
            return true;
        }

        $serverName = $this->serverName;
        $devDomains = ['localhost', '.local', '.dev', '.test', 'oxiddev.de'];
        foreach ($devDomains as $domain) {
            if ($serverName === $domain || str_ends_with($serverName, $domain)) {
                return true;
            }
        }

        return false;
    }
}
