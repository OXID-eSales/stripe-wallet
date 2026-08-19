<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 133 · Story 9 (F9).
 *
 * extractAmountInCurrencyUnits() returned `0.0` for a missing or malformed
 * amount, so "field absent", "field not an integer" and "genuinely zero" were
 * one value. Downstream that wrote a 0.00 captured/refunded amount onto the
 * contract, made CapturableAmount report the FULL amount as still capturable,
 * and produced audit rows recording a 0.00 movement for a real one.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(StripeWebhookEventParser::class)]
final class StripeWebhookEventParserAmountTest extends TestCase
{
    private StripeWebhookEventParser $parser;

    protected function setUp(): void
    {
        $this->parser = new StripeWebhookEventParser();
    }

    /** @param array<string, mixed> $object */
    private function event(array $object): WebhookEvent
    {
        return new WebhookEvent(id: 'evt_1', type: 'charge.refunded', data: ['object' => $object], created: 1700000000);
    }

    public function testReturnsNullWhenTheFieldIsAbsent(): void
    {
        $this->assertNull(
            $this->parser->extractAmountInCurrencyUnits($this->event(['currency' => 'eur']), 'amount_refunded'),
            'An absent amount is unknown, not zero.'
        );
    }

    public function testReturnsNullWhenTheFieldIsNotAnInteger(): void
    {
        $event = $this->event(['amount_refunded' => '2500', 'currency' => 'eur']);

        $this->assertNull($this->parser->extractAmountInCurrencyUnits($event, 'amount_refunded'));
    }

    public function testReturnsZeroForAGenuineZeroAmount(): void
    {
        $event = $this->event(['amount_refunded' => 0, 'currency' => 'eur']);

        $this->assertSame(0.0, $this->parser->extractAmountInCurrencyUnits($event, 'amount_refunded'));
    }

    public function testConvertsTwoDecimalCurrency(): void
    {
        $event = $this->event(['amount_refunded' => 2550, 'currency' => 'eur']);

        $this->assertSame(25.5, $this->parser->extractAmountInCurrencyUnits($event, 'amount_refunded'));
    }

    public function testConvertsZeroDecimalCurrencyWithoutDividing(): void
    {
        $event = $this->event(['amount_refunded' => 1000, 'currency' => 'jpy']);

        $this->assertSame(1000.0, $this->parser->extractAmountInCurrencyUnits($event, 'amount_refunded'));
    }
}
