<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCancelAuthorizationRequestHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use PHPUnit\Framework\TestCase;
use Stripe\PaymentIntent;

class StripeCancelAuthorizationRequestHandlerTest extends TestCase
{
    private StripeAdapterInterface $stripeAdapter;

    protected function setUp(): void
    {
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
    }

    /**
     * Create a PaymentIntent object for testing.
     */
    private function createPaymentIntent(string $status = 'canceled', string $id = 'pi_test'): PaymentIntent
    {
        return PaymentIntent::constructFrom([
            'id' => $id,
            'object' => 'payment_intent',
            'status' => $status,
        ], null);
    }

    public function testHandlerIgnoresNonCancelAuthorizationEvent(): void
    {
        $handler = new StripeCancelAuthorizationRequestHandler(
            $this->stripeAdapter
        );

        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        $this->stripeAdapter->expects($this->never())->method('cancelPaymentIntent');

        $handler->handle($otherEvent);
    }

    public function testHandlerRejectsEmptyPaymentIntentId(): void
    {
        $context = new EventContext([
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $handler = new StripeCancelAuthorizationRequestHandler(
            $this->stripeAdapter
        );

        $this->stripeAdapter->expects($this->never())->method('cancelPaymentIntent');

        $handler->handle($event);

        $this->assertFalse($context->get('cancelSuccess'));
        // Verify exact error message to catch mutations
        $this->assertEquals('PaymentIntent ID is missing', $context->get('error'));
    }

    /**
     * Test that empty string PaymentIntent ID also triggers error.
     * This catches mutations that change === '' to !== ''
     */
    public function testHandlerRejectsEmptyStringPaymentIntentId(): void
    {
        $context = new EventContext([
            'paymentIntentId' => '',  // Empty string, not null
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $handler = new StripeCancelAuthorizationRequestHandler(
            $this->stripeAdapter
        );

        $this->stripeAdapter->expects($this->never())->method('cancelPaymentIntent');

        $handler->handle($event);

        $this->assertFalse($context->get('cancelSuccess'));
        $this->assertEquals('PaymentIntent ID is missing', $context->get('error'));
    }

    public function testHandlerCancelsPaymentIntentViaStripeApi(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_cancel_123',
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        // @phpstan-ignore-next-line
        $this->stripeAdapter
            ->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_test_cancel_123', 'requested_by_customer')
            ->willReturn($this->createPaymentIntent('canceled'));

        $handler = new StripeCancelAuthorizationRequestHandler(
            $this->stripeAdapter
        );

        $handler->handle($event);

        $this->assertTrue($context->get('cancelSuccess'));
        $this->assertEquals('pi_test_cancel_123', $context->get('cancelledPaymentIntentId'));
    }

    public function testHandlerSetsSuccessInContext(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_success',
            'cancellationReason' => 'duplicate',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        // @phpstan-ignore-next-line
        $this->stripeAdapter
            ->method('cancelPaymentIntent')
            ->willReturn($this->createPaymentIntent('canceled'));

        $handler = new StripeCancelAuthorizationRequestHandler(
            $this->stripeAdapter
        );

        $handler->handle($event);

        $this->assertTrue($context->get('cancelSuccess'));
        $this->assertEquals('canceled', $context->get('cancelledStatus'));
    }

    public function testHandlerSetsErrorOnApiFailure(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_fail',
            'cancellationReason' => 'fraudulent',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->stripeAdapter
            ->method('cancelPaymentIntent')
            ->willThrowException(new \Exception('Stripe API error: Cannot cancel'));

        $handler = new StripeCancelAuthorizationRequestHandler(
            $this->stripeAdapter
        );

        $handler->handle($event);

        $this->assertFalse($context->get('cancelSuccess'));
        $this->assertStringContainsString('Cannot cancel', $context->get('error'));
    }

    public function testHandlerPassesCancellationReasonToAdapter(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_reason',
            'cancellationReason' => 'abandoned',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        // @phpstan-ignore-next-line
        $this->stripeAdapter
            ->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_test_reason', 'abandoned')
            ->willReturn($this->createPaymentIntent('canceled'));

        $handler = new StripeCancelAuthorizationRequestHandler(
            $this->stripeAdapter
        );

        $handler->handle($event);
    }

    public function testReturnsCorrectHandledEventClass(): void
    {
        $this->assertEquals(
            StripeCancelAuthorizationRequestEvent::class,
            StripeCancelAuthorizationRequestHandler::getHandledEventClass()
        );
    }

    public function testHandlerWorksWithNullCancellationReason(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_no_reason',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        // @phpstan-ignore-next-line
        $this->stripeAdapter
            ->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_test_no_reason', null)
            ->willReturn($this->createPaymentIntent('canceled'));

        $handler = new StripeCancelAuthorizationRequestHandler(
            $this->stripeAdapter
        );

        $handler->handle($event);

        $this->assertTrue($context->get('cancelSuccess'));
    }
}
