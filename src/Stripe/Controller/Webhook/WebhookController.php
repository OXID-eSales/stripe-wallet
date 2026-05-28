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
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use OxidEsales\Payments\Stripe\Service\WebhookLogServiceInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;

/**
 * Webhook endpoint controller.
 *
 * Sprint 16: Refactored to use WebhookLogService for logging.
 * Sprint 64d: Added guard chain for rate limiting, payload size, IP allowlist.
 * Controller only handles HTTP concerns (SRP).
 *
 * URL: /index.php?cl=stripe_webhook
 *
 * @since 2.0.0
 */
class WebhookController extends FrontendController
{
    protected int $staleThresholdMinutes = 30;

    protected ?StripeWebhookProcessor $processor = null;
    protected ?WebhookLogServiceInterface $webhookLogger = null;
    protected ?RetryCleanupService $cleanupService = null;
    private ?WebhookRequestGuardInterface $guard = null;

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

        try {
            $guard = $container->get(WebhookRequestGuardInterface::class);
            $this->guard = $guard instanceof WebhookRequestGuardInterface ? $guard : null;
        } catch (\Exception $e) {
            Registry::getLogger()->warning('Webhook guard chain unavailable — processing without rate limiting/IP checks', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            /** @var RetryCleanupService $cleanupService */
            $cleanupService = $container->get(RetryCleanupService::class);
            $this->cleanupService = $cleanupService;
        } catch (\Exception $e) {
            Registry::getLogger()->warning('Stale-order cleanup service unavailable', [
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
        $this->setResponseContentType();

        [$payload, $signature, $remoteIp] = $this->extractWebhookInput();

        // Sprint 64d: Run guard chain BEFORE any processing
        $guardResult = $this->getGuard()?->check($payload, $signature, $remoteIp);
        if ($guardResult !== null) {
            $this->sendErrorResponse($payload, $guardResult->message, $guardResult->httpStatusCode, $guardResult->reason);
        }

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

        // STRP-100: Clean up stale NOT_FINISHED orders (>30 min) after each webhook
        $this->cleanupStaleNotFinishedOrders();

        $this->webhookLogger?->logResult($payload, "SUCCESS: {$result->action}", 200);
        http_response_code(200);
        echo json_encode(['received' => true, 'action' => $result->action]);
        exit;

        // @phpstan-ignore-next-line - unreachable but required for return type
        return '';
    }

    /**
     * Set the HTTP response Content-Type header.
     *
     * Protected for testable subclass override (avoids Registry::getUtils() in tests).
     *
     * R-1.5 seam — Sprint 114.3
     */
    protected function setResponseContentType(): void
    {
        Registry::getUtils()->setHeader('Content-Type: application/json');
    }

    /**
     * Get the guard chain (protected for testable subclass override).
     */
    protected function getGuard(): ?WebhookRequestGuardInterface
    {
        return $this->guard;
    }

    /**
     * Extract raw webhook input from PHP globals.
     *
     * Protected for testable subclass override.
     *
     * @return array{string, string, string} [payload, signature, remoteIp]
     */
    protected function extractWebhookInput(): array
    {
        $payload = file_get_contents('php://input');
        $rawSignature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $signature = is_string($rawSignature) ? $rawSignature : '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $remoteIp = is_string($remoteIp) ? $remoteIp : 'unknown';

        return [is_string($payload) ? $payload : '', $signature, $remoteIp];
    }

    /**
     * Clean up stale NOT_FINISHED contracts/orders older than 30 minutes.
     *
     * Runs after each webhook to garbage-collect abandoned checkouts.
     * Failures are logged but do not affect the webhook response.
     *
     * @since 2.0.0 STRP-100
     */
    protected function cleanupStaleNotFinishedOrders(): void
    {
        if ($this->cleanupService === null) {
            return;
        }

        try {
            $cleaned = $this->cleanupService->cleanupStaleContracts($this->staleThresholdMinutes);
            if ($cleaned > 0) {
                Registry::getLogger()->info('Cleaned up ' . $cleaned . ' stale NOT_FINISHED order(s)');
            }
        } catch (\Throwable $e) {
            Registry::getLogger()->warning('Stale order cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send error response and terminate.
     *
     * Protected for testable subclass override.
     *
     * @return never
     */
    protected function sendErrorResponse(string $payload, string $message, int $statusCode, ?string $logAction = null): never
    {
        $this->webhookLogger?->logResult($payload, $logAction ?? "FAILED: {$message}", $statusCode);
        http_response_code($statusCode);
        echo json_encode(['error' => $message]);
        exit;
    }
}
