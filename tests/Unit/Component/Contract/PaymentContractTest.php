<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\Contract;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use PHPUnit\Framework\TestCase;

class PaymentContractTest extends TestCase
{
    private function createBasketSnapshot(): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testConstruct(): void
    {
        $snapshot = $this->createBasketSnapshot();
        $contract = new PaymentContract(1, 'user123', $snapshot);

        $this->assertNotNull($contract->getId());
        $this->assertEquals(1, $contract->getShopId());
        $this->assertEquals('user123', $contract->getUserId());
        $this->assertNull($contract->getOrderId());
        $this->assertTrue($contract->getState()->isDraft());
        $this->assertInstanceOf(BasketSnapshot::class, $contract->getBasketSnapshot());
        $this->assertNotNull($contract->getCreatedAt());
        $this->assertNotNull($contract->getExpiresAt());
    }

    public function testConstructWithCustomId(): void
    {
        $snapshot = $this->createBasketSnapshot();
        $contract = new PaymentContract(1, 'user123', $snapshot, 'custom_id');

        $this->assertEquals('custom_id', $contract->getId());
    }

    public function testAddCondition(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $condition = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $contract->addCondition($condition);

        $this->assertCount(1, $contract->getConditions());
    }

    public function testAddConditionAfterDraftThrowsException(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $condition = new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->addCondition($condition);
        $contract->transitionToPending();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot add conditions after DRAFT state');

        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_FRAUD_CHECK));
    }

    public function testTransitionToPendingRequiresConditions(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot transition to PENDING without conditions');

        $contract->transitionToPending();
    }

    public function testTransitionToPending(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        $contract->transitionToPending();

        $this->assertTrue($contract->getState()->isPending());
    }

    public function testTransitionToPendingOnlyFromDraft(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Can only transition to PENDING from DRAFT state');

        $contract->transitionToPending();
    }

    public function testFulfillCondition(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();

        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['authId' => '123']);

        $conditions = $contract->getConditions();
        $this->assertTrue($conditions[0]->isFulfilled());
    }

    public function testFulfillConditionAutoTransitionsToReadyToCommit(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();

        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $this->assertTrue($contract->getState()->isReadyToCommit());
    }

    public function testAreAllConditionsFulfilledWithNoConditions(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $this->assertFalse($contract->areAllConditionsFulfilled());
    }

    public function testAreAllConditionsFulfilledWithPendingConditions(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();

        $this->assertFalse($contract->areAllConditionsFulfilled());
    }

    public function testAreAllConditionsFulfilledWhenAllFulfilled(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $this->assertTrue($contract->areAllConditionsFulfilled());
    }

    public function testFailCondition(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();

        $contract->failCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, 'Payment declined');

        $this->assertTrue($contract->getState()->equals(ContractState::failed()));
    }

    public function testCommitToOrderRequiresReadyToCommitState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Contract must be in READY_TO_COMMIT state to commit');

        $contract->commitToOrder('order123');
    }

    public function testCommitToOrder(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        $contract->commitToOrder('order123');

        $this->assertEquals('order123', $contract->getOrderId());
        $this->assertTrue($contract->getState()->isCommitted());
    }

    public function testFulfillRequiresCommittedState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Contract must be COMMITTED before fulfillment');

        $contract->fulfill();
    }

    public function testFulfill(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order123');

        $contract->fulfill();

        $this->assertTrue($contract->getState()->isFulfilled());
        $this->assertNotNull($contract->getFulfilledAt());
    }

    public function testCancel(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $contract->cancel('User cancelled');

        $this->assertEquals('cancelled', $contract->getStateValue());
    }

    public function testCancelTerminalStateThrowsException(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order123');
        $contract->fulfill();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot cancel a terminal state contract');

        $contract->cancel();
    }

    public function testFail(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $contract->fail('Payment failed');

        $this->assertEquals('failed', $contract->getStateValue());
    }

    public function testExpire(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $contract->expire();

        $this->assertEquals('expired', $contract->getStateValue());
    }

    public function testIsExpiredReturnsFalseForTerminalState(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order123');
        $contract->fulfill();

        $this->assertFalse($contract->isExpired());
    }

    public function testSetProvider(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot());

        $contract->setProvider('stripe', 'pi_123456', 'https://checkout.stripe.com');

        $this->assertEquals('stripe', $contract->getProvider());
        $this->assertEquals('pi_123456', $contract->getProviderOrderId());
        $this->assertEquals('https://checkout.stripe.com', $contract->getProviderRedirectUrl());
    }

    public function testToArrayAndFromArray(): void
    {
        $contract = new PaymentContract(1, 'user123', $this->createBasketSnapshot(), 'test_id');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->setProvider('stripe', 'pi_123');

        $array = $contract->toArray();

        $this->assertEquals('test_id', $array['id']);
        $this->assertEquals(1, $array['shopId']);
        $this->assertEquals('user123', $array['userId']);
        $this->assertNull($array['orderId']);
        $this->assertEquals('draft', $array['state']);
        $this->assertIsArray($array['basketSnapshot']);
        $this->assertCount(1, $array['conditions']);
        $this->assertEquals('stripe', $array['provider']);

        $restored = PaymentContract::fromArray($array);

        $this->assertEquals($contract->getId(), $restored->getId());
        $this->assertEquals($contract->getShopId(), $restored->getShopId());
        $this->assertEquals($contract->getUserId(), $restored->getUserId());
        $this->assertEquals($contract->getStateValue(), $restored->getStateValue());
        $this->assertCount(1, $restored->getConditions());
    }
}
