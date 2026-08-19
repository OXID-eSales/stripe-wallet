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

    private readonly IdempotentExecutor $idempotentExecutor;

    /**
     * Sprint 133 · Story 3 (F8): the repository is required. It used to default
     * to null, and every mutating method silently skipped all duplicate-charge
     * protection in that case — same class, same API, no log line.
     */
    public function __construct(
        IdempotencyRepositoryInterface $idempotencyRepository,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
        $this->idempotentExecutor = new IdempotentExecutor($idempotencyRepository, $ttlSeconds);
    }

    public function refundPayment(StripeClient $client, RefundPaymentRequest $request): RefundResponse
    {
        return $this->refundWithIdempotency($client, $request);
    }

    /**
     * @param array<string, string>|null $metadata
     */
    public function createRefundByCharge(
        StripeClient $client,
        string $chargeId,
        ?int $amount = null,
        ?string $reason = null,
        ?array $metadata = null,
        ?string $requestReference = null
    ): Refund {
        return $this->refundByChargeWithIdempotency(
            $client,
            $chargeId,
            $amount,
            $reason,
            $metadata,
            $requestReference
        );
    }

    public function retrieveCharge(StripeClient $client, string $chargeId): Charge
    {
        try {
            return $client->charges->retrieve($chargeId);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    private function executeRefundPayment(
        StripeClient $client,
        RefundPaymentRequest $request,
        ?string $idempotencyKey = null
    ): RefundResponse {
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

            $refund = $client->refunds->create($params, self::requestOptions($idempotencyKey));

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
        ?array $metadata,
        ?string $idempotencyKey = null
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

            return $client->refunds->create($params, self::requestOptions($idempotencyKey));
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    private function refundWithIdempotency(StripeClient $client, RefundPaymentRequest $request): RefundResponse
    {
        /** @var IdempotentExecutor $executor */
        $executor = $this->idempotentExecutor;
        $key = IdempotencyKeyFactory::forRefund(
            $request->providerPaymentId,
            $this->refundAmountInMinorUnits($request),
            $request->reason,
            $request->idempotencyKey
        );
        $result = $executor->execute(
            key: $key,
            referenceId: $request->providerPaymentId,
            operation: 'refund',
            callable: fn () => $this->executeRefundPayment($client, $request, $key),
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
    /**
     * Sprint 133 · Story 2 (F2): routed through IdempotentExecutor like the
     * PaymentIntent path. It previously ran its own PROCESSING/COMPLETED/FAILED
     * flow which never checked for a completed record and never stored a
     * result, so a retried request refunded a second time for real.
     *
     * @param array<string, string>|null $metadata
     */
    private function refundByChargeWithIdempotency(
        StripeClient $client,
        string $chargeId,
        ?int $amount,
        ?string $reason,
        ?array $metadata,
        ?string $requestReference
    ): Refund {
        /** @var IdempotentExecutor $executor */
        $executor = $this->idempotentExecutor;
        $key = IdempotencyKeyFactory::forRefundByCharge($chargeId, $amount, $reason, $requestReference);
        $result = $executor->execute(
            key: $key,
            referenceId: $chargeId,
            operation: 'refund_charge',
            callable: fn () => $this->executeCreateRefundByCharge($client, $chargeId, $amount, $reason, $metadata, $key),
            serialize: function (mixed $r): string {
                assert($r instanceof Refund);
                return $this->serializeRefund($r);
            },
            deserialize: fn (string $j) => $this->deserializeRefund($j)
        );
        /** @var Refund $result */
        return $result;
    }

    /**
     * Serialize only the fields StripeObjectMapper::fromRefund() consumes, so a
     * replayed refund is indistinguishable from a freshly retrieved one.
     */
    private function serializeRefund(Refund $refund): string
    {
        return (string) json_encode([
            'id' => $refund->id,
            'amount' => $refund->amount,
            'currency' => $refund->currency,
            'status' => $refund->status,
            'reason' => $refund->reason,
            'created' => $refund->created,
        ]);
    }

    private function deserializeRefund(string $json): Refund
    {
        /** @var array{id?: string, amount?: int, currency?: string, status?: string, reason?: string|null, created?: int} $data */
        $data = json_decode($json, true);

        return Refund::constructFrom([
            'id' => $data['id'] ?? '',
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? '',
            'status' => $data['status'] ?? 'unknown',
            'reason' => $data['reason'] ?? null,
            'created' => $data['created'] ?? 0,
        ]);
    }

    /**
     * The request carries the amount in major units; the idempotency key must
     * use the same minor-unit value that reaches Stripe, so two refunds that
     * differ only by sub-unit rounding cannot collide.
     */
    private function refundAmountInMinorUnits(RefundPaymentRequest $request): ?int
    {
        if ($request->amount === null) {
            return null;
        }

        return AmountConverter::toMinorUnits($request->amount, $request->currency ?? '');
    }

    /**
     * Stripe's own idempotency layer. The local DB record cannot help when the
     * request reaches Stripe but the response is lost — Stripe replays the
     * original result for a repeated key instead of refunding twice.
     *
     * @return array{idempotency_key: string}|null
     */
    private static function requestOptions(?string $idempotencyKey): ?array
    {
        return $idempotencyKey === null ? null : ['idempotency_key' => $idempotencyKey];
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
