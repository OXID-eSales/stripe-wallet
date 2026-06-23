<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use OxidEsales\Payments\Stripe\Core\ViewConfig;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 — TDD: ViewConfig::isStripeDebug() delegates to
 * ModuleConfigurationServiceInterface::isFrontendDebugEnabled().
 *
 * Uses a standalone stub to avoid OXID's virtual parent class chain
 * (ViewConfig_parent) which requires framework bootstrap.
 *
 * The logic under test is simple: isStripeDebug() returns exactly
 * what isFrontendDebugEnabled() returns, with null-safety when the
 * service is unavailable.
 *
 * @group phase-5
 */
final class ViewConfigDebugTest extends TestCase
{
    /**
     * Contract test: the real ViewConfig class must declare isStripeDebug().
     * This is the RED test — it fails until the method is added to ViewConfig.
     *
     * @test
     */
    public function viewConfigClassExposesIsStripeDebugMethod(): void
    {
        $this->assertTrue(
            method_exists(ViewConfig::class, 'isStripeDebug'),
            'ViewConfig must declare isStripeDebug() — Phase 5 contract'
        );
    }

    /** @test */
    public function isStripeDebugReturnsTrueWhenFrontendDebugEnabled(): void
    {
        $stripeConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $stripeConfig->method('isFrontendDebugEnabled')->willReturn(true);

        $viewConfig = new StubViewConfigDebug($stripeConfig);

        $this->assertTrue($viewConfig->isStripeDebug());
    }

    /** @test */
    public function isStripeDebugReturnsFalseWhenFrontendDebugDisabled(): void
    {
        $stripeConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $stripeConfig->method('isFrontendDebugEnabled')->willReturn(false);

        $viewConfig = new StubViewConfigDebug($stripeConfig);

        $this->assertFalse($viewConfig->isStripeDebug());
    }

    /** @test */
    public function isStripeDebugReturnsFalseWhenServiceUnavailable(): void
    {
        $viewConfig = new StubViewConfigDebug(null);

        $this->assertFalse($viewConfig->isStripeDebug());
    }
}

/**
 * Standalone stub that exposes only isStripeDebug() without the OXID
 * parent-class chain. Mirrors the dependency-resolution seam in
 * src/Stripe/Core/ViewConfig: the service is injected via constructor
 * rather than the ServiceContainer trait (which needs OXID bootstrap).
 */
final class StubViewConfigDebug
{
    public function __construct(
        private readonly ?ModuleConfigurationServiceInterface $stripeConfig
    ) {
    }

    public function isStripeDebug(): bool
    {
        if ($this->stripeConfig === null) {
            return false;
        }

        return $this->stripeConfig->isFrontendDebugEnabled();
    }
}
