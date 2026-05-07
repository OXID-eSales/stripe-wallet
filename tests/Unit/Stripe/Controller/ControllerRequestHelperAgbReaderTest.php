<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Eshop\Core\Config;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Request;
use OxidEsales\PaymentComponent\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 101: Tests for the AGB reader methods on ControllerRequestHelper.
 *
 * Covers the boundary between the HTTP request and the controller gate —
 * not the controller's interpretation. Uses Registry::set() to seed a
 * fake request, the same way other helper tests do today.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper
 */
final class ControllerRequestHelperAgbReaderTest extends TestCase
{
    private TokenServiceInterface&MockObject $tokenService;
    private ModuleConfigurationServiceInterface&MockObject $moduleConfig;
    private LanguageResolverInterface&MockObject $languageResolver;
    private ControllerRequestHelper $helper;

    protected function setUp(): void
    {
        $this->tokenService = $this->createMock(TokenServiceInterface::class);
        $this->moduleConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $this->languageResolver = $this->createMock(LanguageResolverInterface::class);
        $this->helper = new ControllerRequestHelper(
            $this->tokenService,
            $this->moduleConfig,
            $this->languageResolver
        );
    }

    // ==========================================
    // H1 — getAgbAcceptedFromRequest: empty params
    // ==========================================

    public function testGetAgbAcceptedReturnsFalseWhenParamAbsent(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getRequestEscapedParameter')->with('ord_agb')->willReturn(null);
        Registry::set(Request::class, $request);

        $this->assertFalse($this->helper->getAgbAcceptedFromRequest());
    }

    // ==========================================
    // H2 — getAgbAcceptedFromRequest: empty string
    // ==========================================

    public function testGetAgbAcceptedReturnsFalseForEmptyString(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getRequestEscapedParameter')->with('ord_agb')->willReturn('');
        Registry::set(Request::class, $request);

        $this->assertFalse($this->helper->getAgbAcceptedFromRequest());
    }

    // ==========================================
    // H3 — getAgbAcceptedFromRequest: "0"
    // ==========================================

    public function testGetAgbAcceptedReturnsFalseForZeroString(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getRequestEscapedParameter')->with('ord_agb')->willReturn('0');
        Registry::set(Request::class, $request);

        $this->assertFalse($this->helper->getAgbAcceptedFromRequest());
    }

    // ==========================================
    // H4 — getAgbAcceptedFromRequest: "1"
    // ==========================================

    public function testGetAgbAcceptedReturnsTrueForOneString(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getRequestEscapedParameter')->with('ord_agb')->willReturn('1');
        Registry::set(Request::class, $request);

        $this->assertTrue($this->helper->getAgbAcceptedFromRequest());
    }

    // ==========================================
    // H5 — getAgbAcceptedFromRequest: "true" (strict — only "1" accepted)
    // ==========================================

    public function testGetAgbAcceptedReturnsFalseForStringTrue(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getRequestEscapedParameter')->with('ord_agb')->willReturn('true');
        Registry::set(Request::class, $request);

        $this->assertFalse($this->helper->getAgbAcceptedFromRequest());
    }

    // ==========================================
    // isAgbConfirmationRequired — reads blConfirmAGB config param
    // ==========================================

    public function testIsAgbConfirmationRequiredReturnsTrueWhenEnabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getConfigParam')->with('blConfirmAGB')->willReturn(true);
        Registry::set(Config::class, $config);

        $this->assertTrue($this->helper->isAgbConfirmationRequired());
    }

    public function testIsAgbConfirmationRequiredReturnsFalseWhenDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getConfigParam')->with('blConfirmAGB')->willReturn(false);
        Registry::set(Config::class, $config);

        $this->assertFalse($this->helper->isAgbConfirmationRequired());
    }

    public function testIsAgbConfirmationRequiredCastsToBool(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getConfigParam')->with('blConfirmAGB')->willReturn('1');
        Registry::set(Config::class, $config);

        $this->assertTrue($this->helper->isAgbConfirmationRequired());
    }
}
