<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessDecision;
use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessGuardInterface;
use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessRejection;
use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessRequest;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
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
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper::class)]
final class ControllerRequestHelperTest extends TestCase
{
    private ReturnAccessGuardInterface&MockObject $returnGuard;
    private TokenServiceInterface&MockObject $tokenService;
    private ModuleConfigurationServiceInterface&MockObject $moduleConfig;
    private LanguageResolverInterface&MockObject $languageResolver;
    private ControllerRequestHelper $helper;

    protected function setUp(): void
    {
        $this->returnGuard = $this->createMock(ReturnAccessGuardInterface::class);
        $this->tokenService = $this->createMock(TokenServiceInterface::class);
        $this->moduleConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $this->languageResolver = $this->createMock(LanguageResolverInterface::class);
        $this->helper = new ControllerRequestHelper(
            $this->returnGuard,
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
    // checkReturnAccess (VR-20 R5)
    // ==========================================

    public function testReturnAccessIsPaymentBasesGuardsDecisionAboutThisContractTokenAndSession(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->returnGuard->expects($this->once())->method('check')
            ->with($this->callback(function (ReturnAccessRequest $request) use ($contract): bool {
                $this->assertSame($contract, $request->contract);
                $this->assertSame('stripe', $request->provider);
                $this->assertSame('tok', $request->token);
                $this->assertSame('user-1', $request->sessionUserId);
                $this->assertSame('shop-session', $request->sessionId);
                $this->assertSame($this->tokenService, $request->legacyTokens, 'old URLs are judged by the legacy validator');

                return true;
            }))
            ->willReturn(ReturnAccessDecision::reject(ReturnAccessRejection::AccessDenied));

        $decision = $this->helperInSession('shop-session')->checkReturnAccess($contract, 'tok', 'user-1');

        $this->assertFalse($decision->granted);
        $this->assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    private function helperInSession(string $sessionId): ControllerRequestHelper
    {
        return new class ($this->returnGuard, $this->tokenService, $this->moduleConfig, $this->languageResolver, $sessionId) extends ControllerRequestHelper {
            public function __construct(
                ReturnAccessGuardInterface $returnGuard,
                TokenServiceInterface $legacyTokens,
                ModuleConfigurationServiceInterface $moduleConfig,
                LanguageResolverInterface $languageResolver,
                private readonly string $testSessionId
            ) {
                parent::__construct($returnGuard, $legacyTokens, $moduleConfig, $languageResolver);
            }

            public function getSessionId(): string
            {
                return $this->testSessionId;
            }
        };
    }

    // ==========================================
    // clearStripeSessionVariables (via stub)
    // ==========================================

    public function testClearStripeSessionVariablesCallsDeleteForAllKeys(): void
    {
        $deletedKeys = [];
        $helper = new class ($this->returnGuard, $this->tokenService, $this->moduleConfig, $this->languageResolver, $deletedKeys) extends ControllerRequestHelper {
            /** @var string[] */
            private array $deleted;

            /**
             * @param string[] $deletedKeys
             */
            public function __construct(
                ReturnAccessGuardInterface $returnGuard,
                TokenServiceInterface $tokenService,
                ModuleConfigurationServiceInterface $moduleConfig,
                LanguageResolverInterface $languageResolver,
                array &$deletedKeys
            ) {
                parent::__construct($returnGuard, $tokenService, $moduleConfig, $languageResolver);
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
        $this->assertContains(ControllerRequestHelper::SESSION_SKIP_ADDR_CHECK, $deletedKeys);
        $this->assertCount(5, $deletedKeys);
    }
}
