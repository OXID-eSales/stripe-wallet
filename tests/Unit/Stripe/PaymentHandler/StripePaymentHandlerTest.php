<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\PaymentHandler;

use OxidEsales\PaymentBase\Adapter\PaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler::class)]
class StripePaymentHandlerTest extends TestCase
{
    private ContractServiceInterface&MockObject $contractService;
    private CheckoutSessionServiceInterface&MockObject $checkoutSessionService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ShopAdapterInterface&MockObject $shopAdapter;
    private ShopOrderServiceInterface&MockObject $shopOrderService;
    private ModuleConfigurationServiceInterface&MockObject $config;
    private TokenServiceInterface&MockObject $tokenService;

    protected function setUp(): void
    {
        $this->contractService = $this->createMock(ContractServiceInterface::class);
        $this->checkoutSessionService = $this->createMock(CheckoutSessionServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopOrderService = $this->createMock(ShopOrderServiceInterface::class);
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $this->tokenService = $this->createMock(TokenServiceInterface::class);

        $this->shopAdapter->method('getShopUrl')->willReturn('https://shop.example.com/');
        $this->shopAdapter->method('getShopId')->willReturn('1');
        $this->config->method('getCaptureMode')->willReturn('automatic');
        $this->config->method('getPublishableKey')->willReturn('pk_test_123');
        $this->config->method('isConfigured')->willReturn(true);
    }

    private function createHandler(bool $iframeEnabled = false, ?\OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface $adapterFactory = null): StripePaymentHandler
    {
        $iframeSettings = $this->createMock(IframeCheckoutSettingsInterface::class);
        $iframeSettings->method('isEnabled')->willReturn($iframeEnabled);

        return new StripePaymentHandler(
            $this->contractService,
            $this->checkoutSessionService,
            $this->contractRepository,
            $this->shopAdapter,
            $this->shopOrderService,
            $this->config,
            $this->tokenService,
            iframeSettings: $iframeSettings,
            adapterFactory: $adapterFactory
        );
    }

    // ── Interface compliance ──

    public function testImplementsPaymentHandlerInterface(): void
    {
        $this->assertInstanceOf(PaymentHandlerInterface::class, $this->createHandler());
    }

    // ── getId / getName ──

    public function testGetIdReturnsStripe(): void
    {
        $this->assertSame('stripe', $this->createHandler()->getId());
    }

    public function testGetNameReturnsNonEmptyString(): void
    {
        $name = $this->createHandler()->getName();

        $this->assertNotEmpty($name);
        $this->assertIsString($name);
    }

    // ── supports() ──

    public function testSupportsStripeWalletPaymentId(): void
    {
        $this->assertTrue(
            $this->createHandler()->supports(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID)
        );
    }

    public function testSupportsAnyStripePrefix(): void
    {
        $this->assertTrue(
            $this->createHandler()->supports('oe_payments_stripe_sepa')
        );
    }

    public function testDoesNotSupportStandardOxidPayment(): void
    {
        $this->assertFalse(
            $this->createHandler()->supports('oxidcashondel')
        );
    }

    public function testDoesNotSupportEmptyString(): void
    {
        $this->assertFalse(
            $this->createHandler()->supports('')
        );
    }

    // ── processPayment() ──

    public function testProcessPaymentReturnsErrorOnContractCreationFailure(): void
    {
        $this->contractService
            ->method('createContract')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $context = $this->createPaymentContext();

        $result = $this->createHandler()->processPayment($context);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('DB connection lost', $result->getErrorMessage() ?? '');
    }

    // ── confirmPayment() ──

    /**
     * Sprint 133 · Story 6 (F6) — DELIBERATE BEHAVIOUR CHANGE. This asserted
     * that confirmPayment() reports success for any id without contacting
     * Stripe, which was the defect: PaymentHandlerInterface documents the method
     * as "Confirm payment with provider / Result with confirmation status".
     * Confirmation now requires a provider round-trip; the contract id is still
     * echoed back, but only on a genuinely confirmed payment.
     */
    public function testConfirmPaymentEchoesContractIdOnlyWhenProviderConfirms(): void
    {
        $handler = $this->createHandler(false, $this->adapterReturningStatus(
            \OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper::STATUS_CAPTURED
        ));

        $result = $handler->confirmPayment('contract_abc');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_abc', $result->getContractId());
    }

    // ── getFrontendConfig() ──

    public function testGetFrontendConfigContainsPublishableKey(): void
    {
        $frontendConfig = $this->createHandler()->getFrontendConfig();

        $this->assertSame('pk_test_123', $frontendConfig['publishableKey']);
        $this->assertSame('stripe', $frontendConfig['type']);
        $this->assertTrue($frontendConfig['requiresRedirect']);
    }

    public function testGetFrontendConfigDefaultsToRedirectMode(): void
    {
        $frontendConfig = $this->createHandler()->getFrontendConfig();

        $this->assertSame('redirect', $frontendConfig['renderMode']);
        $this->assertTrue($frontendConfig['requiresRedirect']);
    }

    public function testGetFrontendConfigUsesIframeModeWhenFlagEnabled(): void
    {
        $frontendConfig = $this->createHandler(iframeEnabled: true)->getFrontendConfig();

        $this->assertSame('iframe', $frontendConfig['renderMode']);
        $this->assertFalse($frontendConfig['requiresRedirect']);
        $this->assertSame('pk_test_123', $frontendConfig['publishableKey']);
    }

    // ── Helpers ──

    private function createPaymentContext(): \OxidEsales\PaymentBase\Adapter\PaymentContextInterface
    {
        $context = $this->createMock(\OxidEsales\PaymentBase\Adapter\PaymentContextInterface::class);
        $context->method('getPaymentMethodId')->willReturn(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID);
        $context->method('getBasket')->willReturn(new \stdClass());
        $context->method('getUser')->willReturn(new class () {
            public function getId(): string
            {
                return 'user_123';
            }

            public function getFieldData(string $field): string
            {
                return match ($field) {
                    'oxusername' => 'test@example.com',
                    'oxfname' => 'John',
                    'oxlname' => 'Doe',
                    default => '',
                };
            }
        });

        return $context;
    }

    // =========================================================================
    // Sprint 133 · Story 6 (F6) — confirmPayment must consult the provider
    //
    // It used to return PaymentHandlerResult::success() unconditionally, with no
    // provider call, against an interface documented as "Confirm payment with
    // provider / Result with confirmation status". A caller written to the
    // interface could not tell that answer from a real confirmation.
    // =========================================================================

    private function adapterReturningStatus(string $status): \OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface
    {
        // PaymentDetailsResponse is readonly, so build a real one.
        $details = new \OxidEsales\PaymentBase\Adapter\Response\PaymentDetailsResponse(
            providerPaymentId: 'pi_x',
            status: $status,
            amount: 100.0,
            currency: 'EUR',
            amountCaptured: 100.0,
            amountRefunded: 0.0,
            isCaptured: true,
            isRefunded: false,
            isCancelled: false,
            createdAt: new \DateTimeImmutable(),
        );

        $adapter = $this->createMock(\OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface::class);
        $adapter->method('getPaymentDetails')->willReturn($details);

        $factory = $this->createMock(\OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($adapter);

        return $factory;
    }

    public function testConfirmPaymentWhenPaymentIntentCapturedReturnsSuccess(): void
    {
        $handler = $this->createHandler(false, $this->adapterReturningStatus(
            \OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper::STATUS_CAPTURED
        ));

        $result = $handler->confirmPayment('pi_captured');

        $this->assertTrue($result->isSuccess());
    }

    public function testConfirmPaymentWhenPaymentIntentAuthorizedReturnsSuccess(): void
    {
        $handler = $this->createHandler(false, $this->adapterReturningStatus(
            \OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper::STATUS_AUTHORIZED
        ));

        $result = $handler->confirmPayment('pi_authorized');

        $this->assertTrue($result->isSuccess());
    }

    public function testConfirmPaymentWhenPaymentIntentNotCompletedReturnsFailure(): void
    {
        $handler = $this->createHandler(false, $this->adapterReturningStatus('requires_action'));

        $result = $handler->confirmPayment('pi_pending');

        $this->assertFalse($result->isSuccess(), 'An unconfirmed payment must not report success.');
    }

    public function testConfirmPaymentWhenAdapterThrowsReturnsFailure(): void
    {
        $adapter = $this->createMock(\OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface::class);
        $adapter->method('getPaymentDetails')->willThrowException(new \RuntimeException('API down'));

        $factory = $this->createMock(\OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($adapter);

        $result = $this->createHandler(false, $factory)->confirmPayment('pi_boom');

        $this->assertFalse($result->isSuccess());
    }

    public function testConfirmPaymentNeverReportsSuccessWithoutQueryingTheProvider(): void
    {
        // No adapter wired at all: an explicit failure, never a free yes.
        $result = $this->createHandler()->confirmPayment('pi_whatever');

        $this->assertFalse($result->isSuccess());
    }
}
