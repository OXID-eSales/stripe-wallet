<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

use DateTimeImmutable;
use Exception;
use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Webhook\Guard\TrustedProxyClientIp;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardInterface;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookHttpRequest;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Adapter\Helper\ResponseHeaders;
use OxidEsales\Payments\Stripe\Service\WebhookLogServiceInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;
use Psr\Container\ContainerInterface;

/**
 * Webhook endpoint controller.
 *
 * Sprint 16: Refactored to use WebhookLogService for logging.
 * Sprint 64d: Added guard chain for rate limiting, payload size, IP allowlist.
 * VR-19 W4: the chain is payment-base's shared one (stripe.webhook.guard_chain).
 * Controller only handles HTTP concerns (SRP).
 *
 * URL: /index.php?cl=stripe_webhook
 *
 * @since 2.0.0
 */
class WebhookController extends FrontendController
{
    protected ?StripeWebhookProcessor $processor = null;
    protected ?WebhookLogServiceInterface $webhookLogger = null;
    private ?WebhookGuardInterface $guard = null;
    protected ?TrustedProxyClientIp $clientIp = null;
    private ?ThrowableSummaryInterface $throwableSummary = null;
    private float $startedAt = 0.0;

    /**
     * Initialize services.
     */
    public function init(): void
    {
        parent::init();

        $container = ContainerFactory::getInstance()->getContainer();
        $this->throwableSummary = $this->resolveThrowableSummary($container);

        try {
            $this->processor = $container->get(StripeWebhookProcessor::class);
            $this->webhookLogger = $container->get(WebhookLogServiceInterface::class);
        } catch (Exception $e) {
            Registry::getLogger()->error('Webhook services not available', [
                'throwable' => $this->summarize($e),
            ]);
        }

        try {
            $guard = $container->get('stripe.webhook.guard_chain');
            $this->guard = $guard instanceof WebhookGuardInterface ? $guard : null;
            // The caller behind the shop's reverse proxies (payment-base settings "Webhooks").
            $clientIp = $container->get(TrustedProxyClientIp::class);
            $this->clientIp = $clientIp instanceof TrustedProxyClientIp ? $clientIp : null;
        } catch (Exception $e) {
            // Sprint 133 (F4): a security control that cannot be built fails the request, it is not
            // skipped: getGuard() stays null and render() answers 503 instead of processing.
            Registry::getLogger()->error(
                'Webhook guard chain unavailable — endpoint refuses to process',
                ['throwable' => $this->summarize($e)]
            );
        }
    }

    /**
     * The summary service is fetched first and on its own: the failures logged below are the ones that may
     * come from a broken container, and they must still be logged without their message.
     */
    private function resolveThrowableSummary(ContainerInterface $container): ?ThrowableSummaryInterface
    {
        try {
            $summary = $container->get(ThrowableSummaryInterface::class);
        } catch (Exception) {
            return null;
        }

        return $summary instanceof ThrowableSummaryInterface ? $summary : null;
    }

    /**
     * What a log line may say about a failure (VR-18 L1): never its message. Without the summary service it
     * is the class alone.
     *
     * @return array<string, scalar|null>
     */
    private function summarize(Exception $e): array
    {
        return $this->throwableSummary?->fromThrowable($e) ?? ['class' => $e::class];
    }

    /**
     * Handle incoming webhook.
     *
     * @return string
     */
    public function render(): string
    {
        $this->startedAt = microtime(true);
        $this->setResponseContentType();

        [$payload, $signature, $remoteIp] = $this->extractWebhookInput();

        // Sprint 64d: Run guard chain BEFORE any processing. Sprint 133 (F4): no chain means
        // 503, so Stripe retries rather than the endpoint processing unguarded traffic.
        $guard = $this->getGuard();
        if ($guard === null) {
            $this->sendErrorResponse($payload, 'Webhook guards unavailable', 503, 'GUARD_CHAIN_UNAVAILABLE');
        }

        $guardResult = $guard->check($this->buildGuardRequest($payload, $remoteIp));
        if (!$guardResult->ok) {
            $reason = $guardResult->reason ?? 'rejected';
            $this->sendErrorResponse($payload, $reason, $guardResult->httpStatus, $reason);
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
            $this->sendErrorResponse($payload, $result->error ?? $result->action, $statusCode, $result->action);
        }

        $this->logDelivery($payload, $result, 200, $this->processor->lastContractId());
        $this->sendSuccessResponse($result->action);

        // VR-23 T3: the stale NOT_FINISHED sweep is no longer run here: it cost up to two Stripe round trips per stale
        // contract on the request Stripe was waiting for. It is the cron command oe:payments:stripe:sweep.
        $this->terminate();
    }

