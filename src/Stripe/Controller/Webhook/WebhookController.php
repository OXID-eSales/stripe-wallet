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
use OxidEsales\Payments\Stripe\Service\WebhookLogServiceInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;

/**
 * Webhook endpoint controller.
 *
 * Sprint 16: Refactored to use WebhookLogService for logging.
 * Controller only handles HTTP concerns (SRP).
 *
 * URL: /index.php?cl=stripe_webhook
 *
 * @since 2.0.0
 */
class WebhookController extends FrontendController
{
    private ?StripeWebhookProcessor $processor = null;
    private ?WebhookLogServiceInterface $webhookLogger = null;

    /**
     * Initialize services.
     */
    public function init(): void
    {
        parent::init();

        $container = ContainerFactory::getInstance()->getContainer();

        try {
            $this->processor = $container->get(StripeWebhookProcessor::class);
            $this->webhookLogger = $container->get(WebhookLogServiceInterface::class);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Webhook services not available', [
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
        $remoteIp = is_string($remoteIp) ? $remoteIp : 'unknown';

        // Log received webhook
        $this->webhookLogger?->logReceived($payload ?: '', $signature, $remoteIp);

        // Validate input
        if (!is_string($payload) || $payload === '') {
            $this->webhookLogger?->logResult('', 'EMPTY_PAYLOAD', 400);
            http_response_code(400);
            echo json_encode(['error' => 'Empty payload']);
            exit;
        }

        if ($signature === '') {
            $this->webhookLogger?->logResult($payload, 'MISSING_SIGNATURE', 400);
            http_response_code(400);
            echo json_encode(['error' => 'Missing signature header']);
            exit;
        }

        // Check processor availability
        if ($this->processor === null) {
            $this->webhookLogger?->logResult($payload, 'PROCESSOR_UNAVAILABLE', 500);
            http_response_code(500);
            echo json_encode(['error' => 'Webhook processor unavailable']);
            exit;
        }

        // Create request object
        $request = new WebhookRequest(
            payload: $payload,
            signature: $signature,
            remoteIp: $remoteIp,
            receivedAt: new DateTimeImmutable()
        );

        // Process webhook
        $result = $this->processor->process($request);

        // Return response based on result
        if ($result->isFailure()) {
            $statusCode = $result->action === 'signature_invalid' ? 400 : 500;
            $this->webhookLogger?->logResult($payload, "FAILED: {$result->action}", $statusCode);
            http_response_code($statusCode);
            echo json_encode(['error' => $result->error ?? $result->action]);
            exit;
        }

        $this->webhookLogger?->logResult($payload, "SUCCESS: {$result->action}", 200);
        http_response_code(200);
        echo json_encode(['received' => true, 'action' => $result->action]);
        exit;

        // @phpstan-ignore-next-line - unreachable but required for return type
        return '';
    }
}
