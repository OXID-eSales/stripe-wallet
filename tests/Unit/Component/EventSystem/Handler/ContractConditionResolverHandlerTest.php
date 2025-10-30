<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\ContractConditionResolverHandler;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepository;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use PHPUnit\Framework\TestCase;

class ContractConditionResolverHandlerTest extends TestCase
{
    private ContractRepository $repository;
    private EventDispatcher $dispatcher;
    private ContractConditionResolverHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new ContractRepository();
        $this->dispatcher = new EventDispatcher();
        $this->handler = new ContractConditionResolverHandler(
            $this->repository,
            $this->dispatcher
        );
    }

    private function createTestContract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_FRAUD_CHECK));

        return $contract;
    }

    public function testTransitionsContractToPending(): void
    {
        $contract = $this->createTestContract();
        $this->repository->save($contract);

        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->handler->handle($event);

        $updated = $this->repository->findById($contract->getId());
        $this->assertTrue($updated->getState()->isPending());
    }

    public function testEmitsPendingEvent(): void
    {
        $eventEmitted = false;
        $emittedContract = null;

        $this->dispatcher->addListener(
            ContractTransitionedToPendingEvent::class,
            function (ContractTransitionedToPendingEvent $event) use (&$eventEmitted, &$emittedContract) {
                $eventEmitted = true;
                $emittedContract = $event->getContract();
            }
        );

        $contract = $this->createTestContract();
        $this->repository->save($contract);

        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->handler->handle($event);

        $this->assertTrue($eventEmitted);
        $this->assertNotNull($emittedContract);
        $this->assertTrue($emittedContract->getState()->isPending());
    }

    public function testThrowsExceptionWhenNoConditions(): void
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $this->repository->save($contract);

        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot transition to PENDING without conditions');

        $this->handler->handle($event);
    }

    public function testThrowsExceptionWhenAlreadyPending(): void
    {
        $contract = $this->createTestContract();
        $contract->transitionToPending();
        $this->repository->save($contract);

        $context = new EventContext(['test' => 'data']);
        $event = new ContractCreatedEvent($contract, $context);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Can only transition to PENDING from DRAFT state');

        $this->handler->handle($event);
    }
}
