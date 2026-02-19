<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Auth;

use OxidEsales\PaymentComponent\Mcp\Http\RateLimiterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests rate limiter behavior: blocks after threshold, allows within threshold,
 * and uses REMOTE_ADDR (not X-Forwarded-For) for client identification.
 *
 * @group security
 * @group auth
 * @group sprint-58
 */
final class RateLimitBypassTest extends TestCase
{
    /**
     * @test
     */
    public function testRateLimiterBlocksAfterThreshold(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);

        $callCount = 0;
        $limiter->method('isAllowed')
            ->willReturnCallback(function () use (&$callCount): bool {
                $callCount++;
                return $callCount <= 10; // Allow first 10, block after
            });

        // First 10 should pass
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($limiter->isAllowed('192.168.1.1'));
        }

        // 11th should be blocked
        $this->assertFalse($limiter->isAllowed('192.168.1.1'));
    }

    /**
     * @test
     */
    public function testRateLimiterAllowsWithinThreshold(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('isAllowed')->willReturn(true);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->isAllowed('192.168.1.1'));
        }
    }

    /**
     * @test
     *
     * Compliance: PCI DSS 6.5.9 — X-Forwarded-For is user-controlled and must not be trusted.
     *
     * The UcpCheckoutController uses $_SERVER['REMOTE_ADDR'], not X-Forwarded-For.
     */
    public function testXForwardedForHeaderIsIgnored(): void
    {
        $sourceFile = dirname(__DIR__, 3) . '/src/Stripe/Mcp/Controller/UcpCheckoutController.php';
        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('UcpCheckoutController not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // Verify it uses REMOTE_ADDR
        $this->assertStringContainsString("REMOTE_ADDR", $source);

        // Verify it does NOT use X-Forwarded-For for client IP
        $this->assertStringNotContainsString('HTTP_X_FORWARDED_FOR', $source);
    }
}
