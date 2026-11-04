<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Session;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridgeInterface;
use OxidEsales\Payments\Stripe\Controller\Admin\StripeConnect;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\PaymentBase\Payment\AdminAccessDeniedException;
use OxidEsales\PaymentBase\Payment\AdminChannelInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests `StripeConnect::stripeFinishOnBoarding()` — the OAuth return landing action (VR-24 H4: POST only, CONFIG right,
key prefixes checked against the mode).
 *
 * The controller has a single responsibility now: persist the access_token and
 * publishable_key returned by Stripe Connect, set a success/failure flag for the
 * view. Webhook registration was moved to `ModuleConfiguration` in Sprint 111
 * (see ModuleConfigurationWebhookActionTest).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\Admin\StripeConnect::class)]
final class StripeConnectTest extends TestCase
{
    private ModuleSettingBridgeInterface&MockObject $moduleSettings;
    private AdminChannelInterface&MockObject $adminChannel;
    private string $requestMethod = 'POST';
    /** @var array<string, string> */
    private array $posted = [];
    private Session&MockObject $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleSettings = $this->createMock(ModuleSettingBridgeInterface::class);
        $this->adminChannel   = $this->createMock(AdminChannelInterface::class);
        $this->session        = $this->createMock(Session::class);
    }

    protected function tearDown(): void
    {
        Registry::set(Session::class, null);
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

    public function testRejectsGetWithAccessToken(): void
    {
        $this->givenValidOnboardingReturn('test', 'sk_test_abc', 'pk_test_xyz');
        $this->requestMethod = 'GET';

        $this->moduleSettings->expects($this->never())->method('save');

        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        $this->assertFalse($controller->getViewData()['blIsSuccess']);
    }

    public function testRequiresPaymentConfigRight(): void
    {
        $this->givenValidOnboardingReturn('test', 'sk_test_abc', 'pk_test_xyz');
        $this->adminChannel->expects($this->once())
            ->method('requireRight')
            ->with(PaymentRightsProvider::CONFIG)
            ->willThrowException(new AdminAccessDeniedException('right_missing', 'no right'));

        $this->moduleSettings->expects($this->never())->method('save');

        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        $this->assertFalse($controller->getViewData()['blIsSuccess']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function mismatchingKeys(): array
    {
        return [
            'live secret key in test mode' => ['test', 'sk_live_abc', 'pk_test_xyz'],
            'test secret key in live mode' => ['live', 'sk_test_abc', 'pk_live_xyz'],
            'live publishable key in test mode' => ['test', 'sk_test_abc', 'pk_live_xyz'],
            'not a key at all' => ['test', 'whsec_abc', 'pk_test_xyz'],
            'no publishable key' => ['test', 'sk_test_abc', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mismatchingKeys')]
    public function testValidatesKeyPrefixAgainstMode(string $mode, string $accessToken, string $publishableKey): void
    {
        $this->givenValidOnboardingReturn($mode, $accessToken, $publishableKey);

        $this->moduleSettings->expects($this->never())->method('save');

        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        $this->assertFalse($controller->getViewData()['blIsSuccess']);
    }

    public function testRestrictedKeyIsAccepted(): void
    {
        $this->givenValidOnboardingReturn('live', 'rk_live_abc', 'pk_live_xyz');

        $this->moduleSettings->expects($this->exactly(2))->method('save');

        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        $this->assertTrue($controller->getViewData()['blIsSuccess']);
    }

    private function givenValidOnboardingReturn(string $mode, string $accessToken, string $pk): void
    {
        $this->session->method('checkSessionChallenge')->willReturn(true);
        Registry::set(Session::class, $this->session);

        $this->posted = ['access_token' => $accessToken, 'publishable_key' => $pk, 'shop_param' => $mode];
    }

    private function createController(): StripeConnect
    {
        return new class ($this->moduleSettings, $this->adminChannel, $this->requestMethod, $this->posted) extends StripeConnect {
            /** @var array<string, mixed> */
            public array $exposedViewData = [];

            /** @param array<string, string> $posted */
            public function __construct(
                ModuleSettingBridgeInterface $moduleSettings,
                AdminChannelInterface $adminChannel,
                private readonly string $method,
                private readonly array $posted
            ) {
                // Bypass parent::__construct() to avoid OXID admin bootstrap.
                $this->initializeCollaborators($moduleSettings, $adminChannel);
            }

            protected function requestMethod(): string
            {
                return $this->method;
            }

            protected function postedParameter(string $name): string
            {
                return $this->posted[$name] ?? '';
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
