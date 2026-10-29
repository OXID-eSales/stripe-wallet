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
use OxidEsales\PaymentBase\Adapter\EarlyOrderWriter;
use OxidEsales\PaymentBase\Adapter\ShopOrderRewriterInterface;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Checkout\Context\CheckoutContextInterface;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistry;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\EventDispatcher;
use OxidEsales\PaymentBase\EventSystem\EventListenerProvider;
use OxidEsales\PaymentBase\EventSystem\Handler\EarlyOrderCreationHandler;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler;
use OxidEsales\Payments\Stripe\Service\CheckoutInFlightGuard;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutSessionResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

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
 *
 * OPC-175: both paths create the early order through payment-base's shared
 * chain (ContractDraftCompletedEvent -> EarlyOrderCreationHandler), as Mollie,
 * PayPal and Stripe's own `cl=order` checkout do. The chain here is the real
 * one; only the shop order service is a mock.
 */
final class StripePaymentHandlerHeadlessTest extends TestCase
{
    private ContractServiceInterface&MockObject $contractService;
    private CheckoutSessionServiceInterface&MockObject $checkoutSessionService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ShopOrderServiceInterface&MockObject $shopOrderService;
    private TokenServiceInterface&MockObject $tokenService;
    private PaymentContract $contract;
    private ShopOrderRewriterInterface&MockObject $orderRewriter;
    private ?OpenCheckoutAttemptRegistry $openAttempts = null;
    private ?CheckoutInFlightGuard $inFlightGuard = null;
    private bool $wireTheSharedChain = true;

    protected function setUp(): void
    {
        $this->contractService = $this->createMock(ContractServiceInterface::class);
        $this->checkoutSessionService = $this->createMock(CheckoutSessionServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->shopOrderService = $this->createMock(ShopOrderServiceInterface::class);
        $this->tokenService = $this->createMock(TokenServiceInterface::class);
        $this->tokenService->method('generateToken')->willReturn('tok');
        $this->orderRewriter = $this->createMock(ShopOrderRewriterInterface::class);

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

    public function testProviderFailure_SessionRefusedByStripe_ResultMessageContainsNoProviderText(): void
    {
        $this->checkoutSessionService->method('createSession')->willReturn(CheckoutSessionResult::failure(
            'Invalid URL https://app.example.com/return?uid=u-1 for cs_live_a1b2',
            'url_invalid'
        ));

        $result = $this->handler()->processPayment($this->headlessContext());

        self::assertFalse($result->isSuccess());
        self::assertSame('STRIPE_SESSION_FAILED', $result->getErrorCode());
        $message = (string) $result->getErrorMessage();
        self::assertStringNotContainsString('https://', $message);
        self::assertStringNotContainsString('cs_', $message);
        self::assertStringNotContainsString('uid=', $message);
    }

    public function testProviderFailure_SessionRefusedByStripe_TextGoesToTheDebugLogWithTheContractId(): void
    {
        $this->checkoutSessionService->method('createSession')
            ->willReturn(CheckoutSessionResult::failure('Invalid URL for cs_live_a1b2', 'url_invalid'));
        $logger = $this->createMock(LoggerInterface::class);
        $logged = [];
        $logger->method('debug')->willReturnCallback(static function (string|\Stringable $message, array $context) use (&$logged): void {
            $logged[] = [LogLevel::DEBUG, $context];
        });
        $logger->method('error')->willReturnCallback(static function (string|\Stringable $message, array $context) use (&$logged): void {
            $logged[] = [LogLevel::ERROR, $context];
        });

        $this->handler($logger)->processPayment($this->headlessContext());

        $debug = array_values(array_filter($logged, static fn(array $entry): bool => $entry[0] === LogLevel::DEBUG));
        self::assertCount(1, $debug);
        self::assertSame('contract-1', $debug[0][1]['contractId']);
        self::assertSame('Invalid URL for cs_live_a1b2', $debug[0][1]['providerMessage']);
        foreach (array_filter($logged, static fn(array $entry): bool => $entry[0] === LogLevel::ERROR) as $entry) {
            self::assertStringNotContainsString('cs_', (string) json_encode($entry[1]));
        }
    }

    public function testProviderFailure_UnexpectedThrowable_ResultMessageAndErrorLogCarryNoExceptionText(): void
    {
        $this->checkoutSessionService->method('createSession')
            ->willThrowException(new \RuntimeException('No such session cs_live_a1b2 at https://api.stripe.com/v1'));
        $errorContexts = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string|\Stringable $message, array $context) use (&$errorContexts): void {
            $errorContexts[] = (string) json_encode([(string) $message, $context]);
        });

        $result = $this->handler($logger)->processPayment($this->headlessContext());

        self::assertSame('STRIPE_PAYMENT_FAILED', $result->getErrorCode());
        self::assertStringNotContainsString('cs_', (string) $result->getErrorMessage());
        self::assertNotSame([], $errorContexts);
        foreach ($errorContexts as $line) {
            self::assertStringNotContainsString('cs_', $line);
            self::assertStringNotContainsString('https://', $line);
        }
    }

