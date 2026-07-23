<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Component\Widget;

use OxidEsales\Payments\Stripe\Component\Widget\StripeCheckoutFooter;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\Payments\Stripe\Module;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeCheckoutFooter.
 *
 * Sprint 114.13 (§8): testable-subclass pattern overrides the OXID
 * framework seams (parent::render, addTplParam, getViewParameter,
 * getServiceFromContainer) so no bootstrap is required.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Component\Widget\StripeCheckoutFooter::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-114-13')]
final class StripeCheckoutFooterTest extends TestCase
{
    // --- render() ---

    public function testRenderReturnsTwigTemplatePath(): void
    {
        $footer = $this->buildFooter([], 'pk_test_abc');

        $template = $footer->render();

        self::assertStringContainsString(Module::MODULE_ID, $template);
        self::assertStringContainsString('stripe-footer', $template);
    }

    public function testRenderSetsCheckoutDataTplParam(): void
    {
        $footer = $this->buildFooter(
            ['paymentMethodId' => 'pm_test', 'totalPrice' => 99.9, 'currency' => 'USD', 'csrfToken' => 'tok'],
            'pk_test_abc'
        );

        $footer->render();

        $params = $footer->getCapturedTplParams();
        self::assertArrayHasKey('checkoutData', $params);
    }

    public function testRenderSetsStripeConfigTplParam(): void
    {
        $footer = $this->buildFooter([], 'pk_test_xyz');

        $footer->render();

        $params = $footer->getCapturedTplParams();
        self::assertArrayHasKey('stripeConfig', $params);
    }

    // --- getCheckoutData() ---

    public function testGetCheckoutDataReturnsExpectedShape(): void
    {
        $viewParams = [
            'paymentMethodId' => 'pm_stripe_123',
            'totalPrice'      => 49.99,
            'currency'        => 'GBP',
            'csrfToken'       => 'csrf_abc',
        ];
        $footer = $this->buildFooter($viewParams, 'pk_test');

        $data = $footer->exposedGetCheckoutData();

        self::assertSame('pm_stripe_123', $data['paymentMethodId']);
        self::assertSame(49.99, $data['totalPrice']);
        self::assertSame('GBP', $data['currency']);
        self::assertSame('csrf_abc', $data['csrfToken']);
    }

    public function testGetCheckoutDataDefaultsCurrencyToEurWhenMissing(): void
    {
        $footer = $this->buildFooter(['currency' => null], 'pk_test');

        $data = $footer->exposedGetCheckoutData();

        self::assertSame('EUR', $data['currency']);
    }

    public function testGetCheckoutDataCastsTotalPriceToFloat(): void
    {
        $footer = $this->buildFooter(['totalPrice' => '12.50'], 'pk_test');

        $data = $footer->exposedGetCheckoutData();

        self::assertSame(12.50, $data['totalPrice']);
    }

    // --- getCheckoutData() — Phase D new keys ---

    public function testWidgetExposesValidationUrlAndPluginId(): void
    {
        $footer = $this->buildFooterWithShopUrl(
            ['csrfToken' => 'tok'],
            'pk_test',
            'https://shop.example.com/'
        );

        $data = $footer->exposedGetCheckoutData();

        self::assertSame(Module::MODULE_ID, $data['pluginModuleId']);
        self::assertStringContainsString('cl=oepaymentvalidationapi', $data['validationUrl']);
        self::assertStringContainsString('fnc=validate', $data['validationUrl']);
        self::assertStringStartsWith('https://shop.example.com/', $data['validationUrl']);
    }

    public function testWidgetReusesExistingCsrfTokenKey(): void
    {
        $footer = $this->buildFooterWithShopUrl(
            ['csrfToken' => 'my_csrf_token'],
            'pk_test',
            'https://shop.example.com/'
        );

        $data = $footer->exposedGetCheckoutData();

        self::assertArrayHasKey('csrfToken', $data);
        self::assertSame('my_csrf_token', $data['csrfToken']);
        // Guard: no duplicate key under a different name was introduced.
        $csrfKeys = array_filter(array_keys($data), static fn(string $k) => str_contains($k, 'csrf') || str_contains($k, 'Csrf'));
        self::assertCount(1, $csrfKeys, 'Exactly one csrf key must exist in checkoutData.');
    }

    // --- getStripeConfig() ---

    public function testGetStripeConfigReturnsPublishableKey(): void
    {
        $footer = $this->buildFooter([], 'pk_test_key_123');

        $config = $footer->exposedGetStripeConfig();

        self::assertSame('pk_test_key_123', $config['publishableKey']);
    }

    public function testGetStripeConfigReturnsEmptyKeyWhenConfigServiceThrows(): void
    {
        $footer = $this->buildFooterWithThrowingConfig([]);

        $config = $footer->exposedGetStripeConfig();

        self::assertSame('', $config['publishableKey']);
    }

    // --- getStripeConfig(): renderMode (IFRAME-02) ---

