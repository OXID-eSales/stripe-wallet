<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

use DateTimeImmutable;
use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Webhook\WebhookRequest;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;

/**
 * Webhook endpoint controller.
 *
 * Handles incoming webhooks from Stripe by:
 * 1. Creating a WebhookRequest from HTTP input
 * 2. Delegating processing to StripeWebhookProcessor
 * 3. Returning appropriate HTTP response
 *
 * The processor handles signature verification, idempotency,
 * logging, and event routing via Template Method pattern.
 *
 * URL: /index.php?cl=stripe_webhook
 *
 * @since Sprint 5: Refactored to use AbstractWebhookProcessor
 */
class WebhookController extends FrontendController
{
    private const WEBHOOK_LOG_FILE = 'log/osc/stripe_webhooks.log';

    private ?StripeWebhookProcessor $processor = null;

    /**
     * Initialize services.
     */
    public function init(): void
    {
        parent::init();

        $container = ContainerFactory::getInstance()->getContainer();

        try {
            $this->processor = $container->get(StripeWebhookProcessor::class);
        } catch (\Exception $e) {
            Registry::getLogger()->error('StripeWebhookProcessor not available', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle incoming webhook.
     *
     * @return string
     */
    public function render(): string
    {
        Registry::getUtils()->setHeader('Content-Type: application/json');

        $payload = file_get_contents('php://input');
        $rawSignature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $signature = is_string($rawSignature) ? $rawSignature : '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Log raw request for debugging
        $this->logRawWebhookRequest($payload, $signature);

        // Validate input
        if (!is_string($payload) || $payload === '') {
            $this->logWebhookResult($payload, 'EMPTY_PAYLOAD', 400);
            http_response_code(400);
            echo json_encode(['error' => 'Empty payload']);
            exit;
        }

        if ($signature === '') {
            $this->logWebhookResult($payload, 'MISSING_SIGNATURE', 400);
            http_response_code(400);
            echo json_encode(['error' => 'Missing signature header']);
            exit;
        }

        // Check processor availability
        if ($this->processor === null) {
            $this->logWebhookResult($payload, 'PROCESSOR_UNAVAILABLE', 500);
            http_response_code(500);
            echo json_encode(['error' => 'Webhook processor unavailable']);
            exit;
        }

        // Create request object
        $request = new WebhookRequest(
            payload: $payload,
            signature: $signature,
            remoteIp: is_string($remoteIp) ? $remoteIp : 'unknown',
            receivedAt: new DateTimeImmutable()
        );

        // Process webhook
        $result = $this->processor->process($request);

        // Return response based on result
        if ($result->isFailure()) {
            $statusCode = $result->action === 'signature_invalid' ? 400 : 500;
            $this->logWebhookResult($payload, "FAILED: {$result->action}", $statusCode);
            http_response_code($statusCode);
            echo json_encode(['error' => $result->error ?? $result->action]);
            exit;
        }

        $this->logWebhookResult($payload, "SUCCESS: {$result->action}", 200);
        http_response_code(200);
        echo json_encode(['received' => true, 'action' => $result->action]);
        exit;

        // @phpstan-ignore-next-line - unreachable but required for return type
        return '';
    }

    /**
     * Log raw incoming webhook HTTP request to file.
     *
     * Logs EVERY incoming request for debugging lost/missing webhooks.
     *
     * @param string|false $payload Raw POST body
     * @param string $sigHeader Stripe signature header
     */
    private function logRawWebhookRequest($payload, string $sigHeader): void
    {
        try {
            $logFile = $this->getWebhookLogFilePath();
            if ($logFile === null) {
                return;
            }

            $eventInfo = $this->parseWebhookEventInfo($payload);
            $logEntry = $this->formatWebhookLogEntry($eventInfo, $sigHeader, $payload);

            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            Registry::getLogger()->warning('Failed to write webhook log file', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get webhook log file path, creating directory if needed.
     */
    private function getWebhookLogFilePath(): ?string
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');
        if (!is_string($shopDir)) {
            return null;
        }

        $logFile = rtrim($shopDir, '/') . '/' . self::WEBHOOK_LOG_FILE;
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        return $logFile;
    }

    /**
     * Parse webhook payload to extract event info.
     *
     * @param string|false $payload
     * @return array<string, string>
     */
    private function parseWebhookEventInfo($payload): array
    {
        $result = ['eventId' => 'unknown', 'eventType' => 'unknown', 'paymentIntentId' => 'unknown'];

        if (!is_string($payload) || $payload === '') {
            return $result;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return $result;
        }

        /** @var array<string, mixed> $data */
        $result['eventId'] = $this->extractStringField($data, 'id');
        $result['eventType'] = $this->extractStringField($data, 'type');
        $result['paymentIntentId'] = $this->extractPaymentIntentId($data);

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractStringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : 'unknown';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractPaymentIntentId(array $data): string
    {
        $dataObj = $data['data'] ?? null;
        if (!is_array($dataObj)) {
            return 'unknown';
        }

        $objData = $dataObj['object'] ?? null;
        if (!is_array($objData)) {
            return 'unknown';
        }

        $id = $objData['id'] ?? $objData['payment_intent'] ?? null;
        return is_string($id) ? $id : 'unknown';
    }

    /**
     * @param array<string, string> $eventInfo
     * @param string|false $payload
     */
    private function formatWebhookLogEntry(array $eventInfo, string $sigHeader, $payload): string
    {
        $timestamp = date('Y-m-d H:i:s.u');
        $requestId = substr(md5(uniqid('', true)), 0, 8);
        $remoteIp = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown';
        $payloadSize = is_string($payload) ? strlen($payload) : 0;

        return sprintf(
            "[%s] [%s] WEBHOOK_RECEIVED\n" .
            "  Event ID:      %s\n" .
            "  Event Type:    %s\n" .
            "  Payment ID:    %s\n" .
            "  Remote IP:     %s\n" .
            "  User Agent:    %s\n" .
            "  Payload Size:  %d bytes\n" .
            "  Has Signature: %s\n" .
            "  Signature:     %s\n" .
            "  ---\n",
            $timestamp,
            $requestId,
            $eventInfo['eventId'],
            $eventInfo['eventType'],
            $eventInfo['paymentIntentId'],
            $remoteIp,
            $userAgent,
            $payloadSize,
            $sigHeader !== '' ? 'YES' : 'NO',
            $sigHeader !== '' ? substr($sigHeader, 0, 50) . '...' : 'NONE'
        );
    }

    /**
     * Log webhook processing result to file.
     *
     * @param string|false $payload
     */
    private function logWebhookResult($payload, string $result, int $httpCode): void
    {
        try {
            $logFile = $this->getWebhookLogFilePath();
            if ($logFile === null) {
                return;
            }

            $eventInfo = $this->parseWebhookEventInfo($payload);

            $logEntry = sprintf(
                "[%s] WEBHOOK_RESULT: %s (HTTP %d) - Event: %s [%s]\n",
                date('Y-m-d H:i:s.u'),
                $result,
                $httpCode,
                $eventInfo['eventType'],
                $eventInfo['eventId']
            );

            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Silent fail - don't break webhook response
        }
    }
}
