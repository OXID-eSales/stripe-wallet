<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\ConfigurationValidator;
use OxidEsales\Payments\Stripe\Service\KeyPairValidatorInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

final class ConfigurationValidatorKeyPairTest extends TestCase
{
    private ConfigurationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigurationValidator($this->createStub(ModuleConfigurationServiceInterface::class));
    }

    public function testIsTheKeyPairValidator(): void
    {
        self::assertInstanceOf(KeyPairValidatorInterface::class, $this->validator);
    }

    public function testGetKeyPairError_KeysOfOneAccount_IsNull(): void
    {
        self::assertNull($this->validator->getKeyPairError('pk_test_AAAAAAAAAAxxxx', 'sk_test_AAAAAAAAAAyyyy'));
    }

    public function testGetKeyPairError_KeysOfDifferentAccounts_NamesTheMismatch(): void
    {
        $error = $this->validator->getKeyPairError('pk_test_AAAAAAAAAAxxxx', 'sk_test_BBBBBBBBBByyyy');

        self::assertNotNull($error);
        self::assertStringContainsString('different Stripe accounts', $error);
    }

    public function testGetKeyPairError_AMalformedKey_IsNamed(): void
    {
        self::assertSame('Publishable key has invalid format', $this->validator->getKeyPairError('nonsense', 'sk_test_AAAAAAAAAAyyyy'));
        self::assertSame('Secret key has invalid format', $this->validator->getKeyPairError('pk_test_AAAAAAAAAAxxxx', 'nonsense'));
    }

    public function testGetKeyPairError_AMissingKey_IsNamed(): void
    {
        self::assertSame('Publishable key is not configured', $this->validator->getKeyPairError('', 'sk_test_AAAAAAAAAAyyyy'));
        self::assertSame('Secret key is not configured', $this->validator->getKeyPairError('pk_test_AAAAAAAAAAxxxx', ''));
    }
}
