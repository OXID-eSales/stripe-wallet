<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp;

use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Response\PaymentResponse;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Checkout\Headless\ContractOpeningServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserResolverInterface;
use OxidEsales\PaymentBase\Checkout\Headless\UserBasketFactoryInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AcpResponseFormatterInterface;
use OxidEsales\PaymentBase\Mcp\AgentContext;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Mcp\StripeAcpCheckoutService;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * GRAPH-QL / PS5 — Stripe's ACP checkout service on payment-base's headless
 * path: create_checkout is the base class's default (buyer → shop user, items
 * → user basket paying with Stripe, contract opened through the shared chain);
 * complete_checkout charges the agent's delegated payment token as a confirmed
 * PaymentIntent and commits through the same service the webhooks use.
 */
final class StripeAcpCheckoutServiceTest extends TestCase
{
    private StripeAdapterInterface&MockObject $adapter;
    private ContractCommitServiceInterface&MockObject $commit;
    private AcpResponseFormatterInterface&MockObject $formatter;
    private ContractRepositoryInterface&MockObject $contracts;
    private ModuleConfigurationServiceInterface&MockObject $config;
    private PaymentContract $contract;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(StripeAdapterInterface::class);
        $this->commit = $this->createMock(ContractCommitServiceInterface::class);
        $this->formatter = $this->createMock(AcpResponseFormatterInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $this->config->method('getCaptureMode')->willReturn(StripeDefinitions::CAPTURE_MODE_AUTOMATIC);

        $this->contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]), 'ctr-1');
        $this->contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $this->contract->transitionToNotFinished('order-1');
        $this->contract->transitionToPending();
        $this->contract->setMetadata('order_number', '1001');
        $this->contracts->method('findById')->with('ctr-1')->willReturn($this->contract);
        $this->formatter->method('validationError')->willReturnCallback(
            static fn(string $message, ?string $param = null): array => ['error' => ['message' => $message, 'param' => $param]]
        );
    }

    public function testIsTheAcpCheckoutServiceForStripe(): void
    {
        self::assertInstanceOf(AcpCheckoutServiceInterface::class, $this->service());
    }

    public function testCreateCheckoutUsesTheBaseDefaultWithTheStripePaymentId(): void
    {
        $buyers = $this->createMock(GuestUserResolverInterface::class);
        $buyers->method('resolve')->willReturn('user-1');
        $baskets = $this->createMock(UserBasketFactoryInterface::class);
        $baskets->expects($this->once())->method('create')
            ->with('user-1', [['id' => 'art-1', 'quantity' => 1]], StripeDefinitions::STRIPE_WALLET_PAYMENT_ID)
            ->willReturn('ub-1');
        $opening = $this->createMock(ContractOpeningServiceInterface::class);
        $opening->expects($this->once())->method('open')
            ->with('user-1', 'ub-1', StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, 'acp')
            ->willReturn($this->contract);
        $this->formatter->method('formatCheckout')->willReturn(['id' => 'ctr-1', 'status' => 'ready_for_payment']);

        $result = $this->service($opening, $baskets, $buyers)->createCheckout(
            ['items' => [['id' => 'art-1', 'quantity' => 1]], 'buyer' => ['email' => 'a@example.com']],
            new AgentContext('agent-1', 'tok')
        );

        self::assertSame('ctr-1', $result['id']);
    }

    public function testCompleteCheckoutChargesTheDelegatedTokenAndCommits(): void
    {
        $this->adapter->expects($this->once())->method('createPayment')
            ->with($this->callback(function (CreatePaymentRequest $r): bool {
                self::assertSame(100.0, $r->amount);
                self::assertSame('EUR', $r->currency);
                self::assertSame('order-1', $r->orderId);
                self::assertSame('1', $r->shopId);
                self::assertSame('spt_123', $r->paymentMethodId);
                self::assertTrue($r->directCapture);
                self::assertSame('ctr-1', $r->metadata['contract_id']);
                self::assertSame('1001', $r->metadata['order_number']);
                self::assertSame('acp', $r->metadata['channel']);

                return true;
            }))
            ->willReturn(new PaymentResponse('pi_1', StripeStatusMapper::STATUS_CAPTURED, 100.0, 'EUR'));
        $this->commit->expects($this->once())->method('commit')
            ->with($this->callback(function (PaymentConfirmation $c): bool {
                self::assertSame('ctr-1', $c->contractId);
                self::assertSame('stripe', $c->providerName);
                self::assertSame('pi_1', $c->authorizationId);
                self::assertSame('pi_1', $c->providerOrderId);
                self::assertSame(100.0, $c->amount);
                self::assertSame('EUR', $c->currency);
                self::assertFalse($c->requiresCapture);
                self::assertSame('acp', $c->source);

                return true;
            }))
            ->willReturn(CommitOutcome::committed('order-1'));
        $this->formatter->expects($this->once())->method('formatOrder')
            ->with($this->contract, 'https://shop.example.com/index.php?cl=account_order')
            ->willReturn(['id' => 'order-1', 'checkout_session_id' => 'ctr-1']);

        $result = $this->service()->completeCheckout('ctr-1', ['token' => 'spt_123', 'provider' => 'stripe'], new AgentContext('agent-1', 'tok'));

        self::assertSame('order-1', $result['id']);
        self::assertSame('agent-1', $this->contract->getMetadata('acp_agent_id'));
    }

    public function testAnAuthorizedIntentCommitsAsRequiringCapture(): void
    {
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $this->config->method('getCaptureMode')->willReturn(StripeDefinitions::CAPTURE_MODE_MANUAL);
        $this->adapter->method('createPayment')
            ->with($this->callback(fn(CreatePaymentRequest $r): bool => $r->directCapture === false))
            ->willReturn(new PaymentResponse('pi_1', StripeStatusMapper::STATUS_AUTHORIZED, 100.0, 'EUR'));
        $this->commit->expects($this->once())->method('commit')
            ->with($this->callback(fn(PaymentConfirmation $c): bool => $c->requiresCapture === true))
            ->willReturn(CommitOutcome::committed('order-1'));
        $this->formatter->method('formatOrder')->willReturn(['id' => 'order-1']);

        self::assertSame('order-1', $this->service()->completeCheckout('ctr-1', ['token' => 'spt_123'], new AgentContext('agent-1', 'tok'))['id']);
    }

    public function testAnUnconfirmedIntentIsAValidationErrorAndNothingIsCommitted(): void
    {
        $this->adapter->method('createPayment')->willReturn(new PaymentResponse('pi_1', StripeStatusMapper::STATUS_FAILED, 100.0, 'EUR'));
        $this->commit->expects($this->never())->method('commit');

        $result = $this->service()->completeCheckout('ctr-1', ['token' => 'spt_bad'], new AgentContext('agent-1', 'tok'));

        self::assertSame('payment_data.token', $result['error']['param']);
        self::assertStringContainsString('failed', $result['error']['message']);
    }

    public function testAStripeFailureIsAValidationErrorNotAnException(): void
    {
        $this->adapter->method('createPayment')->willThrowException(new \RuntimeException('card_declined'));
        $this->commit->expects($this->never())->method('commit');

        $result = $this->service()->completeCheckout('ctr-1', ['token' => 'spt_bad'], new AgentContext('agent-1', 'tok'));

        self::assertStringContainsString('card_declined', $result['error']['message']);
    }

    public function testARefusedCommitIsReportedAfterTheChargeSucceeded(): void
    {
        $this->adapter->method('createPayment')->willReturn(new PaymentResponse('pi_1', StripeStatusMapper::STATUS_CAPTURED, 100.0, 'EUR'));
        $this->commit->method('commit')->willReturn(CommitOutcome::refused('amount_mismatch'));
        $this->formatter->expects($this->never())->method('formatOrder');

        $result = $this->service()->completeCheckout('ctr-1', ['token' => 'spt_123'], new AgentContext('agent-1', 'tok'));

        self::assertStringContainsString('amount_mismatch', $result['error']['message']);
        self::assertStringContainsString('pi_1', $result['error']['message'], 'the merchant must be able to find the money');
    }

    private function service(
        ?ContractOpeningServiceInterface $opening = null,
        ?UserBasketFactoryInterface $baskets = null,
        ?GuestUserResolverInterface $buyers = null,
    ): StripeAcpCheckoutService {
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($this->adapter);
        $shop = $this->createMock(ShopAdapterInterface::class);
        $shop->method('getShopUrl')->willReturn('https://shop.example.com/');
        $shop->method('getShopId')->willReturn('1');

        return new StripeAcpCheckoutService(
            $factory,
            $shop,
            $this->config,
            $this->createMock(ContractServiceInterface::class),
            $this->contracts,
            $this->createMock(EventDispatcherInterface::class),
            $this->formatter,
            $opening,
            $baskets,
            $buyers,
            $this->commit
        );
    }
}
