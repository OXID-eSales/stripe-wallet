<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Request;
use OxidEsales\Eshop\Core\Session;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridgeInterface;
use OxidEsales\Payments\Stripe\Controller\Admin\StripeConnect;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests `StripeConnect::stripeFinishOnBoarding()` — the OAuth return landing action.
 *
 * The controller has a single responsibility now: persist the access_token and
 * publishable_key returned by Stripe Connect, set a success/failure flag for the
 * view. Webhook registration was moved to `ModuleConfiguration` in Sprint 111
 * (see ModuleConfigurationWebhookActionTest).
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Admin\StripeConnect
 */
final class StripeConnectTest extends TestCase
{
    private ModuleSettingBridgeInterface&MockObject $moduleSettings;
    private Request&MockObject $request;
    private Session&MockObject $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleSettings = $this->createMock(ModuleSettingBridgeInterface::class);
        $this->request        = $this->createMock(Request::class);
        $this->session        = $this->createMock(Session::class);
    }

    protected function tearDown(): void
    {
        Registry::set(Session::class, null);
        Registry::set(Request::class, null);
        parent::tearDown();
    }

    public function testFinishOnBoardingPersistsTestModeAccessTokenAndPublishableKey(): void
    {
        $this->givenValidOnboardingReturn('test', 'sk_test_abc', 'pk_test_xyz');

        $saved = [];
        $this->moduleSettings->method('save')
            ->willReturnCallback(function (string $name, mixed $value) use (&$saved): void {
                $saved[$name] = $value;
            });

        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        $this->assertSame('sk_test_abc', $saved['sStripeTestToken'] ?? null);
        $this->assertSame('pk_test_xyz', $saved['sStripeTestPk']    ?? null);
        $this->assertTrue($controller->getViewData()['blIsSuccess']);
    }

    public function testFinishOnBoardingPersistsLiveModeAccessTokenAndPublishableKey(): void
    {
        $this->givenValidOnboardingReturn('live', 'sk_live_abc', 'pk_live_xyz');

        $saved = [];
        $this->moduleSettings->method('save')
            ->willReturnCallback(function (string $name, mixed $value) use (&$saved): void {
                $saved[$name] = $value;
            });

        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        $this->assertSame('sk_live_abc', $saved['sStripeLiveToken'] ?? null);
        $this->assertSame('pk_live_xyz', $saved['sStripeLivePk']    ?? null);
        $this->assertTrue($controller->getViewData()['blIsSuccess']);
    }

    public function testFinishOnBoardingDoesNotSaveWhenModeIsInvalid(): void
    {
        $this->givenValidOnboardingReturn('bogus_mode', 'sk_test_abc', 'pk_test_xyz');

        $this->moduleSettings->expects($this->never())->method('save');

        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        $this->assertFalse($controller->getViewData()['blIsSuccess']);
    }

    public function testFinishOnBoardingDoesNotSaveWhenAccessTokenIsEmpty(): void
    {
        $this->givenValidOnboardingReturn('test', '', 'pk_test_xyz');

        $this->moduleSettings->expects($this->never())->method('save');

        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        $this->assertFalse($controller->getViewData()['blIsSuccess']);
    }

    public function testFinishOnBoardingReturnsFalseWhenSessionChallengeFails(): void
    {
        $this->session->method('checkSessionChallenge')->willReturn(false);
        Registry::set(Session::class, $this->session);

        $this->moduleSettings->expects($this->never())->method('save');

        $controller = $this->createController();
        $result = $controller->stripeFinishOnBoarding();

        $this->assertFalse($result);
    }

    private function givenValidOnboardingReturn(string $mode, string $accessToken, string $pk): void
    {
        $this->session->method('checkSessionChallenge')->willReturn(true);
        Registry::set(Session::class, $this->session);

        $this->request->method('getRequestEscapedParameter')
            ->willReturnMap([
                ['access_token', null, $accessToken],
                ['publishable_key', null, $pk],
                ['shop_param', null, $mode],
            ]);
        Registry::set(Request::class, $this->request);
    }

    private function createController(): StripeConnect
    {
        return new class ($this->moduleSettings) extends StripeConnect {
            /** @var array<string, mixed> */
            public array $exposedViewData = [];

            public function __construct(ModuleSettingBridgeInterface $moduleSettings)
            {
                // Bypass parent::__construct() to avoid OXID admin bootstrap.
                $this->initializeCollaborators($moduleSettings);
            }

            public function getViewConfig(): object // phpcs:ignore
            {
                return new class {
                    public function getSslSelfLink(): string
                    {
                        return 'https://shop.example.com/admin/';
                    }
                };
            }

            public function getViewData(): array
            {
                return $this->exposedViewData;
            }

            public function setViewData($aViewData = null): void
            {
                $this->exposedViewData = $aViewData ?? [];
            }
        };
    }
}
