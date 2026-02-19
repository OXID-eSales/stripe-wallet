<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\InputValidation;

use PHPUnit\Framework\TestCase;

/**
 * Documents Finding F8: ACP email not format-validated.
 *
 * The ACP checkout service accepts email strings without validation,
 * allowing XSS, SQL injection, and other payloads in the email field.
 *
 * @group security
 * @group gdpr
 * @group finding-f8
 * @group sprint-58
 */
final class AcpEmailValidationTest extends TestCase
{
    /**
     * @test
     *
     * Finding F8: PHP's filter_var(FILTER_VALIDATE_EMAIL) is not used.
     */
    public function testPhpFilterValidatesCorrectEmails(): void
    {
        $this->assertNotFalse(filter_var('test@example.com', FILTER_VALIDATE_EMAIL));
        $this->assertNotFalse(filter_var('user.name+tag@domain.co', FILTER_VALIDATE_EMAIL));
    }

    /**
     * @test
     *
     * Finding F8: Demonstrates that malformed strings pass without filter_var.
     */
    public function testMalformedEmailWouldBeRejectedByFilterVar(): void
    {
        $malformed = 'not-an-email';
        $this->assertFalse(filter_var($malformed, FILTER_VALIDATE_EMAIL));
    }

    /**
     * @test
     *
     * Finding F8: SQL injection payload in email field.
     */
    public function testSqlInjectionPayloadRejectedByFilterVar(): void
    {
        $sqlInjection = "'; DROP TABLE oxuser;--";
        $this->assertFalse(filter_var($sqlInjection, FILTER_VALIDATE_EMAIL));
    }

    /**
     * @test
     *
     * Finding F8: XSS payload in email field.
     */
    public function testXssPayloadRejectedByFilterVar(): void
    {
        $xssPayload = '<script>alert("xss")</script>@evil.com';
        $this->assertFalse(filter_var($xssPayload, FILTER_VALIDATE_EMAIL));
    }

    /**
     * @test
     *
     * Finding F8: Null bytes in email.
     */
    public function testNullByteInEmailRejectedByFilterVar(): void
    {
        $nullByteEmail = "test\x00@evil.com";
        $this->assertFalse(filter_var($nullByteEmail, FILTER_VALIDATE_EMAIL));
    }

    /**
     * @test
     *
     * Finding F8: Documents that the ACP service does not validate emails.
     * Checks source code for absence of FILTER_VALIDATE_EMAIL.
     */
    public function testAcpServiceLacksEmailValidation(): void
    {
        $sourceFile = dirname(__DIR__, 3) . '/src/Stripe/Mcp/Service/StripeAcpCheckoutService.php';
        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('StripeAcpCheckoutService not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // Document the gap: no email validation in ACP service
        $this->assertStringNotContainsString(
            'FILTER_VALIDATE_EMAIL',
            $source,
            'ACP service does NOT validate email format (Finding F8)'
        );
        $this->assertStringNotContainsString(
            'filter_var',
            $source,
            'ACP service does NOT use filter_var for input validation (Finding F8)'
        );
    }
}
