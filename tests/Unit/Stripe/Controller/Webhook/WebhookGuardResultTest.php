<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookGuardResult;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\Webhook\WebhookGuardResult::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-64a')]
#[\PHPUnit\Framework\Attributes\Group('security')]
final class WebhookGuardResultTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function resultHoldsRejectionData(): void
    {
        $result = new WebhookGuardResult('rate_limited', 429, 'Too many requests');

        $this->assertSame('rate_limited', $result->reason);
        $this->assertSame(429, $result->httpStatusCode);
        $this->assertSame('Too many requests', $result->message);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function resultIsReadonly(): void
    {
        $result = new WebhookGuardResult('payload_too_large', 413, 'Too large');

        $this->assertSame('payload_too_large', $result->reason);
        $this->assertSame(413, $result->httpStatusCode);
        $this->assertSame('Too large', $result->message);
    }
}
