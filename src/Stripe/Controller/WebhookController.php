<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService;

/**
 * Webhook endpoint controller
 * Handles incoming webhooks from Stripe
 *
 * URL: /index.php?cl=stripe_webhook
 */
class WebhookController extends FrontendController
{
    private ModuleConfigurationService $config;
    private ?WebhookProcessingService $webhookService = null;

    /**
     * Initialize services
     */
    public function init(): void
    {
        parent::init();

        $this->config = Registry::get(ModuleConfigurationService::class);

        // Webhook service is optional (requires implementation)
        try {
            $this->webhookService = Registry::get(WebhookProcessingService::class);
        } catch (\Exception $e) {
            // Service not yet implemented, use basic processing
            Registry::getLogger()->debug('WebhookProcessingService not available, using basic processing');
        }
    }

    /**
     * Handle incoming webhook
     * Verifies signature and processes event
     *
     * @return void
     */
    public function render()
    {
        // Set JSON header
        Registry::getUtils()->setHeader('Content-Type: application/json');

        try {
            // Get raw POST body
            $payload = file_get_contents('php://input');
            $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

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
            http_response_code(200);
            echo json_encode(['received' => true]);

        } catch (SignatureVerificationException $e) {
            // Invalid signature
            Registry::getLogger()->error('Webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            http_response_code(400);
            echo json_encode(['error' => 'Invalid signature']);

        } catch (\Exception $e) {
            // Processing error
            Registry::getLogger()->error('Webhook processing failed', [
                'error' => $e->getMessage(),
            ]);

            http_response_code(500);
            echo json_encode(['error' => 'Processing failed']);
        }

        exit;
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
     * @param \Stripe\Event $event
     */
    private function logWebhookEvent($event): void
    {
        try {
            $db = \OxidEsales\Eshop\Core\DatabaseProvider::getDb();

            $sql = "INSERT INTO osc_payment_webhook_log
                    (OXID, OXEVENTID, OXEVENTTYPE, OXPROVIDER, OXPAYLOAD, OXSTATUS, OXCREATED)
                    VALUES (?, ?, ?, 'stripe', ?, 'received', NOW())
                    ON DUPLICATE KEY UPDATE
                    OXSTATUS = 'duplicate',
                    OXUPDATED = NOW()";

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
}
