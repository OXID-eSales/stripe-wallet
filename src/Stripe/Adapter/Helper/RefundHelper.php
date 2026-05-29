<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * Helper for refund and charge operations.
 *
 * Sprint 46: Extracted from StripeAdapter to reduce ECC.
 * Sprint 46: Idempotency for refund operations (moved from IdempotentStripeAdapter).
 *
 * @since 2.0.0
 */
class RefundHelper
{
    private const DEFAULT_TTL_SECONDS = 86400;

    private readonly ?IdempotentExecutor $idempotentExecutor;

    public function __construct(
        private readonly ?IdempotencyRepositoryInterface $idempotencyRepository = null,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
        $this->idempotentExecutor = $idempotencyRepository !== null
            ? new IdempotentExecutor($idempotencyRepository, $ttlSeconds)
            : null;
    }

    public function refundPayment(StripeClient $client, RefundPaymentRequest $request): RefundResponse
    {
        if ($this->idempotencyRepository !== null) {
            return $this->refundWithIdempotency($client, $request);
        }

        return $this->executeRefundPayment($client, $request);
    }

    /**
     * @param array<string, string>|null $metadata
     */
    public function createRefundByCharge(
        StripeClient $client,
        string $chargeId,
        ?int $amount = null,
        ?string $reason = null,
        ?array $metadata = null
    ): Refund {
        if ($this->idempotencyRepository !== null) {
            return $this->refundByChargeWithIdempotency($client, $chargeId, $amount, $reason, $metadata);
        }

        return $this->executeCreateRefundByCharge($client, $chargeId, $amount, $reason, $metadata);
    }

    public function retrieveCharge(StripeClient $client, string $chargeId): Charge
    {
        try {
            return $client->charges->retrieve($chargeId);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    private function executeRefundPayment(StripeClient $client, RefundPaymentRequest $request): RefundResponse
    {
        try {
            $params = ['payment_intent' => $request->providerPaymentId];

            if ($request->amount !== null) {
                // Sprint 114.10a (§6.2): RefundPaymentRequest now carries an optional currency;
                // use it for correct minor-unit conversion (zero-decimal currencies like JPY
                // must NOT be multiplied by 100). Null/empty falls back to 2-decimal behaviour.
                $params['amount'] = AmountConverter::toMinorUnits($request->amount, $request->currency ?? '');
            }

            if ($request->reason !== null) {
                $params['reason'] = self::mapRefundReason($request->reason);
            }

            if (!empty($request->metadata)) {
                $params['metadata'] = $request->metadata;
            }

            $refund = $client->refunds->create($params);

            /** @var array<string, mixed> $providerData */
            $providerData = $refund->toArray();

            return RefundResponse::success(
                providerPaymentId: $request->providerPaymentId,
                refundId: $refund->id,
                amountRefunded: AmountConverter::toMajorUnits($refund->amount, strtoupper($refund->currency)),
                currency: strtoupper($refund->currency),
                status: $refund->status ?? 'pending',
                refundedAt: new DateTimeImmutable('@' . $refund->created),
                reason: $request->reason,
                providerData: $providerData,
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    /**
     * @param array<string, string>|null $metadata
     */
    private function executeCreateRefundByCharge(
        StripeClient $client,
        string $chargeId,
        ?int $amount,
        ?string $reason,
        ?array $metadata
    ): Refund {
        try {
            $params = ['charge' => $chargeId];

            if ($amount !== null) {
                $params['amount'] = $amount;
            }
            if ($reason !== null) {
                $params['reason'] = $reason;
            }
            if ($metadata !== null) {
                $params['metadata'] = $metadata;
            }

            return $client->refunds->create($params);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    private function refundWithIdempotency(StripeClient $client, RefundPaymentRequest $request): RefundResponse
    {
        /** @var IdempotentExecutor $executor */
        $executor = $this->idempotentExecutor;
        $result = $executor->execute(
            key: 'refund:' . $request->providerPaymentId,
            referenceId: $request->providerPaymentId,
            operation: 'refund',
            callable: fn () => $this->executeRefundPayment($client, $request),
            serialize: function (mixed $r): string {
                assert($r instanceof RefundResponse);
                return $this->serializeRefundResponse($r);
            },
            deserialize: fn (string $j) => $this->deserializeRefundResponse($j)
        );
        /** @var RefundResponse $result */
        return $result;
    }

    /**
     * @param array<string, string>|null $metadata
     */
    private function refundByChargeWithIdempotency(
        StripeClient $client,
        string $chargeId,
        ?int $amount,
        ?string $reason,
        ?array $metadata
    ): Refund {
        $key = 'refund_charge:' . $chargeId;
        /** @var IdempotencyRepositoryInterface $repository */
        $repository = $this->idempotencyRepository;
        $existing = $repository->findByKey($key);

        if ($existing !== null && !$existing->isExpired()) {
            if ($existing->getStatus() === IdempotentExecutor::STATUS_PROCESSING) {
                throw new \RuntimeException('Refund by charge operation already in progress for: ' . $chargeId);
            }
        }

        $record = IdempotencyHelper::reuseOrCreate($existing, $key, $chargeId, 'refund_charge', $this->ttlSeconds);
        $repository->save($record);

        try {
            $result = $this->executeCreateRefundByCharge($client, $chargeId, $amount, $reason, $metadata);
            $record->setStatus(IdempotentExecutor::STATUS_COMPLETED);
            $repository->save($record);
            return $result;
        } catch (\Throwable $e) {
            $record->setStatus(IdempotentExecutor::STATUS_FAILED);
            $record->setResult(json_encode(['error' => $e->getMessage()]) ?: null);
            $repository->save($record);
            throw $e;
        }
    }

    private static function mapRefundReason(string $reason): string
    {
        return match ($reason) {
            'requested_by_customer', 'fraudulent', 'duplicate' => $reason,
            default => 'requested_by_customer',
        };
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
