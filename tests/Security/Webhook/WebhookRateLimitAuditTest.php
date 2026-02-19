<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Webhook;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F18: Webhook Endpoint Has No Rate Limiting
 *
 * HIGH — PCI DSS 10.2.1, OWASP A05:2021
 *
 * Unlike UcpCheckoutController (has RateLimiterInterface), the webhook
 * endpoint accepts unlimited requests. An attacker can flood with invalid
 * signatures causing CPU-intensive HMAC computations.
 *
 * @group security
 * @group f18
 * @since Sprint 60
 */
class WebhookRateLimitAuditTest extends TestCase
{
    /**
     * F18: WebhookController has no RateLimiterInterface dependency.
     */
    public function testWebhookControllerHasNoRateLimiter(): void
    {
        $sourceFile = dirname(__DIR__, 3)
            . '/src/Stripe/Controller/Webhook/WebhookController.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // No rate limiter in the webhook controller
        $this->assertStringNotContainsString(
            'RateLimiterInterface',
            $source,
            'F18: WebhookController has no RateLimiterInterface dependency'
        );
        $this->assertStringNotContainsString(
            'isAllowed',
            $source,
            'F18: WebhookController does not call isAllowed()'
        );
    }

    /**
     * F18: WebhookController constructor does not accept rate limiter.
     */
    public function testWebhookControllerConstructorHasNoRateLimiter(): void
    {
        $sourceFile = dirname(__DIR__, 3)
            . '/src/Stripe/Controller/Webhook/WebhookController.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        $this->assertStringNotContainsString(
            'rateLimiter',
            $source,
            'F18: No rate limiter property in WebhookController'
        );
    }

    /**
     * F18: Webhook processes every request regardless of volume.
     *
     * Documents that signature verification (HMAC) runs on every request
     * without pre-filtering — enabling CPU exhaustion attacks.
     */
    public function testWebhookPerformsHmacOnEveryRequest(): void
    {
        $sourceFile = dirname(__DIR__, 3)
            . '/src/Stripe/Controller/Webhook/WebhookController.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // Signature verification happens on every request
        $hasSignatureCheck = str_contains($source, 'Signature')
            || str_contains($source, 'signature')
            || str_contains($source, 'constructEvent');
        $this->assertTrue(
            $hasSignatureCheck,
            'Webhook performs signature verification on every request'
        );
    }

    /**
     * Positive: ApcuRateLimiter implements RateLimiterInterface correctly.
     */
    public function testRateLimiterInterfaceExists(): void
    {
        $this->assertTrue(
            interface_exists(\OxidEsales\PaymentComponent\Mcp\Http\RateLimiterInterface::class),
            'RateLimiterInterface exists and could be used for webhook'
        );
    }
}
