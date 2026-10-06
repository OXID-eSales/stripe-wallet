<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Controller;

use OxidEsales\GraphQL\Base\DataType\User;
use OxidEsales\GraphQL\Base\Service\Authentication;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCancelResult;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessReturnResult;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartRequest;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutCancelResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutReturnResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutStartResult;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\GraphQL\Controller\StripeCheckout;
use OxidEsales\Payments\Stripe\GraphQL\Exception\StripeCheckoutError;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * GRAPH-QL / PS4 — the three mutations are a thin shell over payment-base's
 * HeadlessCheckoutService: the JWT user is the buyer, the Stripe session id
 * the client got back from Stripe plus a Stripe contract token (minted here,
 * since payment-base already verified its own) travel to the return resolver,
 * and a refusal becomes a client-safe GraphQL error with the stable code.
 */
final class StripeCheckoutTest extends TestCase
{
    private HeadlessCheckoutServiceInterface&MockObject $checkout;
    private Authentication&MockObject $authentication;
    private TokenServiceInterface&MockObject $tokens;

    protected function setUp(): void
    {
        $this->checkout = $this->createMock(HeadlessCheckoutServiceInterface::class);
        $this->authentication = $this->createMock(Authentication::class);
        $this->authentication->method('getUser')->willReturn(new User('user-1'));
        $this->tokens = $this->createMock(TokenServiceInterface::class);
        $this->tokens->method('generateToken')->willReturnCallback(static fn(string $id): string => 'stripe-tok-' . $id);
    }

    public function testStartHandsTheJwtUserAndTheArgumentsToTheHeadlessService(): void
    {
        $this->checkout->expects($this->once())->method('start')
            ->with($this->callback(function (HeadlessStartRequest $r): bool {
                self::assertSame('user-1', $r->userId);
                self::assertSame('ub-1', $r->basketId);
                self::assertTrue($r->confirmTermsAndConditions);
                self::assertSame('https://app.example.com/return', $r->returnUrl);
                self::assertSame('https://app.example.com/cancel', $r->cancelUrl);
                self::assertSame('embedded', $r->uiMode);
                self::assertSame('oe_payments_stripe_wallet', $r->paymentId, 'the mutation names its own payment');

                return true;
            }))
            ->willReturn(new HeadlessStartResult('ctr-1', 'tok-1', 'stripe', '1001', null, 'cs_secret', 'embedded'));

        $result = $this->controller()->stripeCheckoutStart(
            new ID('ub-1'),
            true,
            'https://app.example.com/return',
            'https://app.example.com/cancel',
            'embedded'
        );

        self::assertInstanceOf(CheckoutStartResult::class, $result);
        self::assertSame('ctr-1', $result->contractId());
        self::assertSame('tok-1', $result->contractToken());
        self::assertSame('cs_secret', $result->clientSecret());
        self::assertSame('embedded', $result->renderMode());
    }

    public function testStartDefaultsToHostedWithoutACancelUrl(): void
    {
        $this->checkout->method('start')
            ->with($this->callback(fn(HeadlessStartRequest $r): bool => $r->uiMode === 'hosted' && $r->cancelUrl === null))
            ->willReturn(new HeadlessStartResult('ctr-1', 'tok-1', 'stripe', null, 'https://checkout.stripe.com/c/cs_1', null, 'redirect'));

        $result = $this->controller()->stripeCheckoutStart(new ID('ub-1'), true, 'https://app.example.com/return');

        self::assertSame('https://checkout.stripe.com/c/cs_1', $result->redirectUrl());
    }

    public function testARefusedStartBecomesAClientSafeErrorWithTheStableCode(): void
    {
        $this->checkout->method('start')->willThrowException(
            new HeadlessCheckoutException(HeadlessCheckoutException::TERMS_NOT_CONFIRMED, 'The shop requires consent', null)
        );

        try {
            $this->controller()->stripeCheckoutStart(new ID('ub-1'), false, 'https://app.example.com/return');
            self::fail('refusals surface as GraphQL errors');
        } catch (StripeCheckoutError $e) {
            self::assertSame('The shop requires consent', $e->getMessage());
            self::assertSame('requesterror', $e->getCategory());
            self::assertSame(HeadlessCheckoutException::TERMS_NOT_CONFIRMED, $e->getExtensions()['errorCode']);
            self::assertTrue($e->isClientSafe());
        }
    }

    public function testReturnPassesTheStripeSessionIdAndAFreshStripeContractToken(): void
    {
        $this->checkout->expects($this->once())->method('return')
            ->with('ctr-1', 'tok-1', ['checkoutSessionId' => 'cs_1', 'contract_token' => 'stripe-tok-ctr-1'])
            ->willReturn(new HeadlessReturnResult('committed', 'order-1', '1001', 'committed'));

        $result = $this->controller()->stripeCheckoutReturn('ctr-1', 'tok-1', 'cs_1');

        self::assertInstanceOf(CheckoutReturnResult::class, $result);
        self::assertSame('committed', $result->status());
        self::assertSame('order-1', $result->orderId());
    }

    public function testReturnWithoutASessionIdLeavesItToTheResolverToReport(): void
    {
        $this->checkout->expects($this->once())->method('return')
            ->with('ctr-1', 'tok-1', ['contract_token' => 'stripe-tok-ctr-1'])
            ->willReturn(new HeadlessReturnResult('pending', null, '1001', 'pending'));

        self::assertSame('pending', $this->controller()->stripeCheckoutReturn('ctr-1', 'tok-1', null)->status());
    }

    public function testCancelDelegatesAndAnswersTheState(): void
    {
        $this->checkout->expects($this->once())->method('cancel')->with('ctr-1', 'tok-1')
            ->willReturn(new HeadlessCancelResult(true, 'ctr-1', 'cancelled'));

        $result = $this->controller()->stripeCheckoutCancel('ctr-1', 'tok-1');

        self::assertInstanceOf(CheckoutCancelResult::class, $result);
        self::assertTrue($result->cancelled());
        self::assertSame('cancelled', $result->contractState());
    }

    public function testAnInvalidTokenOnReturnIsAClientSafeError(): void
    {
        $this->checkout->method('return')->willThrowException(
            new HeadlessCheckoutException(HeadlessCheckoutException::INVALID_TOKEN, 'bad token')
        );

        $this->expectException(StripeCheckoutError::class);

        $this->controller()->stripeCheckoutReturn('ctr-1', 'nope', 'cs_1');
    }

    private function controller(): StripeCheckout
    {
        return new StripeCheckout($this->checkout, $this->tokens, $this->authentication);
    }
}