    public function testGetStripeConfigRenderModeDefaultsToRedirect(): void
    {
        $config = $this->buildFooter([], 'pk_x')->exposedGetStripeConfig();

        self::assertSame('redirect', $config['renderMode']);
    }

    public function testGetStripeConfigRenderModeIsIframeWhenFlagEnabled(): void
    {
        $config = $this->buildFooterWithIframe([], 'pk_x', true)->exposedGetStripeConfig();

        self::assertSame('iframe', $config['renderMode']);
    }

    public function testGetStripeConfigRenderModeIsRedirectWhenFlagDisabled(): void
    {
        $config = $this->buildFooterWithIframe([], 'pk_x', false)->exposedGetStripeConfig();

        self::assertSame('redirect', $config['renderMode']);
    }

    /**
     * @param array<string, mixed> $viewParams
     */
    private function buildFooterWithIframe(array $viewParams, string $publishableKey, bool $enabled): TestableStripeCheckoutFooter
    {
        $configService = $this->createMock(ModuleConfigurationServiceInterface::class);
        $configService->method('getPublishableKey')->willReturn($publishableKey);

        $iframeSettings = new class ($enabled) implements IframeCheckoutSettingsInterface {
            public function __construct(private bool $enabled)
            {
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }
        };

        return new TestableStripeCheckoutFooter($viewParams, $configService, 'https://localhost.local/', $iframeSettings);
    }

    // --- helpers ---

    /**
     * Builds a testable subclass with injected view parameters and a
     * publishable key. Overrides all OXID framework seams.
     *
     * @param array<string, mixed> $viewParams
     */
    private function buildFooter(array $viewParams, string $publishableKey): TestableStripeCheckoutFooter
    {
        $configService = $this->createMock(ModuleConfigurationServiceInterface::class);
        $configService->method('getPublishableKey')->willReturn($publishableKey);

        return new TestableStripeCheckoutFooter($viewParams, $configService, 'https://localhost.local/');
    }

    /**
     * Builds a testable subclass with an explicit shop URL for Phase D assertions.
     *
     * @param array<string, mixed> $viewParams
     */
    private function buildFooterWithShopUrl(
        array $viewParams,
        string $publishableKey,
        string $shopUrl
    ): TestableStripeCheckoutFooter {
        $configService = $this->createMock(ModuleConfigurationServiceInterface::class);
        $configService->method('getPublishableKey')->willReturn($publishableKey);

        return new TestableStripeCheckoutFooter($viewParams, $configService, $shopUrl);
    }

    /**
     * Builds a testable subclass whose config service always throws.
     *
     * @param array<string, mixed> $viewParams
     */
    private function buildFooterWithThrowingConfig(array $viewParams): TestableStripeCheckoutFooter
    {
        $configService = $this->createMock(ModuleConfigurationServiceInterface::class);
        $configService->method('getPublishableKey')
            ->willThrowException(new \RuntimeException('Config not available'));

        return new TestableStripeCheckoutFooter($viewParams, $configService, 'https://localhost.local/');
    }
}

/**
 * Testable subclass that overrides every OXID framework seam used by
 * StripeCheckoutFooter, keeping the test entirely framework-free.
 */
final class TestableStripeCheckoutFooter extends StripeCheckoutFooter
{
    /** @var array<string, mixed> */
    private array $tplParams = [];

    /**
     * @param array<string, mixed> $viewParams
     */
    public function __construct(
        private readonly array $viewParams,
        private readonly ModuleConfigurationServiceInterface $configService,
        private readonly string $shopUrl = 'https://localhost.local/',
        private readonly ?IframeCheckoutSettingsInterface $iframeSettings = null,
    ) {
        // Skip OXID parent constructor
    }

    protected function getShopUrl(): string
    {
        return $this->shopUrl;
    }

    public function render(): string
    {
        // Skip parent::render() (boots OXID view engine)
        $this->addTplParam('checkoutData', $this->getCheckoutData());
        $this->addTplParam('stripeConfig', $this->getStripeConfig());

        return $this->_sThisTemplate;
    }

    public function addTplParam($name, $value): void
    {
        $this->tplParams[$name] = $value;
    }

    /** @return array<string, mixed> */
    public function getCapturedTplParams(): array
    {
        return $this->tplParams;
    }

    public function getViewParameter($name): mixed
    {
        return $this->viewParams[$name] ?? null;
    }

    protected function getServiceFromContainer(string $serviceName): object
    {
        if ($serviceName === IframeCheckoutSettingsInterface::class) {
            if ($this->iframeSettings === null) {
                throw new \RuntimeException('iframe settings unavailable');
            }

            return $this->iframeSettings;
        }

        return $this->configService;
    }

    // Expose protected methods for direct testing
    /** @return array<string, mixed> */
    public function exposedGetCheckoutData(): array
    {
        return $this->getCheckoutData();
    }

    /** @return array<string, string> */
    public function exposedGetStripeConfig(): array
    {
        return $this->getStripeConfig();
    }
}
