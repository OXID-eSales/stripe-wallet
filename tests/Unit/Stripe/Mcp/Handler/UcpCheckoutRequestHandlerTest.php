<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Handler;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\Payments\Stripe\Mcp\Event\UcpCheckoutRequestEvent;
use OxidEsales\Payments\Stripe\Mcp\Handler\UcpCheckoutRequestHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UcpCheckoutRequestHandler.
 *
 * This handler acts as a REST-style router, mapping HTTP method + path segments
 * to AcpCheckoutServiceInterface method calls. It reads from EventContext and
 * writes httpStatusCode + responseData back to the context.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Handler\UcpCheckoutRequestHandler
 */
class UcpCheckoutRequestHandlerTest extends TestCase
{
    private AcpCheckoutServiceInterface&MockObject $checkoutService;

    protected function setUp(): void
    {
        $this->checkoutService = $this->createMock(AcpCheckoutServiceInterface::class);
    }

    private function createHandler(): UcpCheckoutRequestHandler
    {
        return new UcpCheckoutRequestHandler($this->checkoutService);
    }

    private function createEvent(
        string $httpMethod,
        array $pathSegments,
        array $requestBody = [],
        ?AgentContext $agentContext = null
    ): UcpCheckoutRequestEvent {
        $agentContext ??= new AgentContext('agent_test', 'token_test');

        $context = new EventContext([
            'httpMethod' => $httpMethod,
            'pathSegments' => $pathSegments,
            'requestBody' => $requestBody,
            'agentContext' => $agentContext,
        ]);

        return new UcpCheckoutRequestEvent($context);
    }

    public function testImplementsHandlerInterface(): void
    {
        $handler = $this->createHandler();

        $this->assertInstanceOf(HandlerInterface::class, $handler);
    }

    public function testGetHandledEventClassReturnsCorrectClass(): void
    {
        $this->assertSame(
            UcpCheckoutRequestEvent::class,
            UcpCheckoutRequestHandler::getHandledEventClass()
        );
    }

    public function testPostWithOneSegmentCreatesCheckout(): void
    {
        $agentContext = new AgentContext('agent_create', 'token_create');
        $requestBody = [
            'items' => [['product_id' => 'prod_1', 'quantity' => 2]],
            'currency' => 'EUR',
        ];

        $expectedResponse = ['id' => 'checkout_new_123', 'status' => 'draft'];

        $this->checkoutService
            ->expects($this->once())
            ->method('createCheckout')
            ->with($requestBody, $agentContext)
            ->willReturn($expectedResponse);

        $event = $this->createEvent('POST', ['checkouts'], $requestBody, $agentContext);
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(201, $context->get('httpStatusCode'));
        $this->assertSame($expectedResponse, $context->get('responseData'));
    }

    public function testGetWithTwoSegmentsGetsCheckout(): void
    {
        $checkoutId = 'checkout_abc123';
        $expectedResponse = ['id' => $checkoutId, 'status' => 'pending'];

        $this->checkoutService
            ->expects($this->once())
            ->method('getCheckout')
            ->with($checkoutId)
            ->willReturn($expectedResponse);

        $event = $this->createEvent('GET', ['checkouts', $checkoutId]);
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(200, $context->get('httpStatusCode'));
        $this->assertSame($expectedResponse, $context->get('responseData'));
    }

    public function testPutWithTwoSegmentsUpdatesCheckout(): void
    {
        $checkoutId = 'checkout_update_456';
        $agentContext = new AgentContext('agent_update', 'token_update');
        $updateData = ['selected_fulfillment_option_id' => 'shipping_express'];
        $expectedResponse = ['id' => $checkoutId, 'status' => 'pending'];

        $this->checkoutService
            ->expects($this->once())
            ->method('updateCheckout')
            ->with($checkoutId, $updateData, $agentContext)
            ->willReturn($expectedResponse);

        $event = $this->createEvent('PUT', ['checkouts', $checkoutId], $updateData, $agentContext);
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(200, $context->get('httpStatusCode'));
        $this->assertSame($expectedResponse, $context->get('responseData'));
    }

