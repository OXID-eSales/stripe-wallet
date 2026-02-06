<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Adapter\Request\AuthorizePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CaptureAuthorizationRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CreatePaymentMethodRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\ReauthorizePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\ThreeDSecureRequest;
use OxidEsales\PaymentComponent\Adapter\Request\VoidAuthorizationRequest;
use OxidEsales\PaymentComponent\Adapter\Request\VoidPaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\AuthorizationResponse;
use OxidEsales\PaymentComponent\Adapter\Response\CancellationResponse;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Adapter\Response\CreatePaymentResponse;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentMethodResponse;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentResponse;
use OxidEsales\PaymentComponent\Adapter\Response\RefundResponse;
use OxidEsales\PaymentComponent\Adapter\Response\ThreeDSecureResponse;
use OxidEsales\PaymentComponent\Adapter\WebhookEvent;
use OxidEsales\PaymentComponent\Contract\IdempotencyRecord;
use OxidEsales\PaymentComponent\Repository\IdempotencyRepositoryInterface;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Refund;

/**
 * Decorator that adds idempotency protection to capture and refund operations.
 *
 * Wraps any StripeAdapterInterface and prevents duplicate API calls by
 * checking/storing results in the idempotency repository.
 *
 * Protected operations:
 * - capturePayment() — key: capture:{providerPaymentId}
 * - refundPayment() — key: refund:{providerPaymentId}
 * - createRefundByCharge() — key: refund_charge:{chargeId}
 *
 * Sprint 42: Idempotency implementation.
 *
 * @since 1.0.0
 */
