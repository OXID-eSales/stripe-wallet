<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\PaymentHandler;

use OxidEsales\PaymentBase\Adapter\ContractFirstPaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\Response\OrderResponse;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutSessionResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The handler with its two shop-session seams recorded instead of executed.
 */
final class SessionRecordingStripeHandler extends StripePaymentHandler
{
    /** @var list<string> */
    public array $sessionCalls = [];

    protected function sessionId(): string
    {
        $this->sessionCalls[] = 'sessionId';

        return 'sid-twig';
    }

    protected function prepareSessionForOrder(string $paymentMethodId): void
    {
        $this->sessionCalls[] = 'prepare:' . $paymentMethodId;
    }
}

/**
 * GRAPH-QL / PS1 — the handler payment-base's HeadlessCheckoutService drives.
 * A headless PaymentContext (metadata.headless) has no PHP session: the early
 * order is built from the persisted basket (basketId), the Checkout Session
 * sends the shopper to the client's URLs, and nothing is read from or written
 * to the session. The OPC path is byte-identical to before.
 */
final class StripePaymentHandlerHeadlessTest extends TestCase
{
    private ContractServiceInterface&MockObject $contractService;
    private CheckoutSessionServiceInterface&MockObject $checkoutSessionService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ShopOrderServiceInterface&MockObject $shopOrderService;
    private TokenServiceInterface&MockObject $tokenService;
    private PaymentContract $contract;

