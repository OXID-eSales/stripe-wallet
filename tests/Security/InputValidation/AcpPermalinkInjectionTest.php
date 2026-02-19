<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\InputValidation;

use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * F14: Open Redirect via ACP Order Permalink (missing urlencode)
 *
 * CRITICAL — PCI DSS 6.5.1, OWASP A01:2021
 *
 * StripeAcpCheckoutService builds order permalink by concatenating $orderId
 * without urlencode(). If $orderId contains special characters, query string
 * injection occurs.
 *
 * Tested via direct string concatenation (same pattern as production code)
 * to avoid needing the full Stripe checkout service dependency chain.
 *
 * @group security
 * @group f14
 * @since Sprint 59
 */
class AcpPermalinkInjectionTest extends TestCase
{
    private const SHOP_URL = 'https://shop.example.com/';

    /**
     * Reproduce the exact permalink construction from StripeAcpCheckoutService:94
     */
    private function buildPermalink(string $orderId): string
    {
        return self::SHOP_URL . '?cl=order_confirm&order=' . $orderId;
    }

    /**
     * Reproduce the fixed version with urlencode.
     */
    private function buildPermalinkSafe(string $orderId): string
    {
        return self::SHOP_URL . '?cl=order_confirm&order=' . urlencode($orderId);
    }

    /**
     * F14: Query string injection via ampersand in order ID.
     */
    public function testQueryStringInjectionViaAmpersand(): void
    {
        $maliciousOrderId = 'abc123&redirect=https://evil.com';
        $permalink = $this->buildPermalink($maliciousOrderId);

        // VULNERABILITY: The ampersand creates an additional query parameter
        $this->assertStringContainsString(
            '&redirect=https://evil.com',
            $permalink,
            'F14: Unencoded orderId allows query string injection'
        );

        // With urlencode, the ampersand would be encoded
        $safePermalink = $this->buildPermalinkSafe($maliciousOrderId);
        $this->assertStringNotContainsString(
            '&redirect=',
            $safePermalink,
            'urlencode prevents query string injection'
        );
    }

    /**
     * F14: JavaScript protocol injection in order ID.
     */
    public function testJavascriptProtocolInjection(): void
    {
        $maliciousOrderId = "abc123&url=javascript:alert('xss')";
        $permalink = $this->buildPermalink($maliciousOrderId);

        $this->assertStringContainsString(
            "javascript:alert('xss')",
            $permalink,
            'F14: JavaScript protocol passes through unencoded'
        );
    }

    /**
     * F14: HTML injection via order ID.
     */
    public function testHtmlInjectionInPermalink(): void
    {
        $maliciousOrderId = 'abc123"><script>alert(1)</script>';
        $permalink = $this->buildPermalink($maliciousOrderId);

        $this->assertStringContainsString(
            '<script>',
            $permalink,
            'F14: HTML/script tags pass through unencoded'
        );

        $safePermalink = $this->buildPermalinkSafe($maliciousOrderId);
        $this->assertStringNotContainsString(
            '<script>',
            $safePermalink
        );
    }

    /**
     * F14: Hash fragment injection to override page behavior.
     */
    public function testHashFragmentInjection(): void
    {
        $maliciousOrderId = 'abc123#/admin/dashboard';
        $permalink = $this->buildPermalink($maliciousOrderId);

        $this->assertStringContainsString(
            '#/admin/dashboard',
            $permalink,
            'F14: Hash fragment passes through unencoded'
        );
    }

    /**
     * F14: Null byte injection in order ID.
     */
    public function testNullByteInjection(): void
    {
        $maliciousOrderId = "abc123\x00&admin=true";
        $permalink = $this->buildPermalink($maliciousOrderId);

        $this->assertStringContainsString(
            "&admin=true",
            $permalink,
            'F14: Null byte with extra params passes through'
        );
    }

    /**
     * F14: Multiple parameter injection to override existing parameters.
     */
    public function testParameterOverrideInjection(): void
    {
        $maliciousOrderId = 'abc123&cl=admin&fnc=deleteAll';
        $permalink = $this->buildPermalink($maliciousOrderId);

        // The cl parameter appears twice — the second may override the first
        $this->assertSame(
            2,
            substr_count($permalink, 'cl='),
            'F14: Duplicate cl= parameter injected via orderId'
        );
    }

    /**
     * Positive: Normal order ID builds correct permalink.
     */
    public function testNormalOrderIdBuildsCorrectPermalink(): void
    {
        $orderId = '550e8400-e29b-41d4-a716-446655440000';
        $permalink = $this->buildPermalink($orderId);

        $expected = self::SHOP_URL . '?cl=order_confirm&order=' . $orderId;
        $this->assertSame($expected, $permalink);
    }

    /**
     * F14: Verify the vulnerable code pattern exists in StripeAcpCheckoutService.
     */
    public function testVulnerablePatternExistsInSourceCode(): void
    {
        $sourceFile = dirname(__DIR__, 3)
            . '/src/Stripe/Mcp/Service/StripeAcpCheckoutService.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // The vulnerable pattern: concatenation without urlencode
        $this->assertStringContainsString(
            "getShopUrl() . '?cl=order_confirm&order=' . \$orderId",
            $source,
            'F14: Vulnerable concatenation pattern exists in source'
        );

        // urlencode is NOT used
        $this->assertStringNotContainsString(
            'urlencode($orderId)',
            $source,
            'F14: urlencode is not applied to $orderId'
        );
    }
}