    /**
     * OPC-175 — one order, one contract. The open attempt of this session
     * (here a Mollie one) continues: payment-base's ContractService hands back
     * the same contract, reopened with its order; that order is written again
     * for Stripe. No second order, no second contract.
     */
    public function testOpcStart_AnOpenAttemptContinues_ItsOrderIsWrittenAgain(): void
    {
        $this->contract->transitionToNotFinished('order-kept');
        $this->contract->transitionToPending();
        $this->contract->setProvider('mollie', 'tr_1');
        $this->contract->reopenForNewAttempt(AttemptClosure::Closed, $this->contract->getBasketSnapshot(), [ContractCondition::TYPE_PAYMENT_AUTHORIZED]);
        $this->shopOrderService = $this->createMock(ShopOrderServiceInterface::class);
        $this->shopOrderService->expects($this->never())->method('createOrder');
        $this->orderRewriter->expects($this->once())->method('rewriteOrder')->with('order-kept')
            ->willReturn(new OrderResponse('order-kept', 1001, 'user_123', 100.0, 'EUR', 'not_finished', StripeDefinitions::STRIPE_WALLET_PAYMENT_ID));
        $this->checkoutSessionService->method('createSession')
            ->willReturn(CheckoutSessionResult::success('cs_1', 'https://checkout.stripe.com/c/cs_1'));

        $result = $this->handler()->processPayment($this->opcContext());

        self::assertTrue($result->isSuccess(), (string) $result->getErrorMessage());
        self::assertSame('order-kept', $this->contract->getOrderId());
        self::assertTrue($this->contract->getState()->isPending());
    }

    public function testOpcStart_RemembersTheNewContractAsTheOpenAttemptOfThisSession(): void
    {
        $this->openAttempts = $this->registryRemembering(null);
        $this->checkoutSessionService->method('createSession')
            ->willReturn(CheckoutSessionResult::success('cs_1', 'https://checkout.stripe.com/c/cs_1'));

        $this->handler()->processPayment($this->opcContext());

        self::assertSame('contract-1', $this->openAttempts->peek());
        self::assertTrue($this->contract->getState()->isPending());
        self::assertSame('order-1', $this->contract->getOrderId());
        self::assertSame('1001', $this->contract->getMetadata('order_number'));
    }

    /**
     * The handler no longer creates the order itself. If nothing on the chain
     * did (a broken wiring), no Stripe session may be opened for a contract
     * that has no order to pay for.
     */
    public function testOpcStart_WhenNoOrderWasCreated_FailsAndOpensNoStripeSession(): void
    {
        $this->wireTheSharedChain = false;
        $this->checkoutSessionService->expects($this->never())->method('createSession');

        $result = $this->handler()->processPayment($this->opcContext());

        self::assertFalse($result->isSuccess());
        self::assertSame('STRIPE_PAYMENT_FAILED', $result->getErrorCode());
    }

    public function testOpcStart_WhenTheCheckoutInFlightIsReused_NoOrderIsWritten(): void
    {
        $inFlight = $this->createMock(CheckoutInFlightGuard::class);
        $inFlight->method('inspect')->willReturn(new StripeCheckoutSessionDto(
            id: 'cs_open',
            paymentStatus: 'unpaid',
            paymentIntentId: '',
            paymentIntentStatus: 'unknown',
            metadata: [],
            amountTotal: 10000,
            currency: 'eur',
            url: 'https://checkout.stripe.com/c/cs_open',
        ));
        $this->inFlightGuard = $inFlight;
        $this->contractRepository->method('findActiveByUserId')->willReturn($this->contract);
        $this->openAttempts = $this->registryRemembering('contract-1');
        $this->shopOrderService->expects($this->never())->method('createOrder');
        $this->orderRewriter->expects($this->never())->method('rewriteOrder');

        $result = $this->handler()->processPayment($this->opcContext());

        self::assertTrue($result->getMetadataValue('reused'));
        self::assertSame('contract-1', $this->openAttempts->peek());
    }

    // ---------------------------------------------------------------- helpers

    private function handler(?LoggerInterface $logger = null): SessionRecordingStripeHandler
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
            $config,
            $this->tokenService,
            $this->sharedChain(),
            $logger,
            $language,
            $iframe,
            null,
            $this->inFlightGuard,
        );
    }

    /**
     * The real payment-base chain: the dispatcher hands ContractDraftCompletedEvent
     * to the real EarlyOrderCreationHandler, which retires, creates the order
     * (mock), transitions the contract and remembers it.
     */
    private function sharedChain(): EventDispatcher
    {
        if (!$this->wireTheSharedChain) {
            return new EventDispatcher(new EventListenerProvider([]));
        }

        $earlyOrderCreation = new EarlyOrderCreationHandler(
            $this->contractRepository,
            new EarlyOrderWriter($this->shopOrderService, $this->orderRewriter),
            $this->openAttempts ?? $this->registryRemembering(null),
        );

        return new EventDispatcher(new EventListenerProvider([$earlyOrderCreation]));
    }

    private function registryRemembering(?string $contractId): OpenCheckoutAttemptRegistry
    {
        $context = new class () implements CheckoutContextInterface {
            /** @var array<string, mixed> */
            private array $values = [];

            public function getScopeId(): string
            {
                return 'session-1';
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function remove(string $key): void
            {
                unset($this->values[$key]);
            }
        };
        $registry = new OpenCheckoutAttemptRegistry($context);
        if ($contractId !== null) {
            $registry->remember($contractId);
        }

        return $registry;
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
