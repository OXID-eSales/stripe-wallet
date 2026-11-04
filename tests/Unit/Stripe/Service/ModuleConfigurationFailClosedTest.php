<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\PaymentBase\Settings\AbstractModuleConfigurationService;
use OxidEsales\PaymentBase\Settings\ModuleConfigurationUnreadableException;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationService;
use OxidEsales\Payments\Stripe\Service\ModuleDescriptionProvider;
use OxidEsales\Payments\Stripe\Service\StripeUrlBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * VR D1: a configuration that cannot be read must not pick a mode. Before, an unreadable DAO left the mode at
 * "test" and the test keys were used on a live shop without a word.
 */
#[CoversClass(ModuleConfigurationService::class)]
final class ModuleConfigurationFailClosedTest extends TestCase
{
    #[Test]
    public function itBuildsOnPaymentBasesSharedConfigurationBase(): void
    {
        $this->assertInstanceOf(AbstractModuleConfigurationService::class, $this->serviceWithUnreadableConfig());
    }

    #[Test]
    public function anUnreadableConfigurationRefusesToNameAMode(): void
    {
        $this->expectException(ModuleConfigurationUnreadableException::class);

        $this->serviceWithUnreadableConfig()->getMode();
    }

    #[Test]
    public function anUnreadableConfigurationNeverHandsOutAKey(): void
    {
        $service = $this->serviceWithUnreadableConfig();

        $this->expectException(ModuleConfigurationUnreadableException::class);
        $service->getToken();
    }

    #[Test]
    public function anUnreadableConfigurationFailsIsTestModeToo(): void
    {
        $this->expectException(ModuleConfigurationUnreadableException::class);

        $this->serviceWithUnreadableConfig()->isTestMode();
    }

    private function serviceWithUnreadableConfig(): ModuleConfigurationService
    {
        $dao = $this->createStub(ModuleConfigurationDaoInterface::class);
        $dao->method('get')->willThrowException(new \RuntimeException('no such module configuration'));

        return new ModuleConfigurationService(
            $this->createStub(ContextInterface::class),
            $dao,
            $this->createStub(StripeUrlBuilder::class),
            $this->createStub(ModuleDescriptionProvider::class),
            $this->createStub(LoggerInterface::class),
        );
    }
}
