<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Handler;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Mcp\Handler\AcpContextResolverHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AcpContextResolverHandler.
 *
 * Uses a testable subclass to override OXID framework calls (oxNew, Registry).
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Handler\AcpContextResolverHandler
 */
class AcpContextResolverHandlerTest extends TestCase
{
    public function testSkipsNonAcpSource(): void
    {
        $context = new EventContext([
            'source' => 'web',
            'userId' => 'user_123',
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler();
        $handler->handle($event);

        $this->assertNull($context->get('user'));
        $this->assertSame('user_123', $context->get('userId'));
    }

    public function testSkipsNonMatchingEvent(): void
    {
        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        $handler = $this->createTestableHandler();
        $handler->handle($otherEvent);

        // No exception = pass — handler silently ignores non-matching events
        $this->assertTrue(true);
    }

    public function testSkipsWhenUserIdAlreadySet(): void
    {
        $context = new EventContext([
            'source' => 'acp',
            'userId' => 'existing_user_id',
            'acp_buyer' => ['email' => 'test@example.com'],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler();
        $handler->handle($event);

        $this->assertSame('existing_user_id', $context->get('userId'));
        $this->assertNull($context->get('user'));
    }

    public function testResolvesExistingUserByEmail(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('resolved_user_id');

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn(1);

        $context = new EventContext([
            'source' => 'acp',
            'acp_buyer' => [
                'email' => 'buyer@example.com',
                'first_name' => 'Jane',
                'last_name' => 'Doe',
            ],
            'acp_items' => [
                ['id' => 'article_001', 'quantity' => 2],
            ],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler(
            resolveUserReturn: $user,
            buildBasketReturn: $basket
        );
        $handler->handle($event);

        $this->assertSame('resolved_user_id', $context->get('userId'));
        $this->assertSame($user, $context->get('user'));
        $this->assertSame($basket, $context->get('basket'));
        $this->assertSame(['payment_authorized'], $context->get('conditionTypes'));
    }

    public function testSetsSessionId(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_42');

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn(1);

        $context = new EventContext([
            'source' => 'acp',
            'acp_buyer' => ['email' => 'test@example.com'],
            'acp_items' => [['id' => 'a1', 'quantity' => 1]],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler(
            resolveUserReturn: $user,
            buildBasketReturn: $basket,
            sessionId: 'sess_abc123'
        );
        $handler->handle($event);

        $this->assertSame('sess_abc123', $context->get('sessionId'));
    }

    public function testDoesNotOverrideExistingConditionTypes(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_1');

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn(1);

        $customConditions = ['payment_authorized', 'fraud_check'];
        $context = new EventContext([
            'source' => 'acp',
            'acp_buyer' => ['email' => 'test@example.com'],
            'acp_items' => [['id' => 'a1', 'quantity' => 1]],
            'conditionTypes' => $customConditions,
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler(
            resolveUserReturn: $user,
            buildBasketReturn: $basket
        );
        $handler->handle($event);

        $this->assertSame($customConditions, $context->get('conditionTypes'));
    }

    public function testPriority200(): void
    {
        $handler = $this->createTestableHandler();
        $this->assertSame(200, $handler->getPriority());
    }

    public function testHandlesCorrectEvent(): void
    {
        $this->assertSame(
            StripeCheckoutSessionRequestEvent::class,
            AcpContextResolverHandler::getHandledEventClass()
        );
    }

    public function testThrowsWhenBuyerEmailMissing(): void
    {
        $context = new EventContext([
            'source' => 'acp',
            'acp_buyer' => ['first_name' => 'NoEmail'],
            'acp_items' => [['id' => 'a1', 'quantity' => 1]],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Use testable handler without mocked user — triggers resolveUser → parent
        $handler = new AcpContextResolverHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ACP buyer email is required');
        $handler->handle($event);
    }

    public function testPassesFulfillmentAddressToResolver(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_with_address');

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn(1);

        $address = [
            'line_one' => 'Hugo Boss str. 23',
            'city' => 'Frankfurt',
            'country' => 'DE',
            'postal_code' => '68386',
        ];
        $context = new EventContext([
            'source' => 'acp',
            'acp_buyer' => ['email' => 'test@example.com'],
            'acp_items' => [['id' => 'a1', 'quantity' => 1]],
            'acp_fulfillment_address' => $address,
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler(
            resolveUserReturn: $user,
            buildBasketReturn: $basket
        );
        $handler->handle($event);

        $this->assertSame($address, $handler->lastResolvedAddress);
        $this->assertSame('user_with_address', $context->get('userId'));
    }

    public function testThrowsWhenUserIdEmptyAfterResolution(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('');

        $basket = $this->createMock(Basket::class);

        $context = new EventContext([
            'source' => 'acp',
            'acp_buyer' => ['email' => 'fail@example.com'],
            'acp_items' => [['id' => 'a1', 'quantity' => 1]],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler(
            resolveUserReturn: $user,
            buildBasketReturn: $basket
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ACP checkout failed: could not resolve or create user');
        $handler->handle($event);
    }

    public function testThrowsWhenUserIdNullAfterResolution(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(null);

        $basket = $this->createMock(Basket::class);

        $context = new EventContext([
            'source' => 'acp',
            'acp_buyer' => ['email' => 'null@example.com'],
            'acp_items' => [['id' => 'a1', 'quantity' => 1]],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler(
            resolveUserReturn: $user,
            buildBasketReturn: $basket
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ACP checkout failed: could not resolve or create user');
        $handler->handle($event);
    }

    public function testSetsSession(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_session');

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn(1);

        $context = new EventContext([
            'source' => 'acp',
            'acp_buyer' => ['email' => 'test@example.com'],
            'acp_items' => [['id' => 'a1', 'quantity' => 1]],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler(
            resolveUserReturn: $user,
            buildBasketReturn: $basket
        );
        $handler->handle($event);

        $this->assertTrue($handler->sessionSet);
    }

    public function testPassesBuyerDataToResolver(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_buyer');

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn(1);

        $buyer = [
            'email' => 'marc@example.com',
            'first_name' => 'Marc',
            'last_name' => 'Muster',
        ];
        $context = new EventContext([
            'source' => 'acp',
            'acp_buyer' => $buyer,
            'acp_items' => [['id' => 'a1', 'quantity' => 1]],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler(
            resolveUserReturn: $user,
            buildBasketReturn: $basket
        );
        $handler->handle($event);

        $this->assertSame($buyer, $handler->lastResolvedBuyer);
    }

    public function testHandlesEmptyFulfillmentAddress(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_no_addr');

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn(1);

        $context = new EventContext([
            'source' => 'acp',
            'acp_buyer' => ['email' => 'test@example.com'],
            'acp_items' => [['id' => 'a1', 'quantity' => 1]],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createTestableHandler(
            resolveUserReturn: $user,
            buildBasketReturn: $basket
        );
        $handler->handle($event);

        $this->assertSame([], $handler->lastResolvedAddress);
        $this->assertSame('user_no_addr', $context->get('userId'));
    }

    public function testUsesCorrectStripePaymentId(): void
    {
        $reflection = new \ReflectionClass(AcpContextResolverHandler::class);
        $constant = $reflection->getReflectionConstant('PAYMENT_ID');
        $this->assertNotFalse($constant);
        $this->assertSame(
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            $constant->getValue(),
            'PAYMENT_ID must match StripeDefinitions::STRIPE_WALLET_PAYMENT_ID'
        );
    }

    private function createTestableHandler(
        ?User $resolveUserReturn = null,
        ?Basket $buildBasketReturn = null,
        string $sessionId = 'test_session_id'
    ): TestableAcpContextResolverHandler {
        return new TestableAcpContextResolverHandler(
            resolveUserReturn: $resolveUserReturn,
            buildBasketReturn: $buildBasketReturn,
            sessionId: $sessionId
        );
    }
}
