<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\PaymentHandler;

use OxidEsales\PaymentComponent\Adapter\PaymentContext;
use OxidEsales\PaymentComponent\Adapter\PaymentHandlerInterface;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentResponse;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler
 */
class StripePaymentHandlerTest extends TestCase
{
    private ContractServiceInterface&MockObject $contractService;
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ShopAdapterInterface&MockObject $shopAdapter;
    private ModuleConfigurationServiceInterface&MockObject $config;

    protected function setUp(): void
    {
        $this->contractService = $this->createMock(ContractServiceInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);

        $this->shopAdapter->method('getShopUrl')->willReturn('https://shop.example.com/');
        $this->shopAdapter->method('getShopId')->willReturn('1');
        $this->config->method('getCaptureMode')->willReturn('automatic');
        $this->config->method('getPublishableKey')->willReturn('pk_test_123');
        $this->config->method('isConfigured')->willReturn(true);

        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->stripeAdapter);
    }

    private function createHandler(): StripePaymentHandler
    {
        return new StripePaymentHandler(
            $this->contractService,
            $this->adapterFactory,
            $this->contractRepository,
            $this->shopAdapter,
            $this->config
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

    public function testProcessPaymentCreatesContractAndPaymentIntent(): void
    {
        $contract = $this->createContractMock();

        $metadataSet = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function (string $key, mixed $value) use (&$metadataSet): void {
                $metadataSet[$key] = $value;
            });

        $this->contractService
            ->expects($this->once())
            ->method('createContract')
            ->willReturn($contract);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createPayment')
            ->willReturn(new PaymentResponse(
                providerPaymentId: 'pi_test_123',
                status: 'requires_confirmation',
                amount: 100.0,
                currency: 'eur',
                requiresAction: false,
                clientSecret: 'pi_test_123_secret_abc'
            ));

        $contract->expects($this->once())
            ->method('setProvider')
            ->with('stripe', 'pi_test_123', null);

        $this->contractRepository
            ->expects($this->once())
            ->method('save');

        $context = new PaymentContext(
            basket: $this->createBasketMock(),
            user: $this->createUserMock(),
            paymentMethodId: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID
        );

        $result = $this->createHandler()->processPayment($context);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_abc', $result->getContractId());
        $this->assertSame('pi_test_123_secret_abc', $result->getClientSecret());
        $this->assertSame('pi_test_123', $result->getMetadataValue('providerPaymentId'));
        $this->assertSame(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, $metadataSet['payment_method_id']);
        $this->assertSame('stripe', $metadataSet['handler']);
    }

    public function testProcessPaymentReturnsErrorOnAdapterException(): void
    {
        $contract = $this->createContractMock();

        $this->contractService
            ->method('createContract')
            ->willReturn($contract);

        $this->stripeAdapter
            ->method('createPayment')
            ->willThrowException(new \RuntimeException('Stripe API error'));

        $context = new PaymentContext(
            basket: $this->createBasketMock(),
            user: $this->createUserMock(),
            paymentMethodId: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID
        );

        $result = $this->createHandler()->processPayment($context);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Stripe API error', $result->getErrorMessage() ?? '');
    }

    public function testProcessPaymentReturnsErrorOnContractCreationFailure(): void
    {
        $this->contractService
            ->method('createContract')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $context = new PaymentContext(
            basket: $this->createBasketMock(),
            user: $this->createUserMock(),
            paymentMethodId: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID
        );

        $result = $this->createHandler()->processPayment($context);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('DB connection lost', $result->getErrorMessage() ?? '');
    }

    // ── confirmPayment() ──

    public function testConfirmPaymentReturnsSuccessForKnownContract(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_abc');

        $this->contractRepository
            ->method('findById')
            ->with('contract_abc')
            ->willReturn($contract);

        $result = $this->createHandler()->confirmPayment('contract_abc');

        $this->assertTrue($result->isSuccess());
    }

    public function testConfirmPaymentReturnsErrorForUnknownContract(): void
    {
        $this->contractRepository
            ->method('findById')
            ->willReturn(null);

        $result = $this->createHandler()->confirmPayment('nonexistent');

        $this->assertFalse($result->isSuccess());
    }

    // ── getFrontendConfig() ──

    public function testGetFrontendConfigContainsPublishableKey(): void
    {
        $frontendConfig = $this->createHandler()->getFrontendConfig();

        $this->assertSame('pk_test_123', $frontendConfig['publishableKey']);
        $this->assertSame('stripe', $frontendConfig['type']);
        $this->assertFalse($frontendConfig['requiresRedirect']);
    }

    // ── Helpers ──

    private function createContractMock(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_abc');
        $contract->method('getBasketSnapshot')->willReturn(
            BasketSnapshot::fromArray([
                'items' => [],
                'totalGross' => 100.0,
                'totalNet' => 84.03,
                'totalVat' => 15.97,
                'currency' => 'EUR',
            ])
        );
        $contract->method('getOrderId')->willReturn(null);
        $contract->method('getMetadata')->willReturn(null);

        return $contract;
    }

    private function createBasketMock(): object
    {
        return $this->createMock(\stdClass::class);
    }

    private function createUserMock(): object
    {
        return new class () {
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
        };
    }
}
