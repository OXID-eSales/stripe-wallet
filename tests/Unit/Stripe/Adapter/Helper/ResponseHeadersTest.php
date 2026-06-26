<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use OxidEsales\Payments\Stripe\Adapter\Helper\ResponseHeaders;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 131 (STRP-XXX): security headers for the module's OWN HTTP responses
 * (webhook + JSON endpoints). These are API responses that are never legitimately
 * framed, so `X-Frame-Options: DENY` is correct here; shop-wide framing policy
 * (SAMEORIGIN, for admin framesets) is set in the web-server config, not here.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Adapter\Helper\ResponseHeaders::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-131')]
#[\PHPUnit\Framework\Attributes\Group('security')]
final class ResponseHeadersTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function capture(): array
    {
        $headers = [];
        ResponseHeaders::applySecurity(static function (string $header) use (&$headers): void {
            $headers[] = $header;
        });

        return $headers;
    }

    public function testEmitsNosniff(): void
    {
        $this->assertContains('X-Content-Type-Options: nosniff', $this->capture());
    }

    public function testEmitsFrameDeny(): void
    {
        $this->assertContains('X-Frame-Options: DENY', $this->capture());
    }

    public function testEmitsExactlyTheSecurityHeadersAndNothingElse(): void
    {
        $this->assertSame(
            ['X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY'],
            $this->capture()
        );
    }

    public function testUsesTheInjectedSinkForEveryHeader(): void
    {
        $calls = 0;
        ResponseHeaders::applySecurity(static function (string $header) use (&$calls): void {
            $calls++;
        });

        $this->assertSame(2, $calls);
    }
}
