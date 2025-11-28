<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripePaymentReturnHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripePaymentReturnEvent;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeStatusMapper;
use PHPUnit\Framework\TestCase;

class StripePaymentReturnHandlerTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    }

    public function testHandlerIgnoresNonStripePaymentReturnEvent(): void
    {
        $handler = new StripePaymentReturnHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $handler->handle($otherEvent);
    }

    public function testRetrievesPaymentIntentFromContext(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_return_123',
            'redirectStatus' => 'succeeded',
        ]);
        $event = new StripePaymentReturnEvent($context);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($dispatchedEvent) {
                return $dispatchedEvent instanceof StripePaymentExecuteEvent
                    && $dispatchedEvent->getPaymentIntentId() === 'pi_test_return_123';
            }))
            ->willReturnCallback(fn($e) => $e);

        $handler = new StripePaymentReturnHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $handler->handle($event);
    }

    public function testDispatchesStripePaymentExecuteEvent(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_dispatch',
            'redirectStatus' => 'succeeded',
        ]);
        $event = new StripePaymentReturnEvent($context);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripePaymentExecuteEvent::class))
            ->willReturnCallback(fn($e) => $e);

        $handler = new StripePaymentReturnHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $handler->handle($event);
    }

    public function testHandlesSucceededRedirectStatus(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_succeeded',
            'redirectStatus' => StripeStatusMapper::STRIPE_SUCCEEDED,
        ]);
        $event = new StripePaymentReturnEvent($context);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn($e) => $e);

        $handler = new StripePaymentReturnHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $handler->handle($event);

        // Should not have error for succeeded status
        $this->assertNull($context->get('error'));
    }

    public function testHandlesFailedRedirectStatus(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_failed',
            'redirectStatus' => 'failed',
        ]);
        $event = new StripePaymentReturnEvent($context);

        // Should NOT dispatch execute event for failed status
        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $handler = new StripePaymentReturnHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $handler->handle($event);

        $this->assertNotNull($context->get('error'));
        $this->assertEquals('payment', $context->get('redirectTarget'));
    }

    public function testSetsErrorWhenPaymentIntentMissing(): void
    {
        $context = new EventContext([
            'redirectStatus' => 'succeeded',
        ]);
        $event = new StripePaymentReturnEvent($context);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $handler = new StripePaymentReturnHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $handler->handle($event);

        $this->assertNotNull($context->get('error'));
        $this->assertEquals('payment', $context->get('redirectTarget'));
    }

    public function testReturnsCorrectHandledEventClass(): void
    {
        $this->assertEquals(
            StripePaymentReturnEvent::class,
            StripePaymentReturnHandler::getHandledEventClass()
        );
    }

    public function testPassesContractIdToExecuteEvent(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_contract',
            'redirectStatus' => 'succeeded',
            'contractId' => 'contract_xyz',
        ]);
        $event = new StripePaymentReturnEvent($context);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($dispatchedEvent) {
                return $dispatchedEvent instanceof StripePaymentExecuteEvent
                    && $dispatchedEvent->getContext()->get('contractId') === 'contract_xyz';
            }))
            ->willReturnCallback(fn($e) => $e);

        $handler = new StripePaymentReturnHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $handler->handle($event);
    }
}
