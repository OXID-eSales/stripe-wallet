<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Webhook\Handler\ChargeDisputeCreatedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Webhook\Handler\ChargeDisputeCreatedWebhookHandler::class)]
final class ChargeDisputeCreatedWebhookHandlerTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private ChargeDisputeCreatedWebhookHandler $handler;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new ChargeDisputeCreatedWebhookHandler(
            new StripeWebhookEventParser(),
            $this->createMock(WebhookContractFulfillmentHandlerInterface::class),
            $this->createMock(ContractRepositoryInterface::class),
            $this->logger
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function supportsChargeDisputeCreatedEventType(): void
    {
        $this->assertTrue($this->handler->supports('charge.dispute.created'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNotSupportOtherEventTypes(): void
    {
        $this->assertFalse($this->handler->supports('charge.refunded'));
        $this->assertFalse($this->handler->supports('payment_intent.succeeded'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function alwaysLogsDisputeDetailsAndReturnsDisputeLoggedWithNullContractId(): void
    {
        $event = new WebhookEvent(
            id: 'evt_dp_1',
            type: 'charge.dispute.created',
            data: ['object' => [
                'id' => 'dp_abc',
                'amount' => 5000,
                'reason' => 'fraudulent',
                'charge' => 'ch_xyz',
            ]],
            created: time()
        );

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Dispute created', $this->callback(fn($ctx) => $ctx['dispute_id'] === 'dp_abc'));

        $outcome = $this->handler->handle($event);

        $this->assertTrue($outcome->result->isSuccess());
        $this->assertSame('dispute_logged', $outcome->result->action);
        $this->assertNull($outcome->contractId);
    }
}
