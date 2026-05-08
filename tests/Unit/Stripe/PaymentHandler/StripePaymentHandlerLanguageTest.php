<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\PaymentHandler;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Session;
use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutSessionResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sprint 92: Verifies the OPC checkout path forwards the active language id
 * into both the success URL (via buildSuccessUrl) and the cancel URL string.
 *
 * Uses a testable subclass that no-ops the early-order step so we don't need
 * an OXID session bootstrap to verify URL composition.
 *
 * @covers \OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler
 */
final class StripePaymentHandlerLanguageTest extends TestCase
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

        $this->tokenService->method('generateToken')->willReturn('tok_abc');

        $this->contractService->method('createContract')
            ->willReturn($this->createMock(PaymentContract::class));

        // Stub OXID session — handler reads its id when composing URLs.
        $session = $this->createMock(Session::class);
        $session->method('getId')->willReturn('php_session_xyz');
        Registry::set(Session::class, $session);
    }

    public function testSuccessUrlIsBuiltWithActiveLanguageIdFromResolver(): void
    {
        $this->checkoutSessionService->expects($this->once())
            ->method('buildSuccessUrl')
            ->with(
                'https://shop.example.com/',
                $this->isType('string'),
                'tok_abc',
                $this->isType('string'),
                1,
                1
            )
            ->willReturn('https://shop.example.com/success');

        $this->checkoutSessionService->method('buildCancelUrl')->willReturnArgument(0);
        $this->checkoutSessionService->method('createSession')
            ->willReturn(CheckoutSessionResult::success('cs_test', 'https://stripe/checkout'));

        $this->createHandler($this->resolverReturning(1))->processPayment($this->createPaymentContext());
    }

    public function testCancelUrlContainsLanguageAndShopId(): void
    {
        $this->checkoutSessionService->method('buildSuccessUrl')->willReturn('https://x');
        $this->checkoutSessionService->expects($this->once())
            ->method('buildCancelUrl')
            ->with($this->logicalAnd(
                $this->stringContains('lang=1'),
                $this->stringContains('shp=1')
            ))
            ->willReturnArgument(0);
        $this->checkoutSessionService->method('createSession')
            ->willReturn(CheckoutSessionResult::success('cs_test', 'https://stripe/checkout'));

        $this->createHandler($this->resolverReturning(1))->processPayment($this->createPaymentContext());
    }

    public function testFallsBackToZeroWhenResolverReturnsZero(): void
    {
        $this->checkoutSessionService->expects($this->once())
            ->method('buildSuccessUrl')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                0,
                1
            )
            ->willReturn('https://x');

        $this->checkoutSessionService->expects($this->once())
            ->method('buildCancelUrl')
            ->with($this->stringContains('lang=0'))
            ->willReturnArgument(0);

        $this->checkoutSessionService->method('createSession')
            ->willReturn(CheckoutSessionResult::success('cs_test', 'https://stripe/checkout'));

        $this->createHandler($this->resolverReturning(0))->processPayment($this->createPaymentContext());
    }

    private function createHandler(LanguageResolverInterface $resolver): StripePaymentHandler
    {
        return new class (
            $this->contractService,
            $this->checkoutSessionService,
            $this->contractRepository,
            $this->shopAdapter,
            $this->shopOrderService,
            $this->config,
            $this->tokenService,
            null,
            $resolver
        ) extends StripePaymentHandler {
            public function __construct(
                ContractServiceInterface $contractService,
                CheckoutSessionServiceInterface $checkoutSessionService,
                ContractRepositoryInterface $contractRepository,
                ShopAdapterInterface $shopAdapter,
                ShopOrderServiceInterface $shopOrderService,
                ModuleConfigurationServiceInterface $config,
                TokenServiceInterface $tokenService,
                ?LoggerInterface $logger,
                LanguageResolverInterface $resolver
            ) {
                parent::__construct(
                    $contractService,
                    $checkoutSessionService,
                    $contractRepository,
                    $shopAdapter,
                    $shopOrderService,
                    $config,
                    $tokenService,
                    $logger,
                    $resolver
                );
            }

            protected function createEarlyOrderAndTransition(
                \OxidEsales\PaymentBase\Contract\PaymentContractInterface $contract,
                PaymentContextInterface $context
            ): void {
                // No-op: bypass session-bound order creation for unit isolation.
            }
        };
    }

    private function resolverReturning(int $id): LanguageResolverInterface
    {
        $resolver = $this->createMock(LanguageResolverInterface::class);
        $resolver->method('getActiveLanguageId')->willReturn($id);
        return $resolver;
    }

    private function createPaymentContext(): PaymentContextInterface
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

        return $context;
    }
}
