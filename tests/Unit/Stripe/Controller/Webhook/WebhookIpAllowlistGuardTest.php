<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookIpAllowlistGuard;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\OxidEsales\Payments\Stripe\Controller\Webhook\WebhookIpAllowlistGuard::class)]
    #[Group('sprint-64c')]
    #[Group('security')]
final class WebhookIpAllowlistGuardTest extends TestCase
{
    public function testGuardAllowsStripeIp(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.169/32', '54.187.205.235/32']);

        $this->assertNull($guard->check('{}', 'sig', '54.187.174.169'));
    }

    public function testGuardRejectsNonStripeIp(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.169/32']);
        $result = $guard->check('{}', 'sig', '192.168.1.1');

        $this->assertNotNull($result);
        $this->assertSame(403, $result->httpStatusCode);
        $this->assertSame('ip_not_allowed', $result->reason);
    }

    public function testEmptyAllowlistDisablesGuard(): void
    {
        $guard = new WebhookIpAllowlistGuard([]);

        $this->assertNull($guard->check('{}', 'sig', '1.2.3.4'));
    }

    public function testGuardHandlesCidrNotation(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.0/24']);

        $this->assertNull($guard->check('{}', 'sig', '54.187.174.200'));
        $this->assertNotNull($guard->check('{}', 'sig', '54.187.175.1'));
    }

    public function testGuardAllowsLoopbackInDevMode(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.0/24'], true);

        $this->assertNull($guard->check('{}', 'sig', '127.0.0.1'));
        $this->assertNull($guard->check('{}', 'sig', '::1'));
    }

    public function testGuardRejectsLoopbackInProdMode(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.0/24'], false);

        $this->assertNotNull($guard->check('{}', 'sig', '127.0.0.1'));
    }

    public function testGuardHandlesExactIpWithoutCidr(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.169']);

        $this->assertNull($guard->check('{}', 'sig', '54.187.174.169'));
        $this->assertNotNull($guard->check('{}', 'sig', '54.187.174.170'));
    }

    public function testGuardHandlesInvalidIpGracefully(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.0/24']);

        $result = $guard->check('{}', 'sig', 'not-an-ip');
        $this->assertNotNull($result);
    }
}
