<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\DataProtection;

use OxidEsales\Payments\Stripe\Tests\Security\Helper\SecurityTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Documents Finding F6: Contract metadata accepts arbitrary data
 * without schema validation or size limits.
 *
 * @covers \OxidEsales\PaymentComponent\Contract\PaymentContract
 * @group security
 * @group gdpr
 * @group finding-f6
 * @group sprint-58
 */
final class ContractMetadataTest extends TestCase
{
    /**
     * @test
     *
     * Finding F6: No schema validation — any key is accepted.
     */
    public function testMetadataAcceptsArbitraryKeys(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');

        $contract->setMetadata('arbitrary_key', 'arbitrary_value');
        $contract->setMetadata('another_random_key', ['nested' => 'data']);
        $contract->setMetadata('123numeric', true);

        $this->assertSame('arbitrary_value', $contract->getMetadata('arbitrary_key'));
        $this->assertSame(['nested' => 'data'], $contract->getMetadata('another_random_key'));
        $this->assertTrue($contract->getMetadata('123numeric'));
    }

    /**
     * @test
     *
     * Finding F6: IP address = PII under GDPR — metadata stores it without notice.
     * Compliance: GDPR Art.4(1)
     */
    public function testMetadataCanStoreUserIp(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');

        $contract->setMetadata('client_ip', '203.0.113.42');

        $this->assertSame('203.0.113.42', $contract->getMetadata('client_ip'));
    }

    /**
     * @test
     *
     * Finding F6: User-Agent = potential PII — stored without schema check.
     */
    public function testMetadataCanStoreUserAgent(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

        $contract->setMetadata('user_agent', $ua);

        $this->assertSame($ua, $contract->getMetadata('user_agent'));
    }

    /**
     * @test
     *
     * Finding F6: No size limit — 1MB string accepted.
     * Compliance: GDPR Art.25 — Data minimization by design
     */
    public function testMetadataHasNoMaxSizeLimit(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');
        $largeValue = str_repeat('x', 1_048_576); // 1 MB

        $contract->setMetadata('large_payload', $largeValue);

        $value = $contract->getMetadata('large_payload');
        $this->assertIsString($value);
        $this->assertSame(1_048_576, strlen($value));
    }

    /**
     * @test
     *
     * Finding F6: Nested arrays accepted without depth limit.
     */
    public function testMetadataAcceptsNestedArrays(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');
        $nested = ['level1' => ['level2' => ['level3' => ['level4' => 'deep']]]];

        $contract->setMetadata('deeply_nested', $nested);

        $result = $contract->getMetadata('deeply_nested');
        $this->assertIsArray($result);
        $this->assertIsArray($result['level1']);
        $this->assertIsArray($result['level1']['level2']);
        $this->assertIsArray($result['level1']['level2']['level3']);
        $this->assertSame('deep', $result['level1']['level2']['level3']['level4']);
    }
}
