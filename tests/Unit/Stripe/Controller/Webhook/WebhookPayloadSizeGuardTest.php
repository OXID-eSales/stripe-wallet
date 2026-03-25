<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookPayloadSizeGuard;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\OxidEsales\Payments\Stripe\Controller\Webhook\WebhookPayloadSizeGuard::class)]
    #[Group('sprint-64a')]
    #[Group('security')]
final class WebhookPayloadSizeGuardTest extends TestCase
{
    public function testGuardRejectsOversizedPayload(): void
    {
        $guard = new WebhookPayloadSizeGuard(1024);
        $oversized = str_repeat('x', 2048);

        $result = $guard->check($oversized, 'sig', '1.2.3.4');

        $this->assertNotNull($result);
        $this->assertSame(413, $result->httpStatusCode);
        $this->assertSame('payload_too_large', $result->reason);
    }

    public function testGuardAllowsNormalPayload(): void
    {
        $guard = new WebhookPayloadSizeGuard(65536);
        $normal = '{"id":"evt_test","type":"payment_intent.succeeded"}';

        $this->assertNull($guard->check($normal, 'sig', '1.2.3.4'));
    }

    public function testGuardAllowsEmptyPayload(): void
    {
        $guard = new WebhookPayloadSizeGuard(65536);

        $this->assertNull($guard->check('', 'sig', '1.2.3.4'));
    }

    public function testGuardAllowsAtExactLimit(): void
    {
        $guard = new WebhookPayloadSizeGuard(100);
        $atLimit = str_repeat('a', 100);

        $this->assertNull($guard->check($atLimit, 'sig', '1.2.3.4'));
    }

    public function testGuardRejectsAtLimitPlusOne(): void
    {
        $guard = new WebhookPayloadSizeGuard(100);
        $overLimit = str_repeat('a', 101);

        $this->assertNotNull($guard->check($overLimit, 'sig', '1.2.3.4'));
    }

    public function testGuardMessageIncludesMaxSize(): void
    {
        $guard = new WebhookPayloadSizeGuard(1024);
        $oversized = str_repeat('x', 2048);

        $result = $guard->check($oversized, 'sig', '1.2.3.4');

        $this->assertStringContainsString('1024', $result->message);
    }
}
