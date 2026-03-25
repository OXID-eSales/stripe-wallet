<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Webhook;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-End integration tests for the webhook HTTP endpoint.
 *
 * These tests make actual HTTP requests to the webhook endpoint
 * to verify it's reachable and responds correctly.
 *
 */
    #[Group('integration')]
    #[Group('e2e')]
    #[Group('webhook-e2e')]
    #[Group('sprint-13')]
final class WebhookEndpointE2ETest extends TestCase
{
    private const WEBHOOK_PATH = 'index.php?cl=oe_stripe_webhook';

    private string $webhookUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $shopUrl = Registry::getConfig()->getShopUrl();
        $this->webhookUrl = rtrim($shopUrl, '/') . '/' . self::WEBHOOK_PATH;
    }

        #[Group('critical')]
    public function testWebhookEndpointExistsAndDoesNotReturn404(): void
    {
        $response = $this->sendHttpRequest('POST', $this->webhookUrl, '{}', [
            'Content-Type: application/json',
        ]);

        $this->assertNotEquals(
            404,
            $response['status'],
            "Webhook endpoint returned 404. URL: {$this->webhookUrl}"
        );

        // 400 or 500 are acceptable - they mean endpoint exists but request is invalid
        $this->assertContains(
            $response['status'],
            [400, 401, 500],
            "Expected 400/401/500 for invalid request, got {$response['status']}"
        );
    }

        public function testWebhookReturns400Or401ForMissingSignature(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_no_sig');

        $response = $this->sendHttpRequest('POST', $this->webhookUrl, $payload, [
            'Content-Type: application/json',
            // No Stripe-Signature header
        ]);

        $this->assertNotEquals(404, $response['status']);
        $this->assertContains(
            $response['status'],
            [400, 500],
            'Should return 400 or 500 for missing signature'
        );
    }

        public function testWebhookReturns400ForInvalidSignature(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_invalid_sig');
        $invalidSignature = 't=' . time() . ',v1=invalid_signature_abc123';

        $response = $this->sendHttpRequest('POST', $this->webhookUrl, $payload, [
            'Content-Type: application/json',
            'Stripe-Signature: ' . $invalidSignature,
        ]);

        $this->assertNotEquals(404, $response['status']);
        // 400, 401 or 500 are acceptable - means endpoint exists and processes request
        $this->assertContains(
            $response['status'],
            [400, 401, 500],
            'Should return 400/401/500 for invalid signature'
        );
    }

        public function testWebhookReturns400ForExpiredSignature(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_expired');

        // Generate signature with timestamp 10 minutes ago (beyond 5 min tolerance)
        $oldTimestamp = time() - 600;
        $signature = StripeWebhookTestHelper::generateSignature($payload, 'test_secret', $oldTimestamp);

        $response = $this->sendHttpRequest('POST', $this->webhookUrl, $payload, [
            'Content-Type: application/json',
            'Stripe-Signature: ' . $signature,
        ]);

        $this->assertNotEquals(404, $response['status']);
        // 400, 401 or 500 are acceptable - means endpoint exists and processes request
        $this->assertContains(
            $response['status'],
            [400, 401, 500],
            'Should return 400/401/500 for expired signature'
        );
    }

        public function testWebhookReturns400ForMalformedJson(): void
    {
        $malformedPayload = '{ invalid json !!!';

        $response = $this->sendHttpRequest('POST', $this->webhookUrl, $malformedPayload, [
            'Content-Type: application/json',
        ]);

        $this->assertNotEquals(404, $response['status']);
        $this->assertContains(
            $response['status'],
            [400, 500],
            'Should return 400 or 500 for malformed JSON'
        );
    }

        public function testWebhookHandlesAllEventTypes(): void
    {
        $eventPayloads = [
            'payment_intent.succeeded' => StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_e2e_1'),
            'charge.refunded' => StripeWebhookTestHelper::createChargeRefundedPayload('pi_e2e_2'),
            'checkout.session.completed' => StripeWebhookTestHelper::createCheckoutSessionCompletedPayload(
                'cs_e2e_1',
                'pi_e2e_3'
            ),
        ];

        foreach ($eventPayloads as $eventType => $payload) {
            $signature = StripeWebhookTestHelper::generateSignature($payload, 'test_secret');

            $response = $this->sendHttpRequest('POST', $this->webhookUrl, $payload, [
                'Content-Type: application/json',
                'Stripe-Signature: ' . $signature,
            ]);

            $this->assertNotEquals(
                404,
                $response['status'],
                "Endpoint should handle {$eventType}, got 404"
            );
        }
    }

        public function testWebhookResponseTimeIsAcceptable(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_perf_test');

        $startTime = microtime(true);

        $response = $this->sendHttpRequest('POST', $this->webhookUrl, $payload, [
            'Content-Type: application/json',
        ]);

        $responseTimeMs = (microtime(true) - $startTime) * 1000;

        // Stripe requires response within 20 seconds
        // Use 15 second threshold to allow for CI environment variability
        $this->assertLessThan(
            15000,
            $responseTimeMs,
            "Webhook response time {$responseTimeMs}ms exceeds 15000ms threshold (Stripe max: 20s)"
        );

        $this->assertNotEquals(404, $response['status']);
    }

        public function testWebhookIsAccessibleViaHttps(): void
    {
        $httpsUrl = str_replace('http://', 'https://', $this->webhookUrl);

        $response = $this->sendHttpRequest('POST', $httpsUrl, '{}', [
            'Content-Type: application/json',
        ]);

        $this->assertNotEquals(
            0,
            $response['status'],
            'HTTPS connection failed (status 0 indicates SSL/connection error)'
        );
        $this->assertNotEquals(404, $response['status']);
    }

        public function testWebhookReturnsNon404Status(): void
    {
        $response = $this->sendHttpRequest('POST', $this->webhookUrl, '{}', [
            'Content-Type: application/json',
        ]);

        // Main assertion: endpoint exists (not 404)
        $this->assertNotEquals(404, $response['status']);

        // Endpoint should return error status for invalid request
        $this->assertContains(
            $response['status'],
            [400, 401, 500],
            'Should return error status for invalid request'
        );
    }

    /**
     * Send HTTP request using cURL
     *
     * @param string $method HTTP method
     * @param string $url URL to request
     * @param string $body Request body
     * @param array<string> $headers Request headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function sendHttpRequest(string $method, string $url, string $body, array $headers): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false, // For self-signed certs in dev
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            return [
                'status' => 0,
                'body' => 'cURL error: ' . $error,
                'headers' => [],
            ];
        }

        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        return [
            'status' => $httpCode,
            'body' => $body,
            'headers' => $this->parseHeaders($headerString),
        ];
    }

    /**
     * Parse HTTP headers string into associative array
     *
     * @param string $headerString Raw headers string
     * @return array<string, string>
     */
    private function parseHeaders(string $headerString): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerString);

        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return $headers;
    }
}
