<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Eshop\Core\Language;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\OxidLanguageResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\Payments\Stripe\Service\OxidLanguageResolver
 */
final class OxidLanguageResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clear the Language mock so integration tests starting after this suite
        // get the real Registry-resolved Language, not our stub.
        Registry::set(Language::class, null);
        parent::tearDown();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(LanguageResolverInterface::class, new OxidLanguageResolver());
    }

    public function testReturnsBaseLanguageIdAsInt(): void
    {
        $language = $this->createMock(Language::class);
        $language->method('getBaseLanguage')->willReturn(1);
        Registry::set(Language::class, $language);

        $this->assertSame(1, (new OxidLanguageResolver())->getActiveLanguageId());
    }

    public function testReturnsZeroForShopDefaultLanguage(): void
    {
        $language = $this->createMock(Language::class);
        $language->method('getBaseLanguage')->willReturn(0);
        Registry::set(Language::class, $language);

        $this->assertSame(0, (new OxidLanguageResolver())->getActiveLanguageId());
    }

    public function testCastsStringToInt(): void
    {
        $language = $this->createMock(Language::class);
        $language->method('getBaseLanguage')->willReturn('2');
        Registry::set(Language::class, $language);

        $this->assertSame(2, (new OxidLanguageResolver())->getActiveLanguageId());
    }
}
