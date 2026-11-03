<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use LogicException;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use RuntimeException;

/**
 * VR-20 R5: validates the return tokens Stripe issued before payment-base's shared `ContractReturnTokenService`.
 *
 * Checkouts started before the update still carry such a token in their return URL (no expiry, no shopper). payment-base's
 * ReturnAccessGuard asks this validator for them - as `ReturnAccessRequest::$legacyTokens`, only for a token without a
 * `.` and only while the setting `blPaymentBaseAcceptLegacyReturnTokens` is on. Nothing issues them any more.
 * Deleted with that setting (one release).
 */
class LegacyReturnTokenValidator implements TokenServiceInterface
{
    private const TOKEN_SEPARATOR = ':';
    private const HASH_ALGORITHM = 'sha256';
    private const TOKEN_SALT = 'oe_stripe_contract_token_v1';

    private ?string $secret = null;

    public function __construct(
        private readonly ModuleConfigurationServiceInterface $configService
    ) {
    }

    private function getSecret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }

        // The secret the old tokens were signed with: the API key, or - when none was configured - the webhook secret.
        $apiSecret = $this->configService->getToken();
        if (empty($apiSecret)) {
            $apiSecret = $this->configService->getWebhookSecret();
        }

        if (empty($apiSecret)) {
            throw new RuntimeException('Stripe legacy return token validation needs a configured API secret key or webhook secret.');
        }

        $this->secret = hash_hmac(self::HASH_ALGORITHM, self::TOKEN_SALT, $apiSecret);

        return $this->secret;
    }

    public function generateToken(string $contractId): string
    {
        throw new LogicException('Stripe no longer issues its own return tokens; use payment-base\'s ContractReturnTokenService.');
    }

    public function validateToken(string $token, string $contractId): bool
    {
        if (empty($token)) {
            return false;
        }

        $extractedId = $this->extractContractId($token);

        return $extractedId !== null && $extractedId === $contractId;
    }

    /**
     * @return string|null The contract ID, or null when the token is not an authentic legacy token
     */
    public function extractContractId(string $token): ?string
    {
        if (empty($token)) {
            return null;
        }

        $decoded = $this->base64UrlDecode($token);
        if ($decoded === false) {
            return null;
        }

        $parts = explode(self::TOKEN_SEPARATOR, $decoded, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$contractId, $hmac] = $parts;
        $expectedHmac = hash_hmac(self::HASH_ALGORITHM, $contractId, $this->getSecret());

        return hash_equals($expectedHmac, $hmac) ? $contractId : null;
    }

    private function base64UrlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
