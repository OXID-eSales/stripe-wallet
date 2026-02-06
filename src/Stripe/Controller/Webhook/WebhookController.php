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

        [$payload, $signature, $remoteIp] = $this->extractWebhookInput();

        $this->webhookLogger?->logReceived($payload, $signature, $remoteIp);

        if ($payload === '') {
            $this->sendErrorResponse('', 'Empty payload', 400, 'EMPTY_PAYLOAD');
        }

        if ($signature === '') {
            $this->sendErrorResponse($payload, 'Missing signature header', 400, 'MISSING_SIGNATURE');
        }

        if ($this->processor === null) {
            $this->sendErrorResponse($payload, 'Webhook processor unavailable', 500, 'PROCESSOR_UNAVAILABLE');
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
            $this->sendErrorResponse($payload, $result->error ?? $result->action, $statusCode);
        }

        $this->webhookLogger?->logResult($payload, "SUCCESS: {$result->action}", 200);
        http_response_code(200);
        echo json_encode(['received' => true, 'action' => $result->action]);
        exit;

        // @phpstan-ignore-next-line - unreachable but required for return type
        return '';
    }

    /**
     * Extract raw webhook input from PHP globals.
     *
     * @return array{string, string, string} [payload, signature, remoteIp]
     */
    private function extractWebhookInput(): array
    {
        $payload = file_get_contents('php://input');
        $rawSignature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $signature = is_string($rawSignature) ? $rawSignature : '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $remoteIp = is_string($remoteIp) ? $remoteIp : 'unknown';

        return [is_string($payload) ? $payload : '', $signature, $remoteIp];
    }

    /**
     * Send error response and terminate.
     *
     * @return never
     */
    private function sendErrorResponse(string $payload, string $message, int $statusCode, ?string $logAction = null): never
    {
        $this->webhookLogger?->logResult($payload, $logAction ?? "FAILED: {$message}", $statusCode);
        http_response_code($statusCode);
        echo json_encode(['error' => $message]);
        exit;
    }
}
