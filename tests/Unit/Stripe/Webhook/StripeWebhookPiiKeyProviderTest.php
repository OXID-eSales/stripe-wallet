<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookPayloadSanitizer;
use OxidEsales\PaymentBase\Webhook\WebhookPiiKeyProvider;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookPiiKeyProvider;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(StripeWebhookPiiKeyProvider::class)]
final class StripeWebhookPiiKeyProviderTest extends TestCase
{
    public function testItIsAWebhookPiiKeyProvider(): void
    {
        $this->assertInstanceOf(WebhookPiiKeyProvider::class, new StripeWebhookPiiKeyProvider());
    }

    public function testItNamesTheStripeKeysThatCarryPersonalData(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'customer_details', 'customer_email', 'customer_name', 'shipping', 'billing_details',
                'receipt_email', 'metadata', 'last4', 'exp_month', 'exp_year',
            ],
            (new StripeWebhookPiiKeyProvider())->piiKeys()
        );
    }

    public function testTheSanitizerRedactsThoseKeysOfAStripePayload(): void
    {
        $sanitizer = new WebhookPayloadSanitizer([new StripeWebhookPiiKeyProvider()]);

        $sanitized = $sanitizer->sanitize([
            'id' => 'evt_1',
            'data' => ['object' => [
                'id' => 'cs_1',
                'metadata' => ['contract_id' => 'c1'],
                'payment_method_details' => ['card' => ['last4' => '4242', 'brand' => 'visa']],
            ]],
        ]);

        $this->assertSame('evt_1', $sanitized['id']);
        $this->assertSame('[REDACTED]', $sanitized['data']['object']['metadata']);
        $this->assertSame('[REDACTED]', $sanitized['data']['object']['payment_method_details']['card']['last4']);
        $this->assertSame('visa', $sanitized['data']['object']['payment_method_details']['card']['brand']);
    }
}
