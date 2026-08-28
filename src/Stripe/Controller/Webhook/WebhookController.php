<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

use DateTimeImmutable;
use Exception;
use Throwable;
use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\Payments\Stripe\Adapter\Helper\ResponseHeaders;
use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupSettingsInterface;
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
    /**
     * STRP-168 item 4: one sweep is bounded. It runs inline in this request, so
     * an unbounded backlog would be paid for out of the webhook's response time
     * — and a provider that times out retries, which grows the backlog it was
     * already struggling with. The remainder waits for the next webhook or for
     * oe:payments:not_finished:cleanup; nothing is dropped, only deferred.
     */
    protected const STALE_SWEEP_BATCH = 50;

    /** Used when the module setting cannot be read; the value it had when hardcoded. */
    protected const DEFAULT_STALE_MINUTES = 30;

    protected ?StripeWebhookProcessor $processor = null;
    protected ?WebhookLogServiceInterface $webhookLogger = null;
    protected ?RetryCleanupService $cleanupService = null;
    protected ?NotFinishedOrderCleanupSettingsInterface $cleanupSettings = null;
    private ?WebhookRequestGuardInterface $guard = null;
    private bool $guardChainDegraded = false;

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
        } catch (Exception $e) {
            Registry::getLogger()->error('Webhook services not available', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $guard = $container->get(WebhookRequestGuardInterface::class);
            $this->guard = $guard instanceof WebhookRequestGuardInterface ? $guard : null;
        } catch (Exception $e) {
            // Sprint 133 · Story 5 (F4): this used to log one warning and leave
            // the guard null, and render()'s null-safe call then skipped HTTPS,
            // IP allowlist, payload size AND rate limiting for every request.
            // A security control that cannot be built fails the request, not
            // the control: install the guards that need no container, and mark
            // the endpoint degraded so it answers 503 instead of processing.
            Registry::getLogger()->error(
                'Webhook guard chain unavailable — endpoint degraded, refusing to process',
                ['error' => $e->getMessage()]
            );
            $this->guard = $this->buildMinimalGuardChain();
            $this->guardChainDegraded = true;
        }

        try {
            /** @var RetryCleanupService $cleanupService */
            $cleanupService = $container->get(RetryCleanupService::class);
            $this->cleanupService = $cleanupService;
        } catch (Exception $e) {
            Registry::getLogger()->warning('Stale-order cleanup service unavailable', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            /** @var NotFinishedOrderCleanupSettingsInterface $cleanupSettings */
            $cleanupSettings = $container->get(NotFinishedOrderCleanupSettingsInterface::class);
            $this->cleanupSettings = $cleanupSettings;
        } catch (Exception $e) {
            // The sweep still runs, on the default horizon — but say so, rather
            // than letting a shop that raised the setting quietly keep sweeping
            // at 30 minutes and cancelling checkouts its customers are still in.
            Registry::getLogger()->warning(
                'Stale-checkout timeout setting unavailable — sweeping on the default horizon',
                ['error' => $e->getMessage(), 'defaultMinutes' => self::DEFAULT_STALE_MINUTES]
            );
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
        $guard = $this->getGuard();
        $guardResult = $guard?->check($payload, $signature, $remoteIp);
        if ($guardResult !== null) {
            $this->sendErrorResponse($payload, $guardResult->message, $guardResult->httpStatusCode, $guardResult->reason);
        }

        // Sprint 133 (F4): no guards, or only the minimal fallback ones, means
        // the endpoint is not fully protected — answer 503 so Stripe retries
        // rather than processing unguarded traffic.
        if ($guard === null || $this->guardChainDegraded) {
            $this->sendErrorResponse(
                $payload,
                'Webhook guards unavailable',
                503,
                'GUARD_CHAIN_UNAVAILABLE'
            );
        }

        $this->processWebhook($payload, $signature, $remoteIp);

        return '';
    }

    /**
     * Everything after the guard chain: audit log, validation, dispatch.
     *
     * Protected seam (module CLAUDE.md testable-subclass pattern) so the
     * guard/fail-closed decision above can be unit-tested without a shop.
     */
    protected function processWebhook(string $payload, string $signature, string $remoteIp): void
    {
        $this->logReceived($payload, $signature, $remoteIp);

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

        $this->logResult($payload, "SUCCESS: {$result->action}", 200);
        http_response_code(200);
        echo json_encode(['received' => true, 'action' => $result->action]);
        exit;
    }

    /**
     * Sprint 133 (F4): the audit trail used to vanish silently when
     * WebhookLogServiceInterface could not be built (`$this->webhookLogger?->`),
     * leaving zero oe_payments_webhooklogs rows while processing continued.
     * Mirrors the $fallbackLogger idiom already used by RequestLogService.
     */
    private function logReceived(string $payload, string $signature, string $remoteIp): void
    {
        if ($this->webhookLogger !== null) {
            $this->webhookLogger->logReceived($payload, $signature, $remoteIp);
            return;
        }

        Registry::getLogger()->warning('Webhook received but the webhook log service is unavailable', [
            'remote_ip' => $remoteIp,
            'payload_bytes' => strlen($payload),
        ]);
    }

    private function logResult(string $payload, string $action, int $statusCode): void
    {
        if ($this->webhookLogger !== null) {
            $this->webhookLogger->logResult($payload, $action, $statusCode);
            return;
        }

        Registry::getLogger()->warning('Webhook result not persisted: log service unavailable', [
            'action' => $action,
            'status' => $statusCode,
        ]);
    }

    /**
     * Guards that need no container and no database, so the cheap invariants
     * still hold when DI is broken.
     */
    /**
     * Seam for the testable subclass; production sets this from init().
     */
    protected function setGuardChainDegraded(bool $degraded): void
    {
        $this->guardChainDegraded = $degraded;
    }

    protected function buildMinimalGuardChain(): WebhookRequestGuardInterface
    {
        return new WebhookGuardChain([
            new WebhookHttpsGuard(),
            new WebhookPayloadSizeGuard(),
        ]);
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
        $utils = Registry::getUtils();
        $utils->setHeader('Content-Type: application/json');
        ResponseHeaders::applySecurity(static function (string $header) use ($utils): void {
            $utils->setHeader($header);
        });
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
    /**
     * How long a checkout may sit in flight before this sweep releases it.
     *
     * STRP-168 item 3: was a hardcoded 30, which a shop selling by bank transfer
     * or Klarna could not raise without a code change — so the sweep cancelled
     * checkouts its customers were still legitimately in.
     */
    protected function getStaleThresholdMinutes(): int
    {
        return $this->cleanupSettings?->getStaleCheckoutMinutes() ?? self::DEFAULT_STALE_MINUTES;
    }

    protected function cleanupStaleNotFinishedOrders(): void
    {
        if ($this->cleanupService === null) {
            return;
        }

        try {
            $cleaned = $this->cleanupService->cleanupStaleContracts(
                $this->getStaleThresholdMinutes(),
                self::STALE_SWEEP_BATCH
            );
            if ($cleaned > 0) {
                Registry::getLogger()->info('Cleaned up ' . $cleaned . ' stale NOT_FINISHED order(s)');
            }
        } catch (Throwable $e) {
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
        $this->logResult($payload, $logAction ?? "FAILED: {$message}", $statusCode);
        http_response_code($statusCode);
        echo json_encode(['error' => $message]);
        exit;
    }
}
