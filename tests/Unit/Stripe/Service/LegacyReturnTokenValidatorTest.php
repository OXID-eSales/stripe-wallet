<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use LogicException;
use OxidEsales\Payments\Stripe\Service\LegacyReturnTokenValidator;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * VR-20 R5: the return tokens Stripe issued before payment-base's shared token. They are still in the URLs of
 * checkouts that were started before the update, so payment-base's guard may ask this validator - only while its
 * switch `blPaymentBaseAcceptLegacyReturnTokens` is on. Nothing issues them any more.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(LegacyReturnTokenValidator::class)]
final class LegacyReturnTokenValidatorTest extends TestCase
{
    private const SECRET_KEY = 'sk_test_51ABC123XYZ';

    public function testATokenTheOldServiceIssuedIsValidForItsContract(): void
    {
        $validator = $this->validator(self::SECRET_KEY);

        self::assertTrue($validator->validateToken($this->issuedBefore('contract_123', self::SECRET_KEY), 'contract_123'));
    }

    public function testATokenOfAnotherContractIsNotValid(): void
    {
        $validator = $this->validator(self::SECRET_KEY);

        self::assertFalse($validator->validateToken($this->issuedBefore('contract_123', self::SECRET_KEY), 'contract_456'));
    }

    public function testATokenSignedWithAnotherKeyIsNotValid(): void
    {
        self::assertFalse($this->validator('sk_test_other')->validateToken($this->issuedBefore('contract_123', self::SECRET_KEY), 'contract_123'));
    }

    public function testATamperedTokenIsNotValid(): void
    {
        $token = $this->issuedBefore('contract_123', self::SECRET_KEY);

        self::assertFalse($this->validator(self::SECRET_KEY)->validateToken(substr($token, 0, -2) . 'xx', 'contract_123'));
    }

    public function testAnEmptyTokenIsNotValid(): void
    {
        self::assertFalse($this->validator(self::SECRET_KEY)->validateToken('', 'contract_123'));
    }

    public function testTheContractIdOfAnAuthenticTokenIsExtracted(): void
    {
        $validator = $this->validator(self::SECRET_KEY);

        self::assertSame('contract_123', $validator->extractContractId($this->issuedBefore('contract_123', self::SECRET_KEY)));
        self::assertNull($validator->extractContractId('not-a-token'));
    }

    public function testTokensIssuedWithTheWebhookSecretBecauseNoKeyWasConfiguredStillValidate(): void
    {
        $validator = $this->validator('', 'whsec_test123');

        self::assertTrue($validator->validateToken($this->issuedBefore('contract_123', 'whsec_test123'), 'contract_123'));
    }

    public function testWithoutAnySecretItRefusesInsteadOfGuessing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('secret');

        $this->validator('', '')->validateToken(rtrim(strtr(base64_encode('contract_123:abc'), '+/', '-_'), '='), 'contract_123');
    }

    public function testNothingIssuesTheseTokensAnyMore(): void
    {
        $this->expectException(LogicException::class);

        $this->validator(self::SECRET_KEY)->generateToken('contract_123');
    }

    private function validator(string $secretKey, string $webhookSecret = ''): LegacyReturnTokenValidator
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getToken')->willReturn($secretKey);
        $config->method('getWebhookSecret')->willReturn($webhookSecret);

        return new LegacyReturnTokenValidator($config);
    }

    /**
     * The format the removed ContractTokenService issued: base64url(contractId:hmac), the hmac keyed with a secret
     * derived from the API key. Reproduced here on purpose - this test pins what old URLs contain.
     */
    private function issuedBefore(string $contractId, string $apiSecret): string
    {
        $secret = hash_hmac('sha256', 'oe_stripe_contract_token_v1', $apiSecret);
        $payload = $contractId . ':' . hash_hmac('sha256', $contractId, $secret);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }
}
