<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutSessionHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidSolutionCatalysts\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\DTO\CheckoutSessionResult;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\TokenServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for StripeCheckoutSessionHandler.
 *
 * Sprint 21: Refactored tests for handler with CheckoutSessionService delegation.
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutSessionHandler
 */
class StripeCheckoutSessionHandlerTest extends TestCase
{
    private CheckoutSessionServiceInterface&MockObject $checkoutSessionService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private TokenServiceInterface&MockObject $tokenService;

    protected function setUp(): void
    {
        $this->checkoutSessionService = $this->createMock(CheckoutSessionServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->tokenService = $this->createMock(TokenServiceInterface::class);

        // Default token generation behavior
        $this->tokenService
            ->method('generateToken')
            ->willReturnCallback(fn($contractId) => 'token_for_' . $contractId);

        // Default: service builds success URL
        $this->checkoutSessionService
            ->method('buildSuccessUrl')
            ->willReturnCallback(function ($shopUrl, $contractId, $token) {
                return $shopUrl . 'index.php?cl=order&fnc=checkoutSuccess'
                    . '&session_id={CHECKOUT_SESSION_ID}'
                    . '&contract_id=' . urlencode($contractId)
                    . '&contract_token=' . urlencode($token);
            });
    }

    private function createHandler(): StripeCheckoutSessionHandler
    {
        return new StripeCheckoutSessionHandler(
            $this->checkoutSessionService,
            $this->contractRepository,
            $this->tokenService
        );
    }

    public function testHandlerIgnoresNonStripeCheckoutSessionRequestEvent(): void
    {
        $handler = $this->createHandler();

        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        // Should not throw, just return early
        $this->contractRepository->expects($this->never())->method('save');

        $handler->handle($otherEvent);
    }

    public function testCreatesStripeCheckoutSession(): void
    {
        $contract = $this->createContractMock('contract_123');
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->checkoutSessionService
            ->expects($this->once())
            ->method('createSession')
            ->willReturn(CheckoutSessionResult::success(
                'cs_test_abc123',
                'https://checkout.stripe.com/pay/cs_test_abc123'
            ));

        $handler = $this->createHandler();

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $handler->handle($event);

        $this->assertEquals('cs_test_abc123', $context->get('checkoutSessionId'));
    }

    public function testDelegatesSessionCreationToService(): void
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [
                ['title' => 'Product 1', 'unitPrice' => 19.99, 'quantity' => 2],
            ],
            'discounts' => [],
            'totalGross' => 39.98,
            'totalNet' => 33.60,
            'totalVat' => 6.38,
            'currency' => 'EUR',
        ]);

        $contract = $this->createContractMock('contract_xyz', $basketSnapshot);
        $context = $this->createContextWithContract($contract);
        $context->set('shopId', '42');
        $context->set('captureMode', 'manual');
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Verify service is called with correct parameters
        $this->checkoutSessionService
            ->expects($this->once())
            ->method('createSession')
            ->with(
                'contract_xyz',
                $basketSnapshot,
                $this->stringContains('checkoutSuccess'),
                $this->stringContains('cl=payment'),
                '42',
                'manual'
            )
            ->willReturn(CheckoutSessionResult::success('cs_test_456', 'https://checkout.stripe.com/pay/cs_test_456'));

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testDoesNotCreateOrder(): void
    {
        $contract = $this->createContractMock('contract_789');
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->checkoutSessionService
            ->method('createSession')
            ->willReturn(CheckoutSessionResult::success('cs_test_789', 'https://checkout.stripe.com/pay/cs_test_789'));

        $handler = $this->createHandler();
        $handler->handle($event);

        // Verify no order ID is set in context
        $this->assertNull($context->get('orderId'));
    }

    public function testSetsCheckoutSessionIdInContext(): void
    {
        $contract = $this->createContractMock('contract_context');
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->checkoutSessionService
            ->method('createSession')
            ->willReturn(CheckoutSessionResult::success(
                'cs_test_context123',
                'https://checkout.stripe.com/pay/cs_test_context123'
            ));

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertEquals('cs_test_context123', $context->get('checkoutSessionId'));
        $this->assertEquals('https://checkout.stripe.com/pay/cs_test_context123', $context->get('checkoutUrl'));
    }

    public function testStoresSessionIdInContract(): void
    {
        $contract = $this->createContractMock('contract_store');
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->checkoutSessionService
            ->method('createSession')
            ->willReturn(CheckoutSessionResult::success(
                'cs_test_store456',
                'https://checkout.stripe.com/pay/cs_test_store456'
            ));

        // setProvider is used to store session ID as providerOrderId
        $contract->expects($this->once())
            ->method('setProvider')
            ->with('stripe', 'cs_test_store456', $this->anything());

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testReturnsCorrectHandledEventClass(): void
    {
        $this->assertEquals(
            StripeCheckoutSessionRequestEvent::class,
            StripeCheckoutSessionHandler::getHandledEventClass()
        );
    }

    public function testUsesCaptureModeFromContext(): void
    {
        $contract = $this->createContractMock('contract_capture');
        $context = $this->createContextWithContract($contract);
        $context->set('captureMode', 'manual');
        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->checkoutSessionService
            ->expects($this->once())
            ->method('createSession')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'manual'
            )
            ->willReturn(CheckoutSessionResult::success('cs_test_capture', 'https://checkout.stripe.com/pay/cs_test_capture'));

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testThrowsExceptionWhenContractMissing(): void
    {
        $context = new EventContext([]);
        // Contract is NOT set
        $event = new StripeCheckoutSessionRequestEvent($context);

        $handler = $this->createHandler();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Contract not found in context');

        $handler->handle($event);
    }

    public function testThrowsExceptionWhenSessionCreationFails(): void
    {
        $contract = $this->createContractMock('contract_fail');
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->checkoutSessionService
            ->method('createSession')
            ->willReturn(CheckoutSessionResult::failure('Invalid API key', 'invalid_api_key'));

        $handler = $this->createHandler();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create checkout session: Invalid API key');

        $handler->handle($event);
    }

    // =========================================================================
    // Contract Token in URL Tests (Sprint 1 - Session Restoration)
    // =========================================================================

    public function testSuccessUrlContainsContractToken(): void
    {
        $contract = $this->createContractMock('contract_token_test');
        $context = $this->createContextWithContract($contract);
        $context->set('shopUrl', 'https://shop.example.com/');
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Capture the success URL passed to service
        $capturedSuccessUrl = null;
        $this->checkoutSessionService
            ->expects($this->once())
            ->method('createSession')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($successUrl) use (&$capturedSuccessUrl) {
                    $capturedSuccessUrl = $successUrl;
                    return true;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(CheckoutSessionResult::success('cs_test_token', 'https://checkout.stripe.com/pay/cs_test_token'));

        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert success URL contains contract_token
        $this->assertNotNull($capturedSuccessUrl);
        $this->assertStringContainsString('contract_token=', $capturedSuccessUrl);
        $this->assertStringContainsString('token_for_contract_token_test', $capturedSuccessUrl);
    }

    public function testSuccessUrlContainsContractId(): void
    {
        $contract = $this->createContractMock('contract_id_in_url');
        $context = $this->createContextWithContract($contract);
        $context->set('shopUrl', 'https://shop.example.com/');
        $event = new StripeCheckoutSessionRequestEvent($context);

        $capturedSuccessUrl = null;
        $this->checkoutSessionService
            ->expects($this->once())
            ->method('createSession')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($successUrl) use (&$capturedSuccessUrl) {
                    $capturedSuccessUrl = $successUrl;
                    return true;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(CheckoutSessionResult::success('cs_test_id', 'https://checkout.stripe.com/pay/cs_test_id'));

        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert success URL contains contract_id
        $this->assertNotNull($capturedSuccessUrl);
        $this->assertStringContainsString('contract_id=contract_id_in_url', $capturedSuccessUrl);
    }

    public function testCancelUrlDoesNotContainToken(): void
    {
        $contract = $this->createContractMock('contract_cancel');
        $context = $this->createContextWithContract($contract);
        $context->set('shopUrl', 'https://shop.example.com/');
        $event = new StripeCheckoutSessionRequestEvent($context);

        $capturedCancelUrl = null;
        $this->checkoutSessionService
            ->expects($this->once())
            ->method('createSession')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function ($cancelUrl) use (&$capturedCancelUrl) {
                    $capturedCancelUrl = $cancelUrl;
                    return true;
                }),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(CheckoutSessionResult::success('cs_test_cancel', 'https://checkout.stripe.com/pay/cs_test_cancel'));

        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert cancel URL does NOT contain contract_token
        $this->assertNotNull($capturedCancelUrl);
        $this->assertStringNotContainsString('contract_token', $capturedCancelUrl);
    }

    // --- Helper methods ---

    private function createContractMock(string $contractId, ?BasketSnapshot $snapshot = null): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);

        if ($snapshot === null) {
            $snapshot = BasketSnapshot::fromArray([
                'items' => [['title' => 'Test', 'unitPrice' => 10.00, 'quantity' => 1]],
                'discounts' => [],
                'totalGross' => 10.00,
                'totalNet' => 8.40,
                'totalVat' => 1.60,
                'currency' => 'EUR',
            ]);
        }

        $contract->method('getBasketSnapshot')->willReturn($snapshot);

        return $contract;
    }

    private function createContextWithContract(PaymentContractInterface $contract): EventContext
    {
        $context = new EventContext([
            'shopId' => 1,
            'userId' => 'user_123',
        ]);
        $context->setContract($contract);

        return $context;
    }
}