class IdempotentStripeAdapter implements StripeAdapterInterface
{
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';
    private const DEFAULT_TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly StripeAdapterInterface $inner,
        private readonly IdempotencyRepositoryInterface $repository,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
    }

    // ==========================================
    // IDEMPOTENT OPERATIONS
    // ==========================================

    public function capturePayment(CapturePaymentRequest $request): CaptureResponse
    {
        $key = 'capture:' . $request->providerPaymentId;
        $existing = $this->repository->findByKey($key);

        if ($existing !== null && !$existing->isExpired()) {
            if ($existing->getStatus() === self::STATUS_COMPLETED && $existing->getResult() !== null) {
                return $this->deserializeCaptureResponse($existing->getResult());
            }

            if ($existing->getStatus() === self::STATUS_PROCESSING) {
                throw new \RuntimeException('Capture operation already in progress for: ' . $request->providerPaymentId);
            }
        }

        $record = $this->reuseOrCreateRecord($existing, $key, $request->providerPaymentId, 'capture');
        $this->repository->save($record);

        try {
            $result = $this->inner->capturePayment($request);
            $record->setStatus(self::STATUS_COMPLETED);
            $record->setResult($this->serializeCaptureResponse($result));
            $this->repository->save($record);
            return $result;
        } catch (\Throwable $e) {
            $record->setStatus(self::STATUS_FAILED);
            $record->setResult(json_encode(['error' => $e->getMessage()]) ?: null);
            $this->repository->save($record);
            throw $e;
        }
    }

    public function refundPayment(RefundPaymentRequest $request): RefundResponse
    {
        $key = 'refund:' . $request->providerPaymentId;
        $existing = $this->repository->findByKey($key);

        if ($existing !== null && !$existing->isExpired()) {
            if ($existing->getStatus() === self::STATUS_COMPLETED && $existing->getResult() !== null) {
                return $this->deserializeRefundResponse($existing->getResult());
            }

            if ($existing->getStatus() === self::STATUS_PROCESSING) {
                throw new \RuntimeException('Refund operation already in progress for: ' . $request->providerPaymentId);
            }
        }

        $record = $this->reuseOrCreateRecord($existing, $key, $request->providerPaymentId, 'refund');
        $this->repository->save($record);

        try {
            $result = $this->inner->refundPayment($request);
            $record->setStatus(self::STATUS_COMPLETED);
            $record->setResult($this->serializeRefundResponse($result));
            $this->repository->save($record);
            return $result;
        } catch (\Throwable $e) {
            $record->setStatus(self::STATUS_FAILED);
            $record->setResult(json_encode(['error' => $e->getMessage()]) ?: null);
            $this->repository->save($record);
            throw $e;
        }
    }

    public function createRefundByCharge(
        string $chargeId,
        ?int $amount = null,
        ?string $reason = null,
        ?array $metadata = null
    ): Refund {
        $key = 'refund_charge:' . $chargeId;
        $existing = $this->repository->findByKey($key);

        if ($existing !== null && !$existing->isExpired()) {
            if ($existing->getStatus() === self::STATUS_PROCESSING) {
                throw new \RuntimeException('Refund by charge operation already in progress for: ' . $chargeId);
            }
            // For Stripe SDK objects, we cannot reliably cache/deserialize,
            // so we only protect against concurrent processing
        }

        $record = $this->reuseOrCreateRecord($existing, $key, $chargeId, 'refund_charge');
        $this->repository->save($record);

        try {
            $result = $this->inner->createRefundByCharge($chargeId, $amount, $reason, $metadata);
            $record->setStatus(self::STATUS_COMPLETED);
            $this->repository->save($record);
            return $result;
        } catch (\Throwable $e) {
            $record->setStatus(self::STATUS_FAILED);
            $record->setResult(json_encode(['error' => $e->getMessage()]) ?: null);
            $this->repository->save($record);
            throw $e;
        }
    }

    // ==========================================
    // DELEGATED OPERATIONS (no idempotency)
    // ==========================================

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        return $this->inner->createPayment($request);
    }

    public function voidPayment(VoidPaymentRequest $request): CancellationResponse
    {
        return $this->inner->voidPayment($request);
    }

    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse
    {
        return $this->inner->getPaymentDetails($providerPaymentId);
    }

    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse
    {
        return $this->inner->authorizePayment($request);
    }

    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse
    {
        return $this->inner->captureAuthorization($request);
    }

    public function voidAuthorization(VoidAuthorizationRequest $request): CancellationResponse
    {
        return $this->inner->voidAuthorization($request);
    }

    public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse
    {
        return $this->inner->reauthorizePayment($request);
    }

    public function createPaymentMethod(CreatePaymentMethodRequest $request): PaymentMethodResponse
    {
        return $this->inner->createPaymentMethod($request);
    }

    /**
     * @return array<PaymentMethodResponse>
     */
    public function listPaymentMethods(string $customerId): array
    {
        return $this->inner->listPaymentMethods($customerId);
    }

    public function deletePaymentMethod(string $paymentMethodId): bool
    {
        return $this->inner->deletePaymentMethod($paymentMethodId);
    }

    public function initiate3DSecure(ThreeDSecureRequest $request): ThreeDSecureResponse
    {
        return $this->inner->initiate3DSecure($request);
    }

    public function verify3DSecureResult(string $providerPaymentId): bool
    {
        return $this->inner->verify3DSecureResult($providerPaymentId);
    }

    /**
     * @return array<string>
     */
    public function getSupportedPaymentMethods(): array
    {
        return $this->inner->getSupportedPaymentMethods();
    }

    public function getProviderName(): string
    {
        return $this->inner->getProviderName();
    }

    public function supportsFeature(string $feature): bool
    {
        return $this->inner->supportsFeature($feature);
    }

    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent
    {
        return $this->inner->parseWebhook($payload, $signature, $secret);
    }

    // ==========================================
    // STRIPE-SPECIFIC DELEGATED METHODS
    // ==========================================

    public function retrieveCheckoutSession(string $sessionId, array $expand = []): Session
    {
        return $this->inner->retrieveCheckoutSession($sessionId, $expand);
    }

    public function createCheckoutSession(array $params): Session
    {
        return $this->inner->createCheckoutSession($params);
    }

    public function retrievePaymentIntent(string $paymentIntentId, array $expand = []): PaymentIntent
    {
        return $this->inner->retrievePaymentIntent($paymentIntentId, $expand);
    }

    public function cancelPaymentIntent(string $paymentIntentId, ?string $cancellationReason = null): PaymentIntent
    {
        return $this->inner->cancelPaymentIntent($paymentIntentId, $cancellationReason);
    }

    public function getPaymentIntentRiskScore(string $paymentIntentId): ?float
    {
        return $this->inner->getPaymentIntentRiskScore($paymentIntentId);
    }

    public function retrieveCharge(string $chargeId): Charge
    {
        return $this->inner->retrieveCharge($chargeId);
    }

    public function createStripeCustomer(array $params): Customer
    {
        return $this->inner->createStripeCustomer($params);
    }

    public function retrieveStripeCustomer(string $customerId): Customer
    {
        return $this->inner->retrieveStripeCustomer($customerId);
    }

    public function testConnection(): bool
    {
        return $this->inner->testConnection();
    }

    // ==========================================
    // PRIVATE HELPERS
    // ==========================================

    private function reuseOrCreateRecord(
        ?IdempotencyRecord $existing,
        string $key,
        string $orderId,
        string $operation
    ): IdempotencyRecord {
        if ($existing !== null) {
            $existing->setStatus(self::STATUS_PROCESSING);
            $existing->setResult(null);
            return $existing;
        }

        return $this->createRecord($key, $orderId, $operation);
    }

    private function createRecord(string $key, string $orderId, string $operation): IdempotencyRecord
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+' . $this->ttlSeconds . ' seconds');
        /** @phpstan-ignore-next-line */
        return new IdempotencyRecord(
            $this->generateId(),
            $key,
            $orderId,
            $operation,
            self::STATUS_PROCESSING,
            $now,
            $expiresAt
        );
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function serializeCaptureResponse(CaptureResponse $response): string
    {
        return (string) json_encode([
            'successful' => $response->successful,
            'providerPaymentId' => $response->providerPaymentId,
            'captureId' => $response->captureId,
            'amountCaptured' => $response->amountCaptured,
            'currency' => $response->currency,
            'status' => $response->status,
            'capturedAt' => $response->capturedAt?->format('Y-m-d H:i:s'),
            'errorMessage' => $response->errorMessage,
            'errorCode' => $response->errorCode,
        ]);
    }

    private function deserializeCaptureResponse(string $json): CaptureResponse
    {
        /** @var array{successful?: bool, providerPaymentId?: string, captureId?: string, amountCaptured?: float, currency?: string, status?: string, capturedAt?: string, errorMessage?: string, errorCode?: string} $data */
        $data = json_decode($json, true);

        if (!($data['successful'] ?? false)) {
            return CaptureResponse::failure(
                $data['errorMessage'] ?? 'Unknown error',
                $data['errorCode'] ?? null
            );
        }

        return CaptureResponse::success(
            providerPaymentId: $data['providerPaymentId'] ?? '',
            captureId: $data['captureId'] ?? '',
            amountCaptured: $data['amountCaptured'] ?? 0.0,
            currency: $data['currency'] ?? '',
            status: $data['status'] ?? '',
            capturedAt: new DateTimeImmutable($data['capturedAt'] ?? 'now'),
        );
    }

    private function serializeRefundResponse(RefundResponse $response): string
    {
        return (string) json_encode([
            'successful' => $response->successful,
            'providerPaymentId' => $response->providerPaymentId,
            'refundId' => $response->refundId,
            'amountRefunded' => $response->amountRefunded,
            'currency' => $response->currency,
            'status' => $response->status,
            'refundedAt' => $response->refundedAt?->format('Y-m-d H:i:s'),
            'reason' => $response->reason,
            'errorMessage' => $response->errorMessage,
            'errorCode' => $response->errorCode,
        ]);
    }

    private function deserializeRefundResponse(string $json): RefundResponse
    {
        /** @var array{successful?: bool, providerPaymentId?: string, refundId?: string, amountRefunded?: float, currency?: string, status?: string, refundedAt?: string, reason?: string, errorMessage?: string, errorCode?: string} $data */
        $data = json_decode($json, true);

        if (!($data['successful'] ?? false)) {
            return RefundResponse::failure(
                $data['errorMessage'] ?? 'Unknown error',
                $data['errorCode'] ?? null
            );
        }

        return RefundResponse::success(
            providerPaymentId: $data['providerPaymentId'] ?? '',
            refundId: $data['refundId'] ?? '',
            amountRefunded: $data['amountRefunded'] ?? 0.0,
            currency: $data['currency'] ?? '',
            status: $data['status'] ?? '',
            refundedAt: new DateTimeImmutable($data['refundedAt'] ?? 'now'),
            reason: $data['reason'] ?? null,
        );
    }
}
