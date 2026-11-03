<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefund;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(StripeWebhookEventParser::class)]
final class StripeWebhookEventParserRefundsTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function aRefundCarriesItsCurrency(): void
    {
        $event = $this->event(['currency' => 'eur', 'refunds' => ['has_more' => false, 'data' => [
            ['id' => 're_1', 'amount' => 1250, 'status' => 'succeeded'],
        ]]]);

        $this->assertEquals([new ChargeRefund('re_1', 12.5, 'EUR', true)], (new StripeWebhookEventParser())->extractRefunds($event));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aPendingRefundIsNotYetSucceeded(): void
    {
        $event = $this->event(['currency' => 'eur', 'refunds' => ['has_more' => false, 'data' => [
            ['id' => 're_2', 'amount' => 500, 'status' => 'pending'],
        ]]]);

        $this->assertEquals([new ChargeRefund('re_2', 5.0, 'EUR', false)], (new StripeWebhookEventParser())->extractRefunds($event));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aCompleteListIsRelied_on(): void
    {
        $event = $this->event(['refunds' => ['has_more' => false, 'data' => []]]);

        $this->assertTrue((new StripeWebhookEventParser())->listsEveryRefund($event));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aListCutShortIsNotRelied_on(): void
    {
        $event = $this->event(['refunds' => ['has_more' => true, 'data' => [['id' => 're_1', 'amount' => 1]]]]);

        $this->assertFalse((new StripeWebhookEventParser())->listsEveryRefund($event));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anEventWithoutAListIsNotRelied_on(): void
    {
        $this->assertFalse((new StripeWebhookEventParser())->listsEveryRefund($this->event(['id' => 'ch_1'])));
    }

    /**
     * @param array<string, mixed> $charge
     */
    private function event(array $charge): WebhookEvent
    {
        return new WebhookEvent('evt_1', 'charge.refunded', ['object' => $charge + ['id' => 'ch_1']], time());
    }
}
