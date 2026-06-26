<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\PaymentBase\Mcp\Http\RateLimiterInterface;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookRateLimitGuard;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\Webhook\WebhookRateLimitGuard::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-64b')]
#[\PHPUnit\Framework\Attributes\Group('security')]
final class WebhookRateLimitGuardTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function guardRejectsWhenRateLimitExceeded(): void
    {
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->method('isAllowed')->with('192.168.1.1')->willReturn(false);

        $guard = new WebhookRateLimitGuard($rateLimiter);
        $result = $guard->check('{"id":"evt_1"}', 'sig_test', '192.168.1.1');

        $this->assertNotNull($result);
        $this->assertSame(429, $result->httpStatusCode);
        $this->assertSame('rate_limited', $result->reason);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardAllowsWhenUnderLimit(): void
    {
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->method('isAllowed')->with('10.0.0.1')->willReturn(true);

        $guard = new WebhookRateLimitGuard($rateLimiter);
        $result = $guard->check('{"id":"evt_1"}', 'sig_test', '10.0.0.1');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardUsesIpAsRateLimitIdentifier(): void
    {
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->once())
            ->method('isAllowed')
            ->with('203.0.113.50')
            ->willReturn(true);

        $guard = new WebhookRateLimitGuard($rateLimiter);
        $guard->check('{}', 'sig', '203.0.113.50');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardReturns429WithRetryMessage(): void
    {
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->method('isAllowed')->willReturn(false);

        $guard = new WebhookRateLimitGuard($rateLimiter);
        $result = $guard->check('{}', 'sig', '1.2.3.4');

        $this->assertStringContainsString('Too many requests', $result->message);
    }
}
