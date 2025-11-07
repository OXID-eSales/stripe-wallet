<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

use OxidSolutionCatalysts\Payments\Watch\Exception\AuthenticationException;

/**
 * API Key validator with timing attack prevention
 *
 * Uses hash_equals() for constant-time string comparison to prevent
 * timing attacks where attackers could guess the API key character by character.
 *
 * Security Features:
 * - Constant-time comparison using hash_equals()
 * - API key format validation (64 hexadecimal characters)
 * - No timing information leakage
 */
final class ApiKeyValidator
{
    private const API_KEY_LENGTH = 64;
    private const API_KEY_PATTERN = '/^[a-fA-F0-9]{64}$/';

    /**
     * Validate API key against expected key
     *
     * @param string $providedKey The API key provided by the client
     * @param string $expectedKey The expected API key
     * @return bool True if keys match
     * @throws AuthenticationException If API key format is invalid
     */
    public function validate(string $providedKey, string $expectedKey): bool
    {
        // Validate format first (fail fast for obvious invalid keys)
        if (!$this->isValidFormat($providedKey)) {
            throw new AuthenticationException(
                'Invalid API key format. Must be 64 hexadecimal characters.'
            );
        }

        // Constant-time comparison to prevent timing attacks
        // Both keys are normalized to lowercase for comparison
        return hash_equals(
            strtolower($expectedKey),
            strtolower($providedKey)
        );
    }

    /**
     * Check if API key has valid format
     *
     * @param string $apiKey API key to check
     * @return bool True if format is valid
     */
    public function isValidFormat(string $apiKey): bool
    {
        return preg_match(self::API_KEY_PATTERN, $apiKey) === 1;
    }

    /**
     * Generate a secure API key
     *
     * @return string Generated API key (64 hex characters)
     * @throws \Exception If random_bytes fails
     */
    public function generateApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
