<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookIpAllowlistGuard;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\Webhook\WebhookIpAllowlistGuard::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-64c')]
#[\PHPUnit\Framework\Attributes\Group('security')]
final class WebhookIpAllowlistGuardTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function guardAllowsStripeIp(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.169/32', '54.187.205.235/32']);

        $this->assertNull($guard->check('{}', 'sig', '54.187.174.169'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardRejectsNonStripeIp(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.169/32']);
        $result = $guard->check('{}', 'sig', '192.168.1.1');

        $this->assertNotNull($result);
        $this->assertSame(403, $result->httpStatusCode);
        $this->assertSame('ip_not_allowed', $result->reason);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function emptyAllowlistDisablesGuard(): void
    {
        $guard = new WebhookIpAllowlistGuard([]);

        $this->assertNull($guard->check('{}', 'sig', '1.2.3.4'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardHandlesCidrNotation(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.0/24']);

        $this->assertNull($guard->check('{}', 'sig', '54.187.174.200'));
        $this->assertNotNull($guard->check('{}', 'sig', '54.187.175.1'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardAllowsLoopbackInDevMode(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.0/24'], true);

        $this->assertNull($guard->check('{}', 'sig', '127.0.0.1'));
        $this->assertNull($guard->check('{}', 'sig', '::1'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardRejectsLoopbackInProdMode(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.0/24'], false);

        $this->assertNotNull($guard->check('{}', 'sig', '127.0.0.1'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardHandlesExactIpWithoutCidr(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.169']);

        $this->assertNull($guard->check('{}', 'sig', '54.187.174.169'));
        $this->assertNotNull($guard->check('{}', 'sig', '54.187.174.170'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardHandlesInvalidIpGracefully(): void
    {
        $guard = new WebhookIpAllowlistGuard(['54.187.174.0/24']);

        $result = $guard->check('{}', 'sig', 'not-an-ip');
        $this->assertNotNull($result);
    }
}
