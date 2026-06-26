<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 131 (STRP-XXX): the JSON checkout endpoint emits the module's response
 * security headers (nosniff + X-Frame DENY) alongside its Content-Type. Captures
 * the header sink via a testable subclass so no real headers are emitted.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\StripeOrderController::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-131')]
#[\PHPUnit\Framework\Attributes\Group('security')]
final class StripeOrderControllerResponseHeadersTest extends TestCase
{
    public function testSendSecureJsonHeadersEmitsContentTypeAndSecurityHeaders(): void
    {
        $controller = new class extends StripeOrderController {
            /** @var list<string> */
            public array $captured = [];

            public function __construct()
            {
                // Skip OXID controller bootstrap — we only exercise the header seam.
            }

            protected function emitHeader(string $header): void
            {
                $this->captured[] = $header;
            }

            public function runSendSecureJsonHeaders(): void
            {
                $this->sendSecureJsonHeaders();
            }
        };

        $controller->runSendSecureJsonHeaders();

        $this->assertSame(
            [
                'Content-Type: application/json',
                'X-Content-Type-Options: nosniff',
                'X-Frame-Options: DENY',
            ],
            $controller->captured
        );
    }
}
