<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\InputValidation;

use OxidEsales\Payments\Stripe\Tests\Security\Helper\SecurityTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Documents Finding F6: Metadata injection — XSS and SQL payloads are stored as-is.
 *
 * Note: SQL injection is mitigated by parameterized queries in the repository layer,
 * but XSS payloads in metadata could be dangerous if rendered in admin UI.
 *
 * @covers \OxidEsales\PaymentComponent\Contract\PaymentContract
 * @group security
 * @group finding-f6
 * @group sprint-58
 */
final class MetadataInjectionTest extends TestCase
{
    /**
     * @test
     *
     * Finding F6: Script tags stored as-is in metadata.
     */
    public function testMetadataAcceptsScriptTags(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');
        $xssPayload = '<script>alert("xss")</script>';

        $contract->setMetadata('xss_test', $xssPayload);

        $this->assertSame($xssPayload, $contract->getMetadata('xss_test'));
    }

    /**
     * @test
     *
     * Finding F6: SQL payloads stored in metadata.
     * Safe due to parameterized queries, but documents the lack of input sanitization.
     */
    public function testMetadataAcceptsSqlPayloads(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');
        $sqlPayload = "'; DROP TABLE oe_payments_contract;--";

        $contract->setMetadata('sql_test', $sqlPayload);

        $this->assertSame($sqlPayload, $contract->getMetadata('sql_test'));
    }

    /**
     * @test
     *
     * Finding F6: No size limit on individual metadata values.
     */
    public function testMetadataAcceptsExtremelyLongValues(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');
        $longValue = str_repeat('A', 100_000); // 100KB

        $contract->setMetadata('long_value', $longValue);

        $value = $contract->getMetadata('long_value');
        $this->assertIsString($value);
        $this->assertSame(100_000, strlen($value));
    }

    /**
     * @test
     *
     * Finding F6: Metadata values can be overwritten without audit trail.
     */
    public function testMetadataOverwritesPreviousValue(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');

        $contract->setMetadata('key', 'original_value');
        $contract->setMetadata('key', 'overwritten_value');

        $this->assertSame('overwritten_value', $contract->getMetadata('key'));
    }

    /**
     * @test
     *
     * Finding F6: Null bytes in metadata values.
     */
    public function testMetadataAcceptsNullBytes(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');
        $nullPayload = "before\x00after";

        $contract->setMetadata('null_test', $nullPayload);

        $this->assertSame($nullPayload, $contract->getMetadata('null_test'));
    }
}
