<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Webhook\Handler\CheckoutSessionExpiredWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Webhook\Handler\CheckoutSessionExpiredWebhookHandler::class)]
final class CheckoutSessionExpiredWebhookHandlerTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private CheckoutSessionExpiredWebhookHandler $handler;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);

        $this->handler = new CheckoutSessionExpiredWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillmentHandler,
            $this->createMock(ContractRepositoryInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function supportsCheckoutSessionExpiredEventType(): void
    {
        $this->assertTrue($this->handler->supports('checkout.session.expired'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNotSupportOtherEventTypes(): void
    {
        $this->assertFalse($this->handler->supports('checkout.session.completed'));
        $this->assertFalse($this->handler->supports('payment_intent.succeeded'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function noContractIdInMetadata_returnsSkipped(): void
    {
        $event = $this->makeEvent(null);

        $outcome = $this->handler->handle($event);

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('No contract ID in session metadata', $outcome->result->error);
        $this->assertNull($outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsTrue_returnsSessionExpiredWithContractId(): void
    {
        $this->fulfillmentHandler->method('handleSessionExpired')->with('ctr-expiry')->willReturn(true);

        $outcome = $this->handler->handle($this->makeEvent('ctr-expiry'));

        $this->assertSame('session_expired', $outcome->result->action);
        $this->assertSame('ctr-expiry', $outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsFalse_returnsSkippedTerminalState(): void
    {
        $this->fulfillmentHandler->method('handleSessionExpired')->willReturn(false);

        $outcome = $this->handler->handle($this->makeEvent('ctr-terminal'));

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('Contract already in terminal state', $outcome->result->error);
        $this->assertNull($outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsNull_returnsContractNotFoundSkipped(): void
    {
        $this->fulfillmentHandler->method('handleSessionExpired')->willReturn(null);

        $outcome = $this->handler->handle($this->makeEvent('ctr-missing'));

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('Contract not found', $outcome->result->error);
        $this->assertNull($outcome->contractId);
    }

    private function makeEvent(?string $contractId): WebhookEvent
    {
        $objectData = ['id' => 'cs_exp_test'];

        if ($contractId !== null) {
            $objectData['metadata'] = ['contract_id' => $contractId];
        }

        return new WebhookEvent(
            id: 'evt_cs_exp_' . substr(md5($contractId ?? 'null'), 0, 8),
            type: 'checkout.session.expired',
            data: ['object' => $objectData],
            created: time()
        );
    }
}
