<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\PaymentAuthorizationHandler;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepository;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use PHPUnit\Framework\TestCase;

class PaymentAuthorizationHandlerTest extends TestCase
{
    private ContractRepository $repository;
    private EventDispatcher $dispatcher;
    private PaymentAuthorizationHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new ContractRepository();
        $this->dispatcher = new EventDispatcher();
        $this->handler = new PaymentAuthorizationHandler(
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
        $contract->transitionToPending();

        return $contract;
    }

    public function testFulfillsPaymentAuthorizedCondition(): void
    {
        $contract = $this->createTestContract();
        $this->repository->save($contract);

        $context = new EventContext([
            'authorizationId' => 'auth_123',
            'providerOrderId' => 'pi_456',
        ]);

        $event = new ContractTransitionedToPendingEvent(
            $contract,
            $context,
            $contract->getConditions()
        );

        $this->handler->handle($event);

        $updated = $this->repository->findById($contract->getId());
        $conditions = $updated->getConditions();

        $this->assertTrue($conditions[0]->isFulfilled());
    }

    public function testSetsProviderOrderId(): void
    {
        $contract = $this->createTestContract();
        $this->repository->save($contract);

        $context = new EventContext([
            'authorizationId' => 'auth_123',
            'providerOrderId' => 'pi_456',
        ]);

        $event = new ContractTransitionedToPendingEvent(
            $contract,
            $context,
            $contract->getConditions()
        );

        $this->handler->handle($event);

        $updated = $this->repository->findById($contract->getId());

        $this->assertEquals('pi_456', $updated->getProviderOrderId());
    }

    public function testEmitsReadyToCommitWhenAllFulfilled(): void
    {
        $eventEmitted = false;

        $this->dispatcher->addListener(
            ContractReadyToCommitEvent::class,
            function () use (&$eventEmitted) {
                $eventEmitted = true;
            }
        );

        $contract = $this->createTestContract();
        $this->repository->save($contract);

        $context = new EventContext([
            'authorizationId' => 'auth_123',
            'providerOrderId' => 'pi_456',
        ]);

        $event = new ContractTransitionedToPendingEvent(
            $contract,
            $context,
            $contract->getConditions()
        );

        $this->handler->handle($event);

        $this->assertTrue($eventEmitted);
    }

    public function testDoesNotEmitWhenOtherConditionsPending(): void
    {
        $eventEmitted = false;

        $this->dispatcher->addListener(
            ContractReadyToCommitEvent::class,
            function () use (&$eventEmitted) {
                $eventEmitted = true;
            }
        );

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
        $contract->transitionToPending();
        $this->repository->save($contract);

        $context = new EventContext([
            'authorizationId' => 'auth_123',
            'providerOrderId' => 'pi_456',
        ]);

        $event = new ContractTransitionedToPendingEvent(
            $contract,
            $context,
            $contract->getConditions()
        );

        $this->handler->handle($event);

        $this->assertFalse($eventEmitted);
    }
}
