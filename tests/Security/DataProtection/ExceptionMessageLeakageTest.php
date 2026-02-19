<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\DataProtection;

use PHPUnit\Framework\TestCase;

/**
 * F23: Exception Messages Logged Without Sanitization
 *
 * MEDIUM — GDPR Art.25, OWASP A09:2021
 *
 * $e->getMessage() is logged directly in multiple service files. If exceptions
 * contain user input or PII (email, DB connection strings), sensitive data
 * enters log files.
 *
 * @group security
 * @group f23
 * @since Sprint 61
 */
class ExceptionMessageLeakageTest extends TestCase
{
    /**
     * Files known to log exception messages directly.
     *
     * @return array<string, array{string}>
     */
    public static function sourceFileProvider(): array
    {
        $paycomp = dirname(__DIR__, 5)
            . '/source/extensions/payment-component/src';

        return [
            'AbstractPaymentCaptureService' => [
                $paycomp . '/Service/AbstractPaymentCaptureService.php',
            ],
            'EarlyOrderCreationHandler' => [
                $paycomp . '/EventSystem/Handler/EarlyOrderCreationHandler.php',
            ],
        ];
    }

    /**
     * F23: Exception message is logged directly without sanitization.
     *
     * @dataProvider sourceFileProvider
     */
    public function testExceptionMessageLoggedDirectly(string $filePath): void
    {
        if (!file_exists($filePath)) {
            $this->markTestSkipped("Source file not found: {$filePath}");
        }

        $source = file_get_contents($filePath);
        $this->assertIsString($source);

        // Documents that $e->getMessage() is used in logging context
        $this->assertStringContainsString(
            '$e->getMessage()',
            $source,
            'F23: Exception message is passed directly to logging'
        );
    }

    /**
     * F23: No sanitization function is applied to exception messages.
     *
     * @dataProvider sourceFileProvider
     */
    public function testNoSanitizationAppliedToExceptionMessages(string $filePath): void
    {
        if (!file_exists($filePath)) {
            $this->markTestSkipped("Source file not found: {$filePath}");
        }

        $source = file_get_contents($filePath);
        $this->assertIsString($source);

        // No sanitization applied before logging
        $this->assertStringNotContainsString('sanitize', strtolower($source));
        $this->assertStringNotContainsString('redact', strtolower($source));
        $this->assertStringNotContainsString('htmlspecialchars($e->getMessage', $source);
    }

    /**
     * F23: Exception messages can contain PII by design.
     */
    public function testExceptionMessagesCanContainPii(): void
    {
        // Simulates a database exception that includes connection details
        $exception = new \RuntimeException(
            'SQLSTATE[HY000]: Connection refused to mysql://admin:p4ssw0rd@db:3306/shop'
        );

        $message = $exception->getMessage();

        // The message contains credentials — if logged, it's a data breach
        $this->assertStringContainsString('p4ssw0rd', $message);
        $this->assertStringContainsString('admin', $message);
    }

    /**
     * F23: Exception messages can contain user email.
     */
    public function testExceptionMessagesCanContainEmail(): void
    {
        $exception = new \InvalidArgumentException(
            'User not found: john.doe@example.com'
        );

        $message = $exception->getMessage();

        $this->assertStringContainsString('john.doe@example.com', $message);
    }

    /**
     * F23: Exception chaining preserves PII through stack.
     */
    public function testExceptionChainingPreservesPii(): void
    {
        $inner = new \RuntimeException('Card 4242424242424242 declined');
        $outer = new \RuntimeException('Payment failed: ' . $inner->getMessage(), 0, $inner);

        $this->assertStringContainsString('4242424242424242', $outer->getMessage());
    }

    /**
     * F23: EarlyOrderCreationHandler logs full exception class name.
     */
    public function testHandlerLogsExceptionClassName(): void
    {
        $filePath = dirname(__DIR__, 5)
            . '/source/extensions/payment-component/src/EventSystem/Handler/EarlyOrderCreationHandler.php';

        if (!file_exists($filePath)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($filePath);
        $this->assertIsString($source);

        // Logs get_class($e) — reveals internal architecture
        $this->assertStringContainsString(
            'get_class($e)',
            $source,
            'F23: Exception class name is logged (reveals internals)'
        );
    }
}
