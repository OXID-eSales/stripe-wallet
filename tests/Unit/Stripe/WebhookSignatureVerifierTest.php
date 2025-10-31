<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe;

use OxidSolutionCatalysts\Payments\Stripe\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeObject;
use Stripe\Webhook;

/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\WebhookSignatureVerifier
 */
final class WebhookSignatureVerifierTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_test_secret';
    private WebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new WebhookSignatureVerifier(self::WEBHOOK_SECRET);
    }

    public function testVerifiesValidSignature(): void
    {
        $payload = json_encode([
            'id' => 'evt_test',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123']],
        ]);

        $timestamp = time();
        $signature = $this->generateValidSignature($payload, $timestamp);

        $result = $this->verifier->verify($payload, $signature);

        $this->assertTrue($result);
    }

    public function testRejectsInvalidSignature(): void
    {
        $payload = '{"id": "evt_test"}';
        $signature = 'invalid_signature';

        $result = $this->verifier->verify($payload, $signature);

        $this->assertFalse($result);
    }

    public function testRejectsMissingSignature(): void
    {
        $payload = '{"id": "evt_test"}';
        $signature = '';

        $result = $this->verifier->verify($payload, $signature);

        $this->assertFalse($result);
    }

    public function testRejectsExpiredSignature(): void
    {
        $payload = json_encode(['id' => 'evt_test']);
        $expiredTimestamp = time() - 400;
        $signature = $this->generateValidSignature($payload, $expiredTimestamp);

        $result = $this->verifier->verify($payload, $signature);

        $this->assertFalse($result);
    }

    public function testRejectsSignatureWithWrongSecret(): void
    {
        $payload = json_encode(['id' => 'evt_test']);
        $timestamp = time();

        $wrongSecret = 'whsec_wrong_secret';
        $signedPayload = "$timestamp.$payload";
        $wrongSignature = hash_hmac('sha256', $signedPayload, $wrongSecret);
        $signature = "t=$timestamp,v1=$wrongSignature";

        $result = $this->verifier->verify($payload, $signature);

        $this->assertFalse($result);
    }

    public function testParsesEventSuccessfully(): void
    {
        $payload = json_encode([
            'id' => 'evt_parse_test',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_123',
                    'status' => 'succeeded',
                ],
            ],
            'created' => 1234567890,
        ]);

        $timestamp = time();
        $signature = $this->generateValidSignature($payload, $timestamp);

        $result = $this->verifier->parseEvent($payload, $signature);

        $this->assertSame('evt_parse_test', $result['id']);
        $this->assertSame('payment_intent.succeeded', $result['type']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('created', $result);
    }

    private function generateValidSignature(string $payload, int $timestamp): string
    {
        $signedPayload = "$timestamp.$payload";
        $signature = hash_hmac('sha256', $signedPayload, self::WEBHOOK_SECRET);
        return "t=$timestamp,v1=$signature";
    }
}
