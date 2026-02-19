<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\InputValidation;

use InvalidArgumentException;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * F16: Currency Not Validated Against ISO 4217
 *
 * HIGH — PCI DSS 6.5.1
 *
 * BasketSnapshot::extractCurrency() checks type is string but accepts
 * any value — including SQL injection payloads, XSS, or garbage strings.
 *
 * @group security
 * @group f16
 * @since Sprint 60
 */
class CurrencyValidationTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createSnapshotData(array $overrides = []): array
    {
        return array_merge([
            'items' => [
                ['id' => 'art_001', 'title' => 'Test', 'quantity' => 1, 'price' => 10.00],
            ],
            'discounts' => [],
            'totalGross' => 10.00,
            'totalNet' => 8.40,
            'totalVat' => 1.60,
            'currency' => 'EUR',
        ], $overrides);
    }

    /**
     * F16: SQL injection payload accepted as currency.
     */
    public function testSqlInjectionPayloadAcceptedAsCurrency(): void
    {
        $data = $this->createSnapshotData(['currency' => "EUR'; DROP TABLE--"]);
        $snapshot = BasketSnapshot::fromArray($data);

        // VULNERABILITY: SQL injection string stored as currency
        $this->assertSame("EUR'; DROP TABLE--", $snapshot->getCurrency());
    }

    /**
     * F16: XSS payload accepted as currency.
     */
    public function testXssPayloadAcceptedAsCurrency(): void
    {
        $data = $this->createSnapshotData(['currency' => '<script>alert(1)</script>']);
        $snapshot = BasketSnapshot::fromArray($data);

        $this->assertSame('<script>alert(1)</script>', $snapshot->getCurrency());
    }

    /**
     * F16: Extremely long string accepted as currency.
     */
    public function testExtremelyLongStringAcceptedAsCurrency(): void
    {
        $longCurrency = str_repeat('A', 10000);
        $data = $this->createSnapshotData(['currency' => $longCurrency]);
        $snapshot = BasketSnapshot::fromArray($data);

        $this->assertSame(10000, strlen($snapshot->getCurrency()));
    }

    /**
     * F16: Empty string accepted as currency.
     */
    public function testEmptyStringAcceptedAsCurrency(): void
    {
        $data = $this->createSnapshotData(['currency' => '']);
        $snapshot = BasketSnapshot::fromArray($data);

        $this->assertSame('', $snapshot->getCurrency());
    }

    /**
     * F16: Non-ISO currency code accepted (lowercase).
     */
    public function testLowercaseCurrencyAccepted(): void
    {
        $data = $this->createSnapshotData(['currency' => 'eur']);
        $snapshot = BasketSnapshot::fromArray($data);

        $this->assertSame('eur', $snapshot->getCurrency());
    }

    /**
     * F16: Unicode characters accepted as currency.
     */
    public function testUnicodeCurrencyAccepted(): void
    {
        $data = $this->createSnapshotData(['currency' => "\u{20AC}\u{1F4B0}"]);
        $snapshot = BasketSnapshot::fromArray($data);

        $this->assertNotEmpty($snapshot->getCurrency());
    }

    /**
     * Positive: Valid ISO 4217 currency codes work.
     *
     * @dataProvider validCurrencyProvider
     */
    public function testValidCurrencyCodesWork(string $currency): void
    {
        $data = $this->createSnapshotData(['currency' => $currency]);
        $snapshot = BasketSnapshot::fromArray($data);

        $this->assertSame($currency, $snapshot->getCurrency());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validCurrencyProvider(): array
    {
        return [
            'EUR' => ['EUR'],
            'USD' => ['USD'],
            'GBP' => ['GBP'],
            'CHF' => ['CHF'],
        ];
    }

    /**
     * Positive: Non-string currency is rejected.
     */
    public function testNonStringCurrencyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @var array<string, mixed> $data */
        $data = $this->createSnapshotData();
        $data['currency'] = 123;
        BasketSnapshot::fromArray($data);
    }

    /**
     * Positive: Missing currency is rejected.
     */
    public function testMissingCurrencyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @var array<string, mixed> $data */
        $data = [
            'items' => [
                ['id' => 'art_001', 'title' => 'Test', 'quantity' => 1, 'price' => 10.00],
            ],
            'discounts' => [],
            'totalGross' => 10.00,
            'totalNet' => 8.40,
            'totalVat' => 1.60,
        ];
        BasketSnapshot::fromArray($data);
    }
}
