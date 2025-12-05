<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Helper;

/**
 * Helper for testing Stripe webhooks.
 *
 * Provides utilities for generating valid webhook signatures,
 * creating test payloads, and working with Stripe CLI.
 *
 * @since Sprint 13
 */
final class StripeWebhookTestHelper
{
    /**
     * Generate a valid Stripe webhook signature.
     *
     * @param string $payload The raw payload string
     * @param string $secret The webhook secret (whsec_xxx)
     * @param int|null $timestamp Unix timestamp (defaults to now)
     * @return string The signature header value
     */
    public static function generateSignature(
        string $payload,
        string $secret,
        ?int $timestamp = null
    ): string {
        $timestamp = $timestamp ?? time();
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Create a test payment_intent.succeeded event payload.
     *
     * @param string $paymentIntentId The payment intent ID
     * @param int $amountCents Amount in cents
     * @param string $currency Currency code
     * @return string JSON payload
     */
    public static function createPaymentIntentSucceededPayload(
        string $paymentIntentId,
        int $amountCents = 5000,
        string $currency = 'eur'
    ): string {
        $eventId = 'evt_test_' . substr(md5($paymentIntentId . microtime()), 0, 16);
        $timestamp = time();

        $payload = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'created' => $timestamp,
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'object' => 'payment_intent',
                    'amount' => $amountCents,
                    'currency' => $currency,
                    'status' => 'succeeded',
                    'charges' => [
                        'data' => [
                            [
                                'id' => 'ch_' . substr(md5($paymentIntentId), 0, 16),
                                'paid' => true,
                                'created' => $timestamp,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Create a test charge.refunded event payload.
     *
     * @param string $paymentIntentId The payment intent ID
     * @param int $amountRefundedCents Refunded amount in cents
     * @return string JSON payload
     */
    public static function createChargeRefundedPayload(
        string $paymentIntentId,
        int $amountRefundedCents = 1000
    ): string {
        $eventId = 'evt_test_' . substr(md5($paymentIntentId . 'refund' . microtime()), 0, 16);
        $chargeId = 'ch_' . substr(md5($paymentIntentId), 0, 16);

        $payload = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'charge.refunded',
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => $chargeId,
                    'object' => 'charge',
                    'payment_intent' => $paymentIntentId,
                    'amount_refunded' => $amountRefundedCents,
                ],
            ],
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Create a test checkout.session.completed event payload.
     *
     * @param string $sessionId The checkout session ID
     * @param string $paymentIntentId The payment intent ID
     * @return string JSON payload
     */
    public static function createCheckoutSessionCompletedPayload(
        string $sessionId,
        string $paymentIntentId
    ): string {
        $eventId = 'evt_test_' . substr(md5($sessionId . microtime()), 0, 16);

        $payload = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'object' => 'checkout.session',
                    'payment_intent' => $paymentIntentId,
                    'payment_status' => 'paid',
                    'status' => 'complete',
                ],
            ],
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Parse a Stripe signature header to extract components.
     *
     * @param string $signature The signature header value
     * @return array{timestamp: int|null, signatures: array<string>}
     */
    public static function parseSignature(string $signature): array
    {
        $result = ['timestamp' => null, 'signatures' => []];

        $parts = explode(',', $signature);
        foreach ($parts as $part) {
            [$key, $value] = explode('=', $part, 2) + [null, null];
            if ($key === 't') {
                $result['timestamp'] = (int) $value;
            } elseif ($key === 'v1') {
                $result['signatures'][] = $value;
            }
        }

        return $result;
    }

    /**
     * Verify a webhook signature.
     *
     * @param string $payload The raw payload
     * @param string $signature The signature header
     * @param string $secret The webhook secret
     * @param int $tolerance Maximum age in seconds
     * @return bool True if signature is valid
     */
    public static function verifySignature(
        string $payload,
        string $signature,
        string $secret,
        int $tolerance = 300
    ): bool {
        $parsed = self::parseSignature($signature);

        if ($parsed['timestamp'] === null || empty($parsed['signatures'])) {
            return false;
        }

        // Check timestamp tolerance
        if (abs(time() - $parsed['timestamp']) > $tolerance) {
            return false;
        }

        // Compute expected signature
        $signedPayload = "{$parsed['timestamp']}.{$payload}";
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        return in_array($expectedSignature, $parsed['signatures'], true);
    }
}
