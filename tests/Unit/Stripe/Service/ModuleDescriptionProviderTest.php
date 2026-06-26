<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\Payments\Stripe\Service\ModuleDescriptionProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Sprint 114.11b (S2): ModuleDescriptionProvider — extracted from ModuleConfigurationService.
 *
 * Owns module description extraction from ModuleConfiguration (metadata.php).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\ModuleDescriptionProvider::class)]
final class ModuleDescriptionProviderTest extends TestCase
{
    private ContextInterface&MockObject $context;
    private ModuleConfigurationDaoInterface&MockObject $dao;

    protected function setUp(): void
    {
        $this->context = $this->createMock(ContextInterface::class);
        $this->context->method('getCurrentShopId')->willReturn(1);
        $this->dao = $this->createMock(ModuleConfigurationDaoInterface::class);
    }

    public function testGetModuleDescriptionReturnsEnglishDescription(): void
    {
        $moduleConfig = $this->buildModuleConfigWithDescriptions(['en' => 'Stripe Payment', 'de' => 'Stripe Zahlung']);
        $this->dao->method('get')->willReturn($moduleConfig);
        $provider = new ModuleDescriptionProvider($this->context, $this->dao);

        $description = $provider->getModuleDescription();

        $this->assertSame('Stripe Payment', $description);
    }

    public function testGetModuleDescriptionFallsBackToFirstAvailableLanguage(): void
    {
        $moduleConfig = $this->buildModuleConfigWithDescriptions(['de' => 'Stripe Zahlung']);
        $this->dao->method('get')->willReturn($moduleConfig);
        $provider = new ModuleDescriptionProvider($this->context, $this->dao);

        $description = $provider->getModuleDescription();

        $this->assertSame('Stripe Zahlung', $description);
    }

    public function testGetModuleDescriptionReturnsEmptyStringWhenDaoThrows(): void
    {
        $this->dao->method('get')->willThrowException(new \RuntimeException('Module not activated'));
        $provider = new ModuleDescriptionProvider($this->context, $this->dao);

        $description = $provider->getModuleDescription();

        $this->assertSame('', $description);
    }

    public function testGetModuleDescriptionReturnsEmptyStringWhenDescriptionsEmpty(): void
    {
        $moduleConfig = $this->buildModuleConfigWithDescriptions([]);
        $this->dao->method('get')->willReturn($moduleConfig);
        $provider = new ModuleDescriptionProvider($this->context, $this->dao);

        $description = $provider->getModuleDescription();

        $this->assertSame('', $description);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $descriptions
     */
    private function buildModuleConfigWithDescriptions(array $descriptions): ModuleConfiguration
    {
        $config = $this->createMock(ModuleConfiguration::class);
        $config->method('getDescription')->willReturn($descriptions);
        return $config;
    }
}
