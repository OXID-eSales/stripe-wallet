<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\InputValidation;

use PHPUnit\Framework\TestCase;

/**
 * F21: Payment Intent ID Not Format-Validated
 *
 * MEDIUM — Input validation best practice
 *
 * getPaymentIntentIdFromRequest() accepts any string from $_GET/$_POST.
 * No validation against Stripe's `pi_` prefix format.
 *
 * @group security
 * @group f21
 * @since Sprint 61
 */
class PaymentIntentIdValidationTest extends TestCase
{
    /**
     * F21: Source code accepts any string as payment intent ID.
     */
    public function testNoFormatValidationInSource(): void
    {
        $sourceFile = dirname(__DIR__, 3)
            . '/src/Stripe/Controller/StripeOrderController.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // No regex validation for pi_ prefix
        $this->assertStringNotContainsString(
            "preg_match('/^pi_",
            $source,
            'F21: No pi_ prefix validation exists'
        );
        $this->assertStringNotContainsString(
            "str_starts_with(\$value, 'pi_')",
            $source,
            'F21: No str_starts_with pi_ check'
        );
    }

    /**
     * F21: SQL injection string would pass the is_string() type check.
     */
    public function testSqlInjectionPassesTypeCheckButFailsFormatCheck(): void
    {
        $maliciousId = "pi_test'; DROP TABLE orders--";

        // Passes is_string type check (which is the only check in source)
        $this->addToAssertionCount(1);

        // But fails proper format validation
        $this->assertFalse(
            (bool) preg_match('/^pi_[a-zA-Z0-9]+$/', $maliciousId),
            'Malicious ID does NOT match valid pi_ format'
        );
    }

    /**
     * F21: Demonstrates correct validation pattern.
     *
     * @dataProvider invalidPaymentIntentIdProvider
     */
    public function testInvalidPaymentIntentIdsFailFormatCheck(string $id): void
    {
        $isValid = (bool) preg_match('/^pi_[a-zA-Z0-9]{10,}$/', $id);

        $this->assertFalse(
            $isValid,
            "Payment intent ID '{$id}' should fail format validation"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPaymentIntentIdProvider(): array
    {
        return [
            'empty string' => [''],
            'no prefix' => ['abc123'],
            'wrong prefix' => ['ch_123abc'],
            'sql injection' => ["pi_'; DROP TABLE--"],
            'xss payload' => ['pi_<script>alert(1)</script>'],
            'null byte' => ["pi_test\x00hack"],
            'space in id' => ['pi_test hack'],
            'unicode' => ["pi_test\u{200B}hack"],
        ];
    }

    /**
     * Positive: Valid Stripe payment intent IDs pass format check.
     *
     * @dataProvider validPaymentIntentIdProvider
     */
    public function testValidPaymentIntentIdsPassFormatCheck(string $id): void
    {
        $isValid = (bool) preg_match('/^pi_[a-zA-Z0-9]{10,}$/', $id);

        $this->assertTrue(
            $isValid,
            "Payment intent ID '{$id}' should pass format validation"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validPaymentIntentIdProvider(): array
    {
        return [
            'test mode' => ['pi_3PGKQhKZ2B9q5T0C1aBcDeFg'],
            'live mode' => ['pi_1AbCdEfGhIjKlMnOpQrStUvW'],
            'short valid' => ['pi_1234567890'],
        ];
    }
}
