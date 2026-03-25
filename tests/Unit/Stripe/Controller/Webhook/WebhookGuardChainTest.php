<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookGuardChain;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookGuardResult;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookRequestGuardInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\OxidEsales\Payments\Stripe\Controller\Webhook\WebhookGuardChain::class)]
    #[Group('sprint-64a')]
    #[Group('security')]
final class WebhookGuardChainTest extends TestCase
{
    public function testChainAllowsWhenAllGuardsPass(): void
    {
        $guard1 = $this->createMock(WebhookRequestGuardInterface::class);
        $guard1->method('check')->willReturn(null);
        $guard2 = $this->createMock(WebhookRequestGuardInterface::class);
        $guard2->method('check')->willReturn(null);

        $chain = new WebhookGuardChain([$guard1, $guard2]);

        $this->assertNull($chain->check('{}', 'sig', '1.2.3.4'));
    }

    public function testChainShortCircuitsOnFirstRejection(): void
    {
        $rejection = new WebhookGuardResult('rate_limited', 429, 'Too many requests');
        $guard1 = $this->createMock(WebhookRequestGuardInterface::class);
        $guard1->method('check')->willReturn($rejection);
        $guard2 = $this->createMock(WebhookRequestGuardInterface::class);
        $guard2->expects($this->never())->method('check');

        $chain = new WebhookGuardChain([$guard1, $guard2]);

        $this->assertSame($rejection, $chain->check('{}', 'sig', '1.2.3.4'));
    }

    public function testEmptyChainAllowsAll(): void
    {
        $chain = new WebhookGuardChain([]);

        $this->assertNull($chain->check('{}', 'sig', '1.2.3.4'));
    }

    public function testChainReturnsFirstRejectionNotSecond(): void
    {
        $rejection1 = new WebhookGuardResult('payload_too_large', 413, 'Too large');
        $rejection2 = new WebhookGuardResult('rate_limited', 429, 'Rate limited');

        $guard1 = $this->createMock(WebhookRequestGuardInterface::class);
        $guard1->method('check')->willReturn($rejection1);
        $guard2 = $this->createMock(WebhookRequestGuardInterface::class);
        $guard2->method('check')->willReturn($rejection2);

        $chain = new WebhookGuardChain([$guard1, $guard2]);
        $result = $chain->check('{}', 'sig', '1.2.3.4');

        $this->assertSame(413, $result->httpStatusCode);
    }
}
