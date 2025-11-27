<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Component\Service;

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

            if (!isset($payload['iv'], $payload['authTag'], $payload['ciphertext'])) {
                throw new \RuntimeException('Invalid encrypted payload structure');
            }

            $iv = base64_decode($payload['iv'], true);
            $authTag = base64_decode($payload['authTag'], true);
            $ciphertext = base64_decode($payload['ciphertext'], true);

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
     * @param array $data Data to encrypt
     * @return string Encrypted data with prefix
     */
    public function encrypt(array $data): string
    {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER_METHOD));
        $authTag = '';

        $ciphertext = openssl_encrypt(
            json_encode($data),
            self::CIPHER_METHOD,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $authTag
        );

        $payload = json_encode([
            'iv' => base64_encode($iv),
            'authTag' => base64_encode($authTag),
            'ciphertext' => base64_encode($ciphertext),
        ]);

        return self::ENCRYPTION_PREFIX . base64_encode($payload);
    }

    /**
     * Validate that data contains required payment fields
     */
    public function validatePaymentData(array $data): bool
    {
        // Check for required fields (card or payment method)
        $hasCard = isset($data['card']['number'], $data['card']['exp_month'], $data['card']['exp_year'], $data['card']['cvc']);
        $hasPaymentMethod = isset($data['paymentMethod']);

        return $hasCard || $hasPaymentMethod;
    }
}
