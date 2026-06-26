<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookPayloadSizeGuard;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\Webhook\WebhookPayloadSizeGuard::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-64a')]
#[\PHPUnit\Framework\Attributes\Group('security')]
final class WebhookPayloadSizeGuardTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function guardRejectsOversizedPayload(): void
    {
        $guard = new WebhookPayloadSizeGuard(1024);
        $oversized = str_repeat('x', 2048);

        $result = $guard->check($oversized, 'sig', '1.2.3.4');

        $this->assertNotNull($result);
        $this->assertSame(413, $result->httpStatusCode);
        $this->assertSame('payload_too_large', $result->reason);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardAllowsNormalPayload(): void
    {
        $guard = new WebhookPayloadSizeGuard(65536);
        $normal = '{"id":"evt_test","type":"payment_intent.succeeded"}';

        $this->assertNull($guard->check($normal, 'sig', '1.2.3.4'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardAllowsEmptyPayload(): void
    {
        $guard = new WebhookPayloadSizeGuard(65536);

        $this->assertNull($guard->check('', 'sig', '1.2.3.4'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardAllowsAtExactLimit(): void
    {
        $guard = new WebhookPayloadSizeGuard(100);
        $atLimit = str_repeat('a', 100);

        $this->assertNull($guard->check($atLimit, 'sig', '1.2.3.4'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardRejectsAtLimitPlusOne(): void
    {
        $guard = new WebhookPayloadSizeGuard(100);
        $overLimit = str_repeat('a', 101);

        $this->assertNotNull($guard->check($overLimit, 'sig', '1.2.3.4'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardMessageIncludesMaxSize(): void
    {
        $guard = new WebhookPayloadSizeGuard(1024);
        $oversized = str_repeat('x', 2048);

        $result = $guard->check($oversized, 'sig', '1.2.3.4');

        $this->assertStringContainsString('1024', $result->message);
    }
}
