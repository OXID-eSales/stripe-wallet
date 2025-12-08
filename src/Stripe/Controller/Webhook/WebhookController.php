<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Controller\Webhook;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidSolutionCatalysts\Payments\Component\Service\WebhookLogServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Webhook endpoint controller
 * Handles incoming webhooks from Stripe
 *
 * URL: /index.php?cl=stripe_webhook
 */
class WebhookController extends FrontendController
{
    private const WEBHOOK_LOG_FILE = 'log/osc/stripe_webhooks.log';

    private ModuleConfigurationService $config;
    private ?WebhookProcessingService $webhookService = null;
    private ?WebhookLogServiceInterface $webhookLogService = null;

    /**
     * Initialize services
     *
     * Uses Symfony DI container for services with constructor dependencies.
     * Registry::get() does NOT support dependency injection.
     */
    public function init(): void
    {
        parent::init();

        // Use DI container for services with constructor dependencies
        $container = ContainerFactory::getInstance()->getContainer();

        // ModuleConfigurationService requires DI (ContextInterface, ModuleConfigurationDaoInterface)
        $this->config = $container->get(ModuleConfigurationService::class);

        // WebhookProcessingService requires DI (multiple dependencies)
        try {
            $this->webhookService = $container->get(WebhookProcessingService::class);
        } catch (\Exception $e) {
            // Service not yet implemented, use basic processing
            Registry::getLogger()->debug('WebhookProcessingService not available, using basic processing');
        }

        // Sprint 7 Phase 4: Use WebhookLogService for proper layering
        try {
            $this->webhookLogService = $container->get(WebhookLogServiceInterface::class);
        } catch (\Exception $e) {
            Registry::getLogger()->debug('WebhookLogService not available');
        }
    }

    /**
     * Handle incoming webhook
     * Verifies signature and processes event
     *
     * @return string
     */
    public function render(): string
    {
        // Set JSON header
        Registry::getUtils()->setHeader('Content-Type: application/json');

        // Get raw POST body early for logging
        $payload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        // Log raw incoming request FIRST (before any validation)
        $this->logRawWebhookRequest($payload, $sigHeader);

        try {
            if (empty($payload)) {
                throw new \Exception('Empty payload');
            }

            if (empty($sigHeader)) {
                throw new \Exception('Missing signature header');
            }

            // Verify webhook signature
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $this->config->getWebhookSecret()
            );

            Registry::getLogger()->info('Webhook received', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);

            // Process webhook event
            if ($this->webhookService) {
                $this->webhookService->processEvent($event);
            } else {
                // Basic processing if service not available
                $this->processEventBasic($event);
            }

            // Return success response
            $this->logWebhookResult($payload, 'SUCCESS', 200);
            http_response_code(200);
            echo json_encode(['received' => true]);
        } catch (SignatureVerificationException $e) {
            // Invalid signature
            Registry::getLogger()->error('Webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            $this->logWebhookResult($payload, 'SIGNATURE_FAILED: ' . $e->getMessage(), 400);
            http_response_code(400);
            echo json_encode(['error' => 'Invalid signature']);
        } catch (\Exception $e) {
            // Processing error
            Registry::getLogger()->error('Webhook processing failed', [
                'error' => $e->getMessage(),
            ]);

            $this->logWebhookResult($payload, 'PROCESSING_FAILED: ' . $e->getMessage(), 500);
            http_response_code(500);
            echo json_encode(['error' => 'Processing failed']);
        }

        exit;
        // @phpstan-ignore-next-line - unreachable but required for return type
        return '';
    }

    /**
     * Basic webhook processing (fallback)
     *
     * @param \Stripe\Event $event
     */
    private function processEventBasic($event): void
    {
        // Log webhook event
        $this->logWebhookEvent($event);

        // Handle common events
        switch ($event->type) {
            case 'payment_intent.succeeded':
                Registry::getLogger()->info('Payment succeeded', [
                    'payment_intent_id' => $event->data->object->id,
                ]);
                break;

            case 'payment_intent.payment_failed':
                Registry::getLogger()->warning('Payment failed', [
                    'payment_intent_id' => $event->data->object->id,
                ]);
                break;

            case 'charge.refunded':
                Registry::getLogger()->info('Charge refunded', [
                    'charge_id' => $event->data->object->id,
                ]);
                break;

            default:
                Registry::getLogger()->debug('Unhandled webhook event', [
                    'event_type' => $event->type,
                ]);
        }
    }

    /**
     * Log webhook event to database
     *
     * Sprint 7 Phase 4: Uses WebhookLogService for proper layering.
     * Falls back to direct SQL if service not available.
     *
     * @param \Stripe\Event $event
     */
    private function logWebhookEvent($event): void
    {
        try {
            // Sprint 7 Phase 4: Use service if available
            if ($this->webhookLogService !== null) {
                /** @var array<string, mixed> $payload */
                $payload = $event->data->object->toArray();
                $this->webhookLogService->logEventReceived(
                    $event->id,
                    $event->type,
                    $payload,
                    'stripe'
                );
                return;
            }

            // Fallback to direct SQL (legacy)
            $db = \OxidEsales\Eshop\Core\DatabaseProvider::getDb();

            $sql = "INSERT INTO osc_payment_webhooklogs
                    (OXID, OXEVENTID, OXEVENTTYPE, OXPROVIDER, OXPAYLOAD, OXSTATUS, OXRECEIVEDAT)
                    VALUES (?, ?, ?, 'stripe', ?, 'received', NOW())
                    ON DUPLICATE KEY UPDATE
                    OXSTATUS = 'duplicate'";

            $db->execute($sql, [
                \OxidEsales\Eshop\Core\UtilsObject::getInstance()->generateUId(),
                $event->id,
                $event->type,
                json_encode($event->data->object->toArray()),
            ]);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to log webhook', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log raw incoming webhook HTTP request to file
     *
     * This logs EVERY incoming request to the webhook endpoint,
     * regardless of whether it passes validation or not.
     * Useful for debugging lost/missing webhooks.
     *
     * Log file: source/log/osc/stripe_webhooks.log
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
     * @return array<string, string> Array with eventId, eventType, paymentIntentId keys
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
     * Extract a string field from array with fallback.
     *
     * @param array<string, mixed> $data
     */
    private function extractStringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : 'unknown';
    }

    /**
     * Extract payment intent ID from webhook data structure.
     *
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
     * Format webhook log entry.
     *
     * @param array<string, string> $eventInfo Event info with eventId, eventType, paymentIntentId
     * @param string $sigHeader
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
     * Log webhook processing result to file
     *
     * @param string|false $payload Raw POST body
     * @param string $result Result status (SUCCESS, SIGNATURE_FAILED, PROCESSING_FAILED)
     * @param int $httpCode HTTP response code
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
