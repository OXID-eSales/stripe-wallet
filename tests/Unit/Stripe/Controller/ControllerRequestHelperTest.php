<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 71: Tests for ControllerRequestHelper.
 *
 * Tests the extracted helper class methods that don't require OXID Registry.
 * Registry-dependent methods (getBasketFromSession, getSessionId, etc.) are
 * tested indirectly through controller integration tests.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper
 */
final class ControllerRequestHelperTest extends TestCase
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
    // getActiveLanguageId
    // ==========================================

    public function testGetActiveLanguageIdDelegatesToResolver(): void
    {
        $this->languageResolver->method('getActiveLanguageId')->willReturn(2);

        $this->assertSame(2, $this->helper->getActiveLanguageId());
    }

    public function testGetActiveLanguageIdReturnsZeroForDefaultLanguage(): void
    {
        $this->languageResolver->method('getActiveLanguageId')->willReturn(0);

        $this->assertSame(0, $this->helper->getActiveLanguageId());
    }

    // ==========================================
    // getCaptureMode
    // ==========================================

    public function testGetCaptureModeDelegatesToModuleConfig(): void
    {
        $this->moduleConfig->method('getCaptureMode')->willReturn('manual');

        $this->assertSame('manual', $this->helper->getCaptureMode());
    }

    public function testGetCaptureModeReturnsAutomatic(): void
    {
        $this->moduleConfig->method('getCaptureMode')->willReturn('automatic');

        $this->assertSame('automatic', $this->helper->getCaptureMode());
    }

    // ==========================================
    // validateContractToken
    // ==========================================

    public function testValidateContractTokenReturnsFalseForNullContractId(): void
    {
        $this->assertFalse($this->helper->validateContractToken(null, 'some_token'));
    }

    public function testValidateContractTokenReturnsFalseForNullToken(): void
    {
        $this->assertFalse($this->helper->validateContractToken('contract_123', null));
    }

    public function testValidateContractTokenReturnsFalseForBothNull(): void
    {
        $this->assertFalse($this->helper->validateContractToken(null, null));
    }

    public function testValidateContractTokenDelegatesToTokenService(): void
    {
        $this->tokenService
            ->expects($this->once())
            ->method('validateToken')
            ->with('valid_token', 'contract_123')
            ->willReturn(true);

        $this->assertTrue($this->helper->validateContractToken('contract_123', 'valid_token'));
    }

    public function testValidateContractTokenReturnsFalseForInvalidToken(): void
    {
        $this->tokenService
            ->method('validateToken')
            ->with('bad_token', 'contract_123')
            ->willReturn(false);

        $this->assertFalse($this->helper->validateContractToken('contract_123', 'bad_token'));
    }

    // ==========================================
    // clearStripeSessionVariables (via stub)
    // ==========================================

    public function testClearStripeSessionVariablesCallsDeleteForAllKeys(): void
    {
        $deletedKeys = [];
        $helper = new class ($this->tokenService, $this->moduleConfig, $deletedKeys) extends ControllerRequestHelper {
            /** @var string[] */
            private array $deleted;

            /**
             * @param string[] $deletedKeys
             */
            public function __construct(
                TokenServiceInterface $tokenService,
                ModuleConfigurationServiceInterface $moduleConfig,
                array &$deletedKeys
            ) {
                parent::__construct($tokenService, $moduleConfig);
                $this->deleted = &$deletedKeys;
            }

            public function deleteSessionVariable(string $key): void
            {
                $this->deleted[] = $key;
            }
        };

        $helper->clearStripeSessionVariables();

        $this->assertContains('stripe_payment_intent_id', $deletedKeys);
        $this->assertContains('stripe_client_secret', $deletedKeys);
        $this->assertContains('stripe_checkout_session_id', $deletedKeys);
        $this->assertContains('stripe_contract_id', $deletedKeys);
        $this->assertCount(4, $deletedKeys);
    }
}
