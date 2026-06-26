<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Environment;

use OxidEsales\Payments\Stripe\Environment\DevelopmentEnvironmentCheckerInterface;
use OxidEsales\Payments\Stripe\Environment\ModuleConfigurationDevelopmentChecker;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Environment\ModuleConfigurationDevelopmentChecker::class)]
#[\PHPUnit\Framework\Attributes\Group('security')]
#[\PHPUnit\Framework\Attributes\Group('owasp-a05')]
class ModuleConfigurationDevelopmentCheckerTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $checker = new ModuleConfigurationDevelopmentChecker($config);

        $this->assertInstanceOf(DevelopmentEnvironmentCheckerInterface::class, $checker);
    }

    public function testIsDevelopmentModeReturnsTrueInTestMode(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('isTestMode')->willReturn(true);

        $checker = new ModuleConfigurationDevelopmentChecker($config);

        $this->assertTrue($checker->isDevelopmentMode());
    }

    public function testIsDevelopmentModeReturnsFalseInLiveMode(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('isTestMode')->willReturn(false);

        $checker = new ModuleConfigurationDevelopmentChecker($config);

        $this->assertFalse($checker->isDevelopmentMode());
    }
}