    public function testPostCompleteRoute(): void
    {
        $checkoutId = 'checkout_complete_789';
        $agentContext = new AgentContext('agent_complete', 'token_complete');
        $requestBody = [
            'payment_data' => [
                'token' => 'spt_granted_abc',
                'provider' => 'stripe',
            ],
        ];
        $expectedResponse = ['id' => 'order_completed_123', 'status' => 'completed'];

        $this->checkoutService
            ->expects($this->once())
            ->method('completeCheckout')
            ->with(
                $checkoutId,
                $requestBody['payment_data'],
                $agentContext
            )
            ->willReturn($expectedResponse);

        $event = $this->createEvent(
            'POST',
            ['checkouts', $checkoutId, 'complete'],
            $requestBody,
            $agentContext
        );
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(200, $context->get('httpStatusCode'));
        $this->assertSame($expectedResponse, $context->get('responseData'));
    }

    public function testPostCompleteWithMissingPaymentDataPassesEmptyArray(): void
    {
        $checkoutId = 'checkout_no_payment_data';
        $agentContext = new AgentContext('agent_empty', 'token_empty');
        $requestBody = [];

        $this->checkoutService
            ->expects($this->once())
            ->method('completeCheckout')
            ->with($checkoutId, [], $agentContext)
            ->willReturn(['error' => 'Payment token is required']);

        $event = $this->createEvent(
            'POST',
            ['checkouts', $checkoutId, 'complete'],
            $requestBody,
            $agentContext
        );
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(200, $context->get('httpStatusCode'));
    }

    public function testPostCancelRoute(): void
    {
        $checkoutId = 'checkout_cancel_101';
        $expectedResponse = ['id' => $checkoutId, 'status' => 'cancelled'];

        $this->checkoutService
            ->expects($this->once())
            ->method('cancelCheckout')
            ->with($checkoutId)
            ->willReturn($expectedResponse);

        $event = $this->createEvent('POST', ['checkouts', $checkoutId, 'cancel']);
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(200, $context->get('httpStatusCode'));
        $this->assertSame($expectedResponse, $context->get('responseData'));
    }

    public function testUnknownRouteReturns404(): void
    {
        $this->checkoutService
            ->expects($this->never())
            ->method($this->anything());

        $event = $this->createEvent('DELETE', ['checkouts', 'some_id']);
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(404, $context->get('httpStatusCode'));
        $responseData = $context->get('responseData');
        $this->assertSame('not_found', $responseData['error']['type']);
        $this->assertSame('Endpoint not found', $responseData['error']['message']);
    }

    public function testGetWithOneSegmentReturns404(): void
    {
        $this->checkoutService
            ->expects($this->never())
            ->method($this->anything());

        $event = $this->createEvent('GET', ['checkouts']);
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(404, $context->get('httpStatusCode'));
    }

    public function testPostWithTwoSegmentsReturns404(): void
    {
        $this->checkoutService
            ->expects($this->never())
            ->method($this->anything());

        $event = $this->createEvent('POST', ['checkouts', 'some_id']);
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(404, $context->get('httpStatusCode'));
    }

    public function testPostWithThreeSegmentsUnknownActionReturns404(): void
    {
        $this->checkoutService
            ->expects($this->never())
            ->method($this->anything());

        $event = $this->createEvent('POST', ['checkouts', 'some_id', 'unknown_action']);
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(404, $context->get('httpStatusCode'));
    }

    public function testPutWithOneSegmentReturns404(): void
    {
        $this->checkoutService
            ->expects($this->never())
            ->method($this->anything());

        $event = $this->createEvent('PUT', ['checkouts']);
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame(404, $context->get('httpStatusCode'));
    }
}