    protected function setUp(): void
    {
        $this->contractService = $this->createMock(ContractServiceInterface::class);
        $this->checkoutSessionService = $this->createMock(CheckoutSessionServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->shopOrderService = $this->createMock(ShopOrderServiceInterface::class);
        $this->tokenService = $this->createMock(TokenServiceInterface::class);
        $this->tokenService->method('generateToken')->willReturn('tok');

        $this->contract = new PaymentContract(1, 'user_123', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]), 'contract-1');
        $this->contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $this->contractService->method('createContract')->willReturn($this->contract);
        $this->shopOrderService->method('createOrder')->willReturn($this->orderResponse());
    }

    public function testDeclaresItselfContractFirst(): void
    {
        self::assertInstanceOf(ContractFirstPaymentHandlerInterface::class, $this->handler());
    }

    public function testHeadlessStartBuildsTheOrderFromTheBasketIdAndTheSessionFromTheClientUrls(): void
    {
        $this->shopOrderService->expects($this->once())->method('createOrder')
            ->with($this->callback(function (CreateOrderRequest $request): bool {
                self::assertSame('ub-1', $request->basketId);
                self::assertSame('headless:ub-1', $request->sessionId);
                self::assertSame('user_123', $request->userId);
                self::assertSame(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, $request->paymentId);
                self::assertSame('NOT_FINISHED', $request->initialStatus);

                return true;
            }))
            ->willReturn($this->orderResponse());
        $this->checkoutSessionService->expects($this->once())->method('createSession')
            ->with(
                'contract-1',
                $this->anything(),
                'https://app.example.com/return?session_id={CHECKOUT_SESSION_ID}',
                'https://app.example.com/cancel',
                '1',
                'automatic',
                'order-1',
                '1001',
                null,
                false
            )
            ->willReturn(CheckoutSessionResult::success('cs_1', 'https://checkout.stripe.com/c/cs_1'));
        $this->contractRepository->expects($this->never())->method('findActiveByUserId');
        $handler = $this->handler();

        $result = $handler->processPayment($this->headlessContext());

        self::assertTrue($result->isSuccess(), (string) $result->getErrorMessage());
        self::assertSame('contract-1', $result->getContractId());
        self::assertSame('redirect', $result->getMetadataValue('renderMode'));
        self::assertSame('https://checkout.stripe.com/c/cs_1', $result->getMetadataValue('redirectUrl'));
        self::assertSame('cs_1', $result->getMetadataValue('sessionId'));
        self::assertNull($result->getClientSecret());

        self::assertSame([], $handler->sessionCalls, 'a headless checkout never touches the PHP session');
        self::assertSame('ub-1', $this->contract->getMetadata('basket_id'));
        self::assertTrue($this->contract->getState()->isPending());
        self::assertSame('cs_1', $this->contract->getProviderOrderId());
    }

    public function testHeadlessEmbeddedUsesTheReturnUrlAsStripeReturnUrlAndAnswersTheClientSecret(): void
    {
        $this->checkoutSessionService->expects($this->once())->method('createSession')
            ->with(
                'contract-1',
                $this->anything(),
                'https://app.example.com/return?step=done&session_id={CHECKOUT_SESSION_ID}',
                'https://app.example.com/cancel',
                '1',
                'automatic',
                'order-1',
                '1001',
                null,
                true
            )
            ->willReturn(CheckoutSessionResult::embedded('cs_1', 'cs_1_secret_x'));

        $result = $this->handler()->processPayment(
            $this->headlessContext(uiMode: 'embedded', returnUrl: 'https://app.example.com/return?step=done')
        );

        self::assertTrue($result->isSuccess(), (string) $result->getErrorMessage());
        self::assertSame('cs_1_secret_x', $result->getClientSecret());
        self::assertSame('embedded', $result->getMetadataValue('renderMode'));
    }

    public function testAnUnsupportedUiModeIsRefusedBeforeAnythingIsCreated(): void
    {
        $this->contractService->expects($this->never())->method('createContract');
        $this->shopOrderService->expects($this->never())->method('createOrder');

        $result = $this->handler()->processPayment($this->headlessContext(uiMode: 'custom'));

        self::assertFalse($result->isSuccess());
        self::assertSame('STRIPE_UI_MODE_UNSUPPORTED', $result->getErrorCode());
    }

    public function testWithoutACancelUrlTheReturnUrlIsTheCancelTargetToo(): void
    {
        $this->checkoutSessionService->expects($this->once())->method('createSession')
            ->with(
                $this->anything(),
                $this->anything(),
                'https://app.example.com/return?session_id={CHECKOUT_SESSION_ID}',
                'https://app.example.com/return',
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                null,
                false
            )
            ->willReturn(CheckoutSessionResult::success('cs_1', 'https://checkout.stripe.com/c/cs_1'));

        self::assertTrue($this->handler()->processPayment($this->headlessContext(cancelUrl: null))->isSuccess());
    }

    /**
     * The one-page checkout keeps everything it had: the session basket gets the
     * payment, the skip-address flag is set, the order request names the shop
     * session and no basket id, and Stripe's URLs are the shop's own.
     */
    public function testTheOpcPathStillUsesTheSession(): void
    {
        $this->shopOrderService->expects($this->once())->method('createOrder')
            ->with($this->callback(function (CreateOrderRequest $request): bool {
                self::assertNull($request->basketId);
                self::assertSame('sid-twig', $request->sessionId);

                return true;
            }))
            ->willReturn($this->orderResponse());
        $this->checkoutSessionService->method('buildSuccessUrl')->willReturn('https://shop.example.com/index.php?cl=order&fnc=checkoutSuccess');
        $this->checkoutSessionService->method('buildCancelUrl')->willReturn('https://shop.example.com/index.php?cl=payment');
        $this->checkoutSessionService->expects($this->once())->method('createSession')
            ->with(
                'contract-1',
                $this->anything(),
                'https://shop.example.com/index.php?cl=order&fnc=checkoutSuccess',
                'https://shop.example.com/index.php?cl=payment',
                $this->anything(),
                $this->anything(),
                'order-1',
                '1001',
                null,
                false
            )
            ->willReturn(CheckoutSessionResult::success('cs_1', 'https://checkout.stripe.com/c/cs_1'));
        $handler = $this->handler();

        $result = $handler->processPayment($this->opcContext());

        self::assertTrue($result->isSuccess(), (string) $result->getErrorMessage());
        self::assertSame(['prepare:' . StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, 'sessionId', 'sessionId'], $handler->sessionCalls);
        self::assertSame('redirect', $result->getMetadataValue('renderMode'));
        self::assertNull($this->contract->getMetadata('basket_id'));
    }

    // ---------------------------------------------------------------- helpers

    private function handler(): SessionRecordingStripeHandler
    {
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopUrl')->willReturn('https://shop.example.com/');
        $shopAdapter->method('getShopId')->willReturn('1');
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getCaptureMode')->willReturn('automatic');
        $config->method('getPublishableKey')->willReturn('pk_test');
        $iframe = $this->createMock(IframeCheckoutSettingsInterface::class);
        $iframe->method('isEnabled')->willReturn(false);
        $language = $this->createMock(LanguageResolverInterface::class);
        $language->method('getActiveLanguageId')->willReturn(0);

        return new SessionRecordingStripeHandler(
            $this->contractService,
            $this->checkoutSessionService,
            $this->contractRepository,
            $shopAdapter,
            $this->shopOrderService,
            $config,
            $this->tokenService,
            null,
            $language,
            $iframe,
        );
    }

    private function headlessContext(
        string $uiMode = 'hosted',
        string $returnUrl = 'https://app.example.com/return',
        ?string $cancelUrl = 'https://app.example.com/cancel',
    ): PaymentContextInterface {
        return $this->context([
            'headless' => true,
            'basketId' => 'ub-1',
            'uiMode' => $uiMode,
            'sessionId' => 'headless:ub-1',
        ], $returnUrl, $cancelUrl);
    }

    private function opcContext(): PaymentContextInterface
    {
        return $this->context([], null, null);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function context(array $metadata, ?string $returnUrl, ?string $cancelUrl): PaymentContextInterface
    {
        $context = $this->createMock(PaymentContextInterface::class);
        $context->method('getPaymentMethodId')->willReturn(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID);
        $context->method('getBasket')->willReturn(new \stdClass());
        $context->method('getUser')->willReturn(new class () {
            public function getId(): string
            {
                return 'user_123';
            }
        });
        $context->method('getReturnUrl')->willReturn($returnUrl);
        $context->method('getCancelUrl')->willReturn($cancelUrl);
        $context->method('getMetadata')->willReturn($metadata);
        $context->method('getMetadataValue')->willReturnCallback(
            static fn(string $key, mixed $default = null): mixed => $metadata[$key] ?? $default
        );

        return $context;
    }

    private function orderResponse(): OrderResponse
    {
        return new OrderResponse('order-1', 1001, 'user_123', 100.0, 'EUR', 'not_finished', StripeDefinitions::STRIPE_WALLET_PAYMENT_ID);
    }
}
