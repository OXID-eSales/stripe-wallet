<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Service\TokenServiceInterface;

/**
 * Service for generating and validating secure tokens for contract identification in URLs.
 *
 * Used to securely pass contract IDs in return URLs from payment providers
 * without exposing the raw contract ID or allowing tampering.
 *
 * Implements TokenServiceInterface for LSP compliance -
 * can be substituted anywhere the interface is expected.
 */
final class ContractTokenService implements TokenServiceInterface
{
    private const TOKEN_SEPARATOR = ':';
    private const HASH_ALGORITHM = 'sha256';
    private const TOKEN_SALT = 'oe_stripe_contract_token_v1';
    private const TOKEN_SECRET = 'oe_stripe_contract_token_secret';

    private readonly string $secret;

    public function __construct(
        ModuleConfigurationService $configService
    ) {
        // Derive token secret from the Stripe API secret key + a fixed salt
        // This ensures tokens are unique per shop installation without requiring
        // an additional environment variable
        $apiSecret = $configService->getSecretKey();
        if (empty($apiSecret)) {
            // Fallback to webhook secret if API secret is not configured
            $apiSecret = $configService->getWebhookSecret();
        }
        if (empty($apiSecret)) {
            // Last resort: use a default (less secure but prevents fatal errors)
            $apiSecret = self::TOKEN_SECRET;
        }
        $this->secret = hash_hmac(self::HASH_ALGORITHM, self::TOKEN_SALT, $apiSecret);
    }

    /**
     * Generate a secure, URL-safe token for a contract ID.
     *
     * Token format: base64url(contractId:hmac)
     */
    public function generateToken(string $contractId): string
    {
        $hmac = $this->generateHmac($contractId);
        $payload = $contractId . self::TOKEN_SEPARATOR . $hmac;

        return $this->base64UrlEncode($payload);
    }

    /**
     * Validate that a token is valid for the given contract ID.
     */
    public function validateToken(string $token, string $contractId): bool
    {
        if (empty($token)) {
            return false;
        }

        $extractedId = $this->extractContractId($token);
        if ($extractedId === null || $extractedId !== $contractId) {
            return false;
        }

        // Regenerate expected token and compare
        $expectedToken = $this->generateToken($contractId);

        return hash_equals($expectedToken, $token);
    }

    /**
     * Extract the contract ID from a token.
     *
     * @return string|null The contract ID, or null if token is invalid
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

        // Validate HMAC before returning contract ID
        $expectedHmac = $this->generateHmac($contractId);
        if (!hash_equals($expectedHmac, $hmac)) {
            return null;
        }

        return $contractId;
    }

    /**
     * Generate HMAC for a contract ID.
     */
    private function generateHmac(string $contractId): string
    {
        return hash_hmac(self::HASH_ALGORITHM, $contractId, $this->secret);
    }

    /**
     * Encode data as URL-safe base64.
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode URL-safe base64 data.
     */
    private function base64UrlDecode(string $data): string|false
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded;
    }
}