    /**
     * Answer 200 and release the client.
     *
     * Protected seam for the testable subclass.
     */
    protected function sendSuccessResponse(string $action): void
    {
        $body = (string) json_encode(['received' => true, 'action' => $action]);

        http_response_code(200);
        ignore_user_abort(true);
        if (!function_exists('fastcgi_finish_request')) {
            header('Content-Length: ' . strlen($body));
            header('Connection: close');
        }
        echo $body;

        $this->releaseClient();
    }

    /**
     * Under PHP-FPM the request is finished outright and the worker keeps
     * running; elsewhere the sized body is flushed, so a client honouring
     * Content-Length stops waiting.
     */
    private function releaseClient(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    /**
     * The production end of a handled webhook. Protected seam, so a test can
     * observe the answer and the end instead of losing the process.
     *
     * @return never
     */
    protected function terminate(): never
    {
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

    /**
     * The one webhook file-log line of a delivery (VR-18 L4): ids, action, reason, status, duration.
     */
    protected function logDelivery(string $payload, WebhookResult $result, int $statusCode, ?string $contractId): void
    {
        if ($this->webhookLogger !== null) {
            $this->webhookLogger->logResult($payload, $result, $statusCode, $this->elapsedMilliseconds(), $contractId);
            return;
        }

        Registry::getLogger()->warning('Webhook result not persisted: log service unavailable', [
            'action' => $result->action,
            'reason' => $result->error,
            'status' => $statusCode,
        ]);
    }

    private function elapsedMilliseconds(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
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
     *
     * Without the client-IP resolver the rate limit would key on the proxy: no guard at all.
     */
    protected function getGuard(): ?WebhookGuardInterface
    {
        return $this->clientIp === null ? null : $this->guard;
    }

    /**
     * What the shared guards look at. Protected for testable subclass override.
     */
    protected function buildGuardRequest(string $payload, string $remoteAddr): WebhookHttpRequest
    {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;

        return new WebhookHttpRequest(
            scheme: $this->resolveScheme(),
            clientIp: $this->clientIp?->resolve($remoteAddr, $this->requestHeaders()) ?? $remoteAddr,
            contentLength: is_numeric($contentLength) ? (int) $contentLength : 0,
            rawBody: $payload,
        );
    }

    /**
     * TLS at this origin, or a proxy that terminated it (X-Forwarded-Proto), as the module's
     * HTTPS guard always accepted.
     */
    private function resolveScheme(): string
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && $https !== 'off') {
            return 'https';
        }

        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    }

    /**
     * @return array<string, string> request headers, as TrustedProxyClientIp reads them
     */
    private function requestHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }

        return $headers;
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
     * Send error response and terminate.
     *
     * Protected for testable subclass override.
     *
     * @return never
     */
    protected function sendErrorResponse(string $payload, string $message, int $statusCode, ?string $logAction = null): never
    {
        $this->logDelivery($payload, WebhookResult::failure($logAction ?? 'failed', $message), $statusCode, null);
        $this->emitError($message, $statusCode);
    }

    /**
     * The production end of an error answer. Protected seam, so a test can observe the log line without losing
     * the process.
     *
     * @return never
     */
    protected function emitError(string $message, int $statusCode): never
    {
        http_response_code($statusCode);
        echo json_encode(['error' => $message]);
        exit;
    }
}
