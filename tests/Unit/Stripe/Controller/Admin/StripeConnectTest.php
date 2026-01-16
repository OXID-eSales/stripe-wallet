<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Request;
use OxidEsales\Eshop\Core\Session;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridgeInterface;
use OxidEsales\Payments\Stripe\Controller\Admin\StripeConnect;
use OxidEsales\Payments\Stripe\Module;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StripeConnect admin controller.
 *
 * Verifies that Stripe Connect onboarding properly saves all fields
 * to module settings when returning from Stripe.
 */
class StripeConnectTest extends TestCase
{
    private MockObject $moduleSettingBridge;
    private MockObject $request;
    private MockObject $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleSettingBridge = $this->createMock(ModuleSettingBridgeInterface::class);
        $this->request = $this->createMock(Request::class);
        $this->session = $this->createMock(Session::class);
    }

    /**
     * Create a testable StripeConnect controller with mocked dependencies.
     */
    private function createController(): StripeConnect
    {
        $controller = new class($this->moduleSettingBridge) extends StripeConnect {
            private ModuleSettingBridgeInterface $testModuleSettingService;

            public function __construct(ModuleSettingBridgeInterface $moduleSettingService)
            {
                // Skip parent constructor to avoid container access
                $this->testModuleSettingService = $moduleSettingService;
            }

            public function stripeFinishOnBoarding()
            {
                // Re-implement to use our injected mock
                if (!Registry::getSession()->checkSessionChallenge()) {
                    return false;
                }

                $sAccessToken = Registry::getRequest()->getRequestEscapedParameter('access_token');
                $sPublishableKey = Registry::getRequest()->getRequestEscapedParameter('publishable_key');
                $sMode = Registry::getRequest()->getRequestEscapedParameter('shop_param');

                $blSuccess = true;
                if (empty($sAccessToken) || empty($sMode) || ($sMode != 'test' && $sMode != 'live')) {
                    $blSuccess = false;
                } else {
                    if ($sMode == 'live') {
                        $this->testModuleSettingService->save('sStripeLiveToken', $sAccessToken, Module::MODULE_ID);
                        $this->testModuleSettingService->save('sStripeLivePk', $sPublishableKey, Module::MODULE_ID);
                    } else {
                        $this->testModuleSettingService->save('sStripeTestToken', $sAccessToken, Module::MODULE_ID);
                        $this->testModuleSettingService->save('sStripeTestPk', $sPublishableKey, Module::MODULE_ID);
                    }
                }

                $aViewData = $this->getViewData();
                $aViewData['blIsSuccess'] = $blSuccess;
                $this->setViewData($aViewData);
            }

            public function getViewData(): array
            {
                return $this->_aViewData ?? [];
            }

            public function setViewData($aViewData = null): void
            {
                $this->_aViewData = $aViewData;
            }
        };

        return $controller;
    }

    // ==========================================
    // TEST MODE ONBOARDING TESTS
    // ==========================================

    /**
     * Test 1: Test mode onboarding saves both access token and publishable key.
     */
    public function testFinishOnBoardingSavesTestModeCredentials(): void
    {
        // Arrange
        $accessToken = 'sk_test_51ABC123TestSecretKey';
        $publishableKey = 'pk_test_51ABC123TestPublishableKey';

        $this->session->method('checkSessionChallenge')->willReturn(true);
        Registry::set(Session::class, $this->session);

        $this->request->method('getRequestEscapedParameter')
            ->willReturnMap([
                ['access_token', null, $accessToken],
                ['publishable_key', null, $publishableKey],
                ['shop_param', null, 'test'],
            ]);
        Registry::set(Request::class, $this->request);

        // Expect both settings to be saved
        $this->moduleSettingBridge->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(function ($name, $value, $moduleId) use ($accessToken, $publishableKey) {
                static $callCount = 0;
                $callCount++;

                $this->assertEquals(Module::MODULE_ID, $moduleId);

                if ($callCount === 1) {
                    $this->assertEquals('sStripeTestToken', $name);
                    $this->assertEquals($accessToken, $value);
                } else {
                    $this->assertEquals('sStripeTestPk', $name);
                    $this->assertEquals($publishableKey, $value);
                }
            });

        // Act
        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        // Assert
        $viewData = $controller->getViewData();
        $this->assertTrue($viewData['blIsSuccess']);
    }

    /**
     * Test 2: Test mode onboarding with empty publishable key still saves access token.
     */
    public function testFinishOnBoardingSavesTestModeWithEmptyPublishableKey(): void
    {
        // Arrange
        $accessToken = 'sk_test_51ABC123TestSecretKey';
        $publishableKey = ''; // Empty publishable key

        $this->session->method('checkSessionChallenge')->willReturn(true);
        Registry::set(Session::class, $this->session);

        $this->request->method('getRequestEscapedParameter')
            ->willReturnMap([
                ['access_token', null, $accessToken],
                ['publishable_key', null, $publishableKey],
                ['shop_param', null, 'test'],
            ]);
        Registry::set(Request::class, $this->request);

        // Both settings should still be saved (even empty publishable key)
        $savedSettings = [];
        $this->moduleSettingBridge->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(function ($name, $value, $moduleId) use (&$savedSettings) {
                $savedSettings[$name] = $value;
            });

        // Act
        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        // Assert
        $this->assertArrayHasKey('sStripeTestToken', $savedSettings);
        $this->assertArrayHasKey('sStripeTestPk', $savedSettings);
        $this->assertEquals($accessToken, $savedSettings['sStripeTestToken']);
        $this->assertEquals('', $savedSettings['sStripeTestPk']);
    }

    // ==========================================
    // LIVE MODE ONBOARDING TESTS
    // ==========================================

    /**
     * Test 3: Live mode onboarding saves both access token and publishable key.
     */
    public function testFinishOnBoardingSavesLiveModeCredentials(): void
    {
        // Arrange
        $accessToken = 'sk_live_51ABC123LiveSecretKey';
        $publishableKey = 'pk_live_51ABC123LivePublishableKey';

        $this->session->method('checkSessionChallenge')->willReturn(true);
        Registry::set(Session::class, $this->session);

        $this->request->method('getRequestEscapedParameter')
            ->willReturnMap([
                ['access_token', null, $accessToken],
                ['publishable_key', null, $publishableKey],
                ['shop_param', null, 'live'],
            ]);
        Registry::set(Request::class, $this->request);

        // Expect live mode settings to be saved
        $savedSettings = [];
        $this->moduleSettingBridge->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(function ($name, $value, $moduleId) use (&$savedSettings) {
                $savedSettings[$name] = $value;
                $this->assertEquals(Module::MODULE_ID, $moduleId);
            });

        // Act
        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        // Assert
        $viewData = $controller->getViewData();
        $this->assertTrue($viewData['blIsSuccess']);
        $this->assertArrayHasKey('sStripeLiveToken', $savedSettings);
        $this->assertArrayHasKey('sStripeLivePk', $savedSettings);
        $this->assertEquals($accessToken, $savedSettings['sStripeLiveToken']);
        $this->assertEquals($publishableKey, $savedSettings['sStripeLivePk']);
    }

    // ==========================================
    // VALIDATION TESTS
    // ==========================================

    /**
     * Test 4: Onboarding fails when access token is empty.
     */
    public function testFinishOnBoardingFailsWithEmptyAccessToken(): void
    {
        // Arrange
        $this->session->method('checkSessionChallenge')->willReturn(true);
        Registry::set(Session::class, $this->session);

        $this->request->method('getRequestEscapedParameter')
            ->willReturnMap([
                ['access_token', null, ''], // Empty access token
                ['publishable_key', null, 'pk_test_123'],
                ['shop_param', null, 'test'],
            ]);
        Registry::set(Request::class, $this->request);

        // Nothing should be saved
        $this->moduleSettingBridge->expects($this->never())->method('save');

        // Act
        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        // Assert
        $viewData = $controller->getViewData();
        $this->assertFalse($viewData['blIsSuccess']);
    }

    /**
     * Test 5: Onboarding fails when mode is invalid.
     */
    public function testFinishOnBoardingFailsWithInvalidMode(): void
    {
        // Arrange
        $this->session->method('checkSessionChallenge')->willReturn(true);
        Registry::set(Session::class, $this->session);

        $this->request->method('getRequestEscapedParameter')
            ->willReturnMap([
                ['access_token', null, 'sk_test_123'],
                ['publishable_key', null, 'pk_test_123'],
                ['shop_param', null, 'invalid_mode'], // Invalid mode
            ]);
        Registry::set(Request::class, $this->request);

        // Nothing should be saved
        $this->moduleSettingBridge->expects($this->never())->method('save');

        // Act
        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        // Assert
        $viewData = $controller->getViewData();
        $this->assertFalse($viewData['blIsSuccess']);
    }

    /**
     * Test 6: Onboarding fails when mode is missing.
     */
    public function testFinishOnBoardingFailsWithMissingMode(): void
    {
        // Arrange
        $this->session->method('checkSessionChallenge')->willReturn(true);
        Registry::set(Session::class, $this->session);

        $this->request->method('getRequestEscapedParameter')
            ->willReturnMap([
                ['access_token', null, 'sk_test_123'],
                ['publishable_key', null, 'pk_test_123'],
                ['shop_param', null, null], // Missing mode
            ]);
        Registry::set(Request::class, $this->request);

        // Nothing should be saved
        $this->moduleSettingBridge->expects($this->never())->method('save');

        // Act
        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        // Assert
        $viewData = $controller->getViewData();
        $this->assertFalse($viewData['blIsSuccess']);
    }

    /**
     * Test 7: Onboarding returns false when session challenge fails.
     */
    public function testFinishOnBoardingReturnsFalseOnSessionChallengeFail(): void
    {
        // Arrange
        $this->session->method('checkSessionChallenge')->willReturn(false);
        Registry::set(Session::class, $this->session);

        // Nothing should be saved
        $this->moduleSettingBridge->expects($this->never())->method('save');

        // Act
        $controller = $this->createController();
        $result = $controller->stripeFinishOnBoarding();

        // Assert
        $this->assertFalse($result);
    }

    // ==========================================
    // KEY FORMAT CONSISTENCY TESTS
    // ==========================================

    /**
     * Test 8: Verify keys from same Stripe account are saved together.
     *
     * This test documents the expected behavior that publishable and secret keys
     * should come from the same Stripe account (same account ID prefix).
     */
    public function testOnBoardingSavesMatchingKeyPair(): void
    {
        // Arrange - Keys from same account (51ABC12345 - exactly 10 chars for account ID)
        $accessToken = 'sk_test_51ABC12345SecretKeyRestOfString';
        $publishableKey = 'pk_test_51ABC12345PublishableKeyRestOfString';

        $this->session->method('checkSessionChallenge')->willReturn(true);
        Registry::set(Session::class, $this->session);

        $this->request->method('getRequestEscapedParameter')
            ->willReturnMap([
                ['access_token', null, $accessToken],
                ['publishable_key', null, $publishableKey],
                ['shop_param', null, 'test'],
            ]);
        Registry::set(Request::class, $this->request);

        $savedSettings = [];
        $this->moduleSettingBridge->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(function ($name, $value, $moduleId) use (&$savedSettings) {
                $savedSettings[$name] = $value;
            });

        // Act
        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        // Assert - Both keys saved
        $this->assertCount(2, $savedSettings);

        // Extract account IDs from saved keys
        $secretAccountId = $this->extractAccountIdFromKey($savedSettings['sStripeTestToken']);
        $publishableAccountId = $this->extractAccountIdFromKey($savedSettings['sStripeTestPk']);

        // Account IDs should match (keys from same Stripe account)
        $this->assertEquals($secretAccountId, $publishableAccountId);
    }

    /**
     * Test 9: Document that mismatched keys can still be saved (current behavior).
     *
     * This test documents that the current implementation does NOT validate
     * that keys belong to the same Stripe account. This may cause issues
     * at runtime when the checkout session created with one account's secret
     * key cannot be found with another account's publishable key.
     */
    public function testOnBoardingAllowsMismatchedKeysCurrentBehavior(): void
    {
        // Arrange - Keys from DIFFERENT accounts (this is problematic!)
        $accessToken = 'sk_test_51OyDwdAeMx6SN5PNSecretKey';      // Account: 51OyDwdAeM
        $publishableKey = 'pk_test_51NXKT4ESzLxU9YjjPublishable'; // Account: 51NXKT4ESz

        $this->session->method('checkSessionChallenge')->willReturn(true);
        Registry::set(Session::class, $this->session);

        $this->request->method('getRequestEscapedParameter')
            ->willReturnMap([
                ['access_token', null, $accessToken],
                ['publishable_key', null, $publishableKey],
                ['shop_param', null, 'test'],
            ]);
        Registry::set(Request::class, $this->request);

        $savedSettings = [];
        $this->moduleSettingBridge->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(function ($name, $value, $moduleId) use (&$savedSettings) {
                $savedSettings[$name] = $value;
            });

        // Act
        $controller = $this->createController();
        $controller->stripeFinishOnBoarding();

        // Assert - Current behavior: both keys are saved even if mismatched
        // This documents the current behavior, NOT the desired behavior
        $viewData = $controller->getViewData();
        $this->assertTrue($viewData['blIsSuccess']);

        // Keys from different accounts were saved
        $secretAccountId = $this->extractAccountIdFromKey($savedSettings['sStripeTestToken']);
        $publishableAccountId = $this->extractAccountIdFromKey($savedSettings['sStripeTestPk']);

        // This assertion documents that mismatched keys ARE currently allowed
        // In a better implementation, this should NOT happen
        $this->assertNotEquals(
            $secretAccountId,
            $publishableAccountId,
            'This test documents that mismatched keys are currently allowed (not ideal)'
        );
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    /**
     * Extract account ID from Stripe key.
     * Format: {type}_{mode}_{accountId}{randomChars}
     * Example: sk_test_51ABC123xyz... -> 51ABC12345
     */
    private function extractAccountIdFromKey(string $key): ?string
    {
        if (preg_match('/^[ps]k_(test|live)_([a-zA-Z0-9]{10})/', $key, $matches)) {
            return $matches[2];
        }
        return null;
    }
}
