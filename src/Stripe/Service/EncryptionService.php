<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use Psr\Log\LoggerInterface;

/**
 * EncryptionService - Handles encryption/decryption of sensitive payment data
 *
 * Decrypts card data that was encrypted client-side using Web Crypto API
 * Format: "ENC:base64EncodedData"
 */
class EncryptionService
{
    private const ENCRYPTION_PREFIX = 'ENC:';
    private const CIPHER_METHOD = 'aes-256-gcm';

    public function __construct(
        private readonly string $encryptionKey,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Decrypt encrypted payment data from client
     *
     * @param string $encryptedData Format: "ENC:base64EncodedData"
     * @return array|null Decrypted payment data or null on failure
     */
    public function decrypt(string $encryptedData): ?array
    {
        try {
            // Verify format
            if (!str_starts_with($encryptedData, self::ENCRYPTION_PREFIX)) {
                throw new \InvalidArgumentException('Invalid encryption format');
            }

            // Remove prefix and decode
            $encryptedPayload = substr($encryptedData, strlen(self::ENCRYPTION_PREFIX));
            $decoded = base64_decode($encryptedPayload, true);

            if ($decoded === false) {
                throw new \RuntimeException('Failed to decode base64 data');
            }

            // Parse encrypted payload
            // Expected format: iv + authTag + ciphertext (JSON encoded)
            $payload = json_decode($decoded, true);

            if (!is_array($payload) || !isset($payload['iv'], $payload['authTag'], $payload['ciphertext'])) {
                throw new \RuntimeException('Invalid encrypted payload structure');
            }

            $iv = base64_decode((string) $payload['iv'], true);
            $authTag = base64_decode((string) $payload['authTag'], true);
            $ciphertext = base64_decode((string) $payload['ciphertext'], true);

            if ($iv === false || $authTag === false || $ciphertext === false) {
                throw new \RuntimeException('Failed to decode encrypted payload components');
            }

            // Decrypt using AES-256-GCM
            $decrypted = openssl_decrypt(
                $ciphertext,
                self::CIPHER_METHOD,
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $authTag
            );

            if ($decrypted === false) {
                throw new \RuntimeException('Decryption failed');
            }

            // Parse decrypted JSON
            $paymentData = json_decode($decrypted, true);

            if (!is_array($paymentData)) {
                throw new \RuntimeException('Invalid decrypted data format');
            }

            $this->logger->info('Payment data decrypted successfully');

            return $paymentData;
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt payment data', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Encrypt data (for testing or client-side key generation)
     *
     * @param array<string, mixed> $data Data to encrypt
     * @return string Encrypted data with prefix
     */
    public function encrypt(array $data): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
        if ($ivLength === false) { // @phpstan-ignore identical.alwaysFalse
            throw new \RuntimeException('Failed to get cipher IV length');
        }

        $iv = openssl_random_pseudo_bytes($ivLength);
        if ($iv === false) { // @phpstan-ignore identical.alwaysFalse
            throw new \RuntimeException('Failed to generate IV');
        }

        $authTag = '';

        $jsonData = json_encode($data);
        if ($jsonData === false) {
            throw new \RuntimeException('Failed to encode data to JSON');
        }

        $ciphertext = openssl_encrypt(
            $jsonData,
            self::CIPHER_METHOD,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $authTag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        $payload = json_encode([
            'iv' => base64_encode($iv),
            'authTag' => base64_encode($authTag),
            'ciphertext' => base64_encode($ciphertext),
        ]);

        if ($payload === false) {
            throw new \RuntimeException('Failed to encode payload to JSON');
        }

        return self::ENCRYPTION_PREFIX . base64_encode($payload);
    }

    /**
     * Validate that data contains required payment fields
     *
     * @param array<string, mixed> $data Data to validate
     * @return bool
     */
    public function validatePaymentData(array $data): bool
    {
        // Check for required fields (card or payment method)
        $card = $data['card'] ?? null;
        $hasCard = is_array($card)
            && isset($card['number'], $card['exp_month'], $card['exp_year'], $card['cvc']);
        $hasPaymentMethod = isset($data['paymentMethod']);

        return $hasCard || $hasPaymentMethod;
    }
}
