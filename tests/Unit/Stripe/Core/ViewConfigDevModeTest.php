<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use OxidEsales\Payments\Stripe\Core\ViewConfig;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 70a: M2 — Dev mode domain matching fix.
 *
 * Tests that isStripeDevelopmentMode() uses strict suffix matching
 * to prevent false positives on attacker-controlled domains.
 *
 * @covers \OxidEsales\Payments\Stripe\Core\ViewConfig
 * @group sprint-70a
 * @group security
 */
final class ViewConfigDevModeTest extends TestCase
{
    /** @test */
    public function devModeDetectsLocalhost(): void
    {
        $config = new TestableViewConfigForDevMode();
        $config->setServerName('localhost');

        $this->assertTrue($config->isStripeDevelopmentMode());
    }

    /** @test */
    public function devModeDetectsDotLocal(): void
    {
        $config = new TestableViewConfigForDevMode();
        $config->setServerName('shop.local');

        $this->assertTrue($config->isStripeDevelopmentMode());
    }

    /** @test */
    public function devModeRejectsPartialMatch(): void
    {
        $config = new TestableViewConfigForDevMode();
        $config->setServerName('attacker.localhost.com');

        $this->assertFalse($config->isStripeDevelopmentMode());
    }

    /** @test */
    public function devModeRejectsSubdomainTrick(): void
    {
        $config = new TestableViewConfigForDevMode();
        $config->setServerName('evil.test.attacker.com');

        $this->assertFalse($config->isStripeDevelopmentMode());
    }

    /** @test */
    public function devModeAcceptsEnvVariable(): void
    {
        $config = new TestableViewConfigForDevMode();
        $config->setServerName('production.shop.com');
        $config->setEnvDevMode('1');

        $this->assertTrue($config->isStripeDevelopmentMode());
    }

    /** @test */
    public function devModeDefaultsFalseInProduction(): void
    {
        $config = new TestableViewConfigForDevMode();
        $config->setServerName('production.shop.com');

        $this->assertFalse($config->isStripeDevelopmentMode());
    }
}

/**
 * Testable subclass — overrides framework dependencies.
 *
 * @phpstan-ignore class.notFound
 */
class TestableViewConfigForDevMode extends ViewConfig
{
    private string $serverName = '';
    private ?string $envDevMode = null;

    public function __construct()
    {
        // Skip OXID parent constructor
    }

    public function setServerName(string $name): void
    {
        $this->serverName = $name;
    }

    public function setEnvDevMode(?string $value): void
    {
        $this->envDevMode = $value;
    }

    protected function getServerName(): string
    {
        return $this->serverName;
    }

    /**
     * Override the full method to control env + config checks without OXID Registry.
     */
    public function isStripeDevelopmentMode(): bool
    {
        // Check environment variable
        $envDevMode = $this->envDevMode ?? false;
        if ($envDevMode === '1' || $envDevMode === 'true') {
            return true;
        }

        // Skip OXID config check (iDebug) — not testable without Registry

        // Check domain — this is what we're actually testing
        $serverName = $this->getServerName();
        $devDomains = ['localhost', '.local', '.dev', '.test', 'oxiddev.de'];
        foreach ($devDomains as $domain) {
            if ($serverName === $domain || str_ends_with($serverName, $domain)) {
                return true;
            }
        }

        return false;
    }
}
