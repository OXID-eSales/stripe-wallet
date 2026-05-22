<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Session;
use OxidEsales\Payments\Stripe\Controller\Admin\ModuleConfiguration;
use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrarInterface;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * "Create webhooks" / "Clear all webhooks" AJAX actions on the ModuleConfiguration
 * controller, plus the view-helper methods consumed by the module_config Twig
 * extension.
 *
 * Per-mode endpoint metadata (ID + signing secret) lives in oxconfig — invisible
 * to the module_config form. The legacy single-valued module settings
 * (`sStripeWebhookEndpoint`, `sStripeWebhookEndpointSecret`) are ALSO written on
 * success, so the admin sees the registered URL + secret in the form on reload.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Admin\ModuleConfiguration
 * @group sprint-111
 */
final class ModuleConfigurationWebhookActionTest extends TestCase
{
    private WebhookEndpointRegistrarInterface&MockObject $registrar;
    private ModuleConfigurationServiceInterface&MockObject $moduleConfig;
    private LoggerInterface $logger;
    private Session&MockObject $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrar    = $this->createMock(WebhookEndpointRegistrarInterface::class);
        $this->moduleConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $this->logger       = new NullLogger();
        $this->session      = $this->createMock(Session::class);

        $this->session->method('checkSessionChallenge')->willReturn(true);
        $this->session->method('getSessionChallengeToken')->willReturn('tok_test');
        Registry::set(Session::class, $this->session);
    }

    protected function tearDown(): void
    {
        Registry::set(Session::class, null);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // AJAX action: stripeCreateWebhookEndpoint()
    // -------------------------------------------------------------------------

    public function testReturnsSuccessJsonAndPersistsEndpointWhenRegistrarSucceeds(): void
    {
        $this->moduleConfig->method('getMode')->willReturn('test');
        $this->moduleConfig->method('getPlatformKey')->willReturn('sk_test_platform');
        $this->moduleConfig->method('getWebhookUrl')
            ->willReturn('https://shop.example.com/index.php?cl=StripeWebhookController');
        $this->moduleConfig->method('getModuleDescription')
            ->willReturn('OXID eShop Stripe wallet (test)');

        $this->registrar->expects($this->once())
            ->method('register')
            ->with(
                'sk_test_platform',
                'https://shop.example.com/index.php?cl=StripeWebhookController',
                null,
                true,
                'OXID eShop Stripe wallet (test)',
            )
            ->willReturn(new WebhookEndpointRegistrationResult('we_123', 'whsec_abc'));

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        ob_start();
        $controller->stripeCreateWebhookEndpoint();
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        $decoded = json_decode($output, true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('we_123', $decoded['endpointId']);
        $this->assertSame('whsec_abc', $decoded['endpointSecret']);
        $this->assertSame(
            'https://shop.example.com/index.php?cl=StripeWebhookController',
            $decoded['webhookUrl']
        );
        $this->assertSame('we_123', $stored['sStripeWebhookEndpointIdTest'] ?? null);
        $this->assertSame('whsec_abc', $stored['sStripeWebhookEndpointSecretTest'] ?? null);
        $this->assertSame(
            'https://shop.example.com/index.php?cl=StripeWebhookController',
            $controller->getSavedModuleSetting('sStripeWebhookEndpoint')
        );
        $this->assertSame(
            'whsec_abc',
            $controller->getSavedModuleSetting('sStripeWebhookEndpointSecret')
        );
    }

    public function testReturnsErrorJsonWhenPlatformKeyMissing(): void
    {
        $this->moduleConfig->method('getMode')->willReturn('test');
        $this->moduleConfig->method('getPlatformKey')->willReturn('');

        $this->registrar->expects($this->never())->method('register');

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        ob_start();
        $controller->stripeCreateWebhookEndpoint();
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        $decoded = json_decode($output, true);
        $this->assertFalse($decoded['success']);
        $this->assertSame('STRIPE_WEBHOOK_PLATFORM_KEY_MISSING', $decoded['message']);
    }

    public function testReturnsErrorJsonWhenRegistrarThrowsWebhookRegistrationException(): void
    {
        $this->moduleConfig->method('getMode')->willReturn('test');
        $this->moduleConfig->method('getPlatformKey')->willReturn('sk_test_platform');
        $this->moduleConfig->method('getWebhookUrl')
            ->willReturn('https://shop.example.com/index.php?cl=StripeWebhookController');
        $this->moduleConfig->method('getModuleDescription')->willReturn('desc');

        $this->registrar->method('register')
            ->willThrowException(
                WebhookRegistrationException::fromApiError('rate_limit', 'too many requests')
            );

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        ob_start();
        $controller->stripeCreateWebhookEndpoint();
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        $decoded = json_decode($output, true);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('too many requests', $decoded['message'] ?? '');
    }

    public function testReturns403WhenSessionChallengeInvalid(): void
    {
        $session = $this->createMock(Session::class);
        $session->method('checkSessionChallenge')->willReturn(false);
        Registry::set(Session::class, $session);

        $this->registrar->expects($this->never())->method('register');

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        ob_start();
        $controller->stripeCreateWebhookEndpoint();
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        $decoded = json_decode($output, true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(403, http_response_code());
    }

    public function testPassesExistingEndpointIdFromOxConfigToRegistrar(): void
    {
        $this->moduleConfig->method('getMode')->willReturn('test');
        $this->moduleConfig->method('getPlatformKey')->willReturn('sk_test_platform');
        $this->moduleConfig->method('getWebhookUrl')
            ->willReturn('https://shop.example.com/index.php?cl=StripeWebhookController');
        $this->moduleConfig->method('getModuleDescription')->willReturn('desc');

        $stored = ['sStripeWebhookEndpointIdTest' => 'we_existing_123'];

        $this->registrar->expects($this->once())
            ->method('register')
            ->with(
                'sk_test_platform',
                $this->anything(),
                'we_existing_123',
                true,
                'desc',
            )
            ->willReturn(new WebhookEndpointRegistrationResult('we_existing_123', null));

        $controller = $this->createControllerWithOxConfigStore($stored);

        ob_start();
        $controller->stripeCreateWebhookEndpoint();
        ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // View helpers
    // -------------------------------------------------------------------------

    public function testStripeIsWebhookConfiguredReturnsTrueWhenPerModeEndpointIdExists(): void
    {
        $this->moduleConfig->method('getMode')->willReturn('test');
        $this->moduleConfig->method('getWebhookEndpoint')->willReturn('');

        $stored = ['sStripeWebhookEndpointIdTest' => 'we_test_123'];
        $controller = $this->createControllerWithOxConfigStore($stored);

        $this->assertTrue($controller->stripeIsWebhookConfigured());
    }

    public function testStripeIsWebhookConfiguredReturnsTrueWhenLegacyEndpointAndSecretAreBothSet(): void
    {
        $this->moduleConfig->method('getMode')->willReturn('test');
        $this->moduleConfig->method('getWebhookEndpoint')
            ->willReturn('https://shop.example.com/index.php?cl=StripeWebhookController');
        $this->moduleConfig->method('getWebhookSecret')->willReturn('whsec_legacy');

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        $this->assertTrue($controller->stripeIsWebhookConfigured());
    }

    public function testStripeIsWebhookConfiguredReturnsFalseWhenLegacyUrlIsSetButSecretIsMissing(): void
    {
        $this->moduleConfig->method('getMode')->willReturn('test');
        $this->moduleConfig->method('getWebhookEndpoint')
            ->willReturn('https://shop.example.com/index.php?cl=StripeWebhookController');
        $this->moduleConfig->method('getWebhookSecret')->willReturn('');

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        $this->assertFalse($controller->stripeIsWebhookConfigured());
    }

    public function testStripeIsWebhookConfiguredReturnsFalseWhenNeitherIsSet(): void
    {
        $this->moduleConfig->method('getMode')->willReturn('test');
        $this->moduleConfig->method('getWebhookEndpoint')->willReturn('');

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        $this->assertFalse($controller->stripeIsWebhookConfigured());
    }

    public function testStripeIsPlatformKeyConfiguredReturnsTrueWhenPlatformKeyNonEmpty(): void
    {
        $this->moduleConfig->method('getPlatformKey')->willReturn('sk_test_platform');

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        $this->assertTrue($controller->stripeIsPlatformKeyConfigured());
    }

    public function testStripeIsPlatformKeyConfiguredReturnsFalseWhenPlatformKeyEmpty(): void
    {
        $this->moduleConfig->method('getPlatformKey')->willReturn('');

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        $this->assertFalse($controller->stripeIsPlatformKeyConfigured());
    }

    public function testStripeGetCreateWebhookUrlPointsToModuleConfigurationController(): void
    {
        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        $url = $controller->stripeGetCreateWebhookUrl();

        $this->assertStringContainsString('cl=module_config', $url);
        $this->assertStringContainsString('fnc=stripeCreateWebhookEndpoint', $url);
        $this->assertStringContainsString('stoken=', $url);
    }

    // -------------------------------------------------------------------------
    // AJAX action: stripeClearAllWebhookEndpoints()
    // -------------------------------------------------------------------------

    public function testClearAllReturnsSuccessJsonAndForgetsAllLocalMetadata(): void
    {
        $this->moduleConfig->method('getPlatformKey')->willReturn('sk_test_platform');
        $this->moduleConfig->method('getWebhookUrl')
            ->willReturn('https://shop.example.com/index.php?cl=StripeWebhookController');
        $this->registrar->expects($this->once())
            ->method('clearAll')
            ->with('sk_test_platform', 'https://shop.example.com/index.php?cl=StripeWebhookController')
            ->willReturn(2);

        $stored = [
            'sStripeWebhookEndpointIdTest'     => 'we_test_aaa',
            'sStripeWebhookEndpointSecretTest' => 'whsec_aaa',
            'sStripeWebhookEndpointIdLive'     => 'we_live_bbb',
            'sStripeWebhookEndpointSecretLive' => 'whsec_bbb',
        ];
        $controller = $this->createControllerWithOxConfigStore($stored);

        ob_start();
        $controller->stripeClearAllWebhookEndpoints();
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        $decoded = json_decode($output, true);
        $this->assertTrue($decoded['success']);
        $this->assertSame(2, $decoded['deleted']);

        $this->assertSame('', $stored['sStripeWebhookEndpointIdTest']);
        $this->assertSame('', $stored['sStripeWebhookEndpointSecretTest']);
        $this->assertSame('', $stored['sStripeWebhookEndpointIdLive']);
        $this->assertSame('', $stored['sStripeWebhookEndpointSecretLive']);
        $this->assertSame('', $controller->getSavedModuleSetting('sStripeWebhookEndpoint'));
        $this->assertSame('', $controller->getSavedModuleSetting('sStripeWebhookEndpointSecret'));
    }

    public function testClearAllReturnsErrorJsonWhenPlatformKeyMissing(): void
    {
        $this->moduleConfig->method('getPlatformKey')->willReturn('');
        $this->registrar->expects($this->never())->method('clearAll');

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        ob_start();
        $controller->stripeClearAllWebhookEndpoints();
        $output = ob_get_clean();

        $decoded = json_decode((string) $output, true);
        $this->assertFalse($decoded['success']);
        $this->assertArrayHasKey('message', $decoded);
    }

    public function testClearAllReturnsErrorJsonWhenRegistrarThrows(): void
    {
        $this->moduleConfig->method('getPlatformKey')->willReturn('sk_test_platform');
        $this->registrar->method('clearAll')
            ->willThrowException(
                WebhookRegistrationException::fromApiError('rate_limit', 'too many requests')
            );

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        ob_start();
        $controller->stripeClearAllWebhookEndpoints();
        $output = ob_get_clean();

        $decoded = json_decode((string) $output, true);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('too many requests', $decoded['message'] ?? '');
    }

    public function testClearAllReturns403WhenSessionChallengeInvalid(): void
    {
        $session = $this->createMock(Session::class);
        $session->method('checkSessionChallenge')->willReturn(false);
        Registry::set(Session::class, $session);

        $this->registrar->expects($this->never())->method('clearAll');

        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        ob_start();
        $controller->stripeClearAllWebhookEndpoints();
        $output = ob_get_clean();

        $decoded = json_decode((string) $output, true);
        $this->assertFalse($decoded['success']);
        $this->assertSame('STRIPE_WEBHOOK_SESSION_EXPIRED', $decoded['message']);
        $this->assertSame(403, http_response_code());
    }

    public function testStripeGetClearWebhooksUrlPointsToClearAction(): void
    {
        $stored = [];
        $controller = $this->createControllerWithOxConfigStore($stored);

        $url = $controller->stripeGetClearWebhooksUrl();

        $this->assertStringContainsString('cl=module_config', $url);
        $this->assertStringContainsString('fnc=stripeClearAllWebhookEndpoints', $url);
        $this->assertStringContainsString('stoken=', $url);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a controller whose oxconfig seam uses an in-memory array and
     * whose OXID framework bootstrap is bypassed via initializeWebhookCollaborators().
     *
     * @param array<string, string> $store Reference to the in-memory store array.
     */
    private function createControllerWithOxConfigStore(array &$store): ModuleConfiguration
    {
        return new class (
            $this->registrar,
            $this->moduleConfig,
            $this->logger,
            $store,
        ) extends ModuleConfiguration {
            /** @var array<string, string> */
            private array $oxConfigStore;

            /** @var array<string, string> */
            private array $moduleSettingsStore = [];

            /**
             * @param array<string, string> $oxConfigStore
             */
            public function __construct(
                WebhookEndpointRegistrarInterface $registrar,
                ModuleConfigurationServiceInterface $moduleConfig,
                LoggerInterface $logger,
                array &$oxConfigStore
            ) {
                // Skip ModuleConfiguration_parent::__construct to avoid OXID admin bootstrap.
                $this->initializeWebhookCollaborators($registrar, $moduleConfig, $logger);
                $this->oxConfigStore = &$oxConfigStore;
            }

            public function getSavedModuleSetting(string $key): ?string
            {
                return $this->moduleSettingsStore[$key] ?? null;
            }

            protected function saveModuleSetting(string $key, string $value): void
            {
                $this->moduleSettingsStore[$key] = $value;
            }

            protected function readOxConfigVar(string $key): ?string
            {
                $value = $this->oxConfigStore[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
                return null;
            }

            protected function saveOxConfigVar(string $key, string $value): void
            {
                $this->oxConfigStore[$key] = $value;
            }

            protected function getSessionChallengeToken(): string
            {
                return 'tok_test';
            }

            protected function terminate(): void
            {
                // Tests must not exit(); the action's JSON output is captured via ob_*.
            }

            protected function translate(string $ident): string
            {
                // Stable echo for assertions; bypasses Registry::getLang() in tests.
                return $ident;
            }
        };
    }
}
