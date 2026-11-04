<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * Helper for refund and charge operations.
 *
 * @since 2.0.0
 */
class RefundHelper
{
    private const DEFAULT_TTL_SECONDS = 86400;
    private const REFUND_PAGE_SIZE = 100;

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

    /**
     * Every refund of the charge, all pages (read to the end here, so an API error surfaces as an adapter exception).
     *
     * @return list<Refund>
     */
    public function listRefundsByCharge(StripeClient $client, string $chargeId): array
    {
        try {
            $refunds = [];
            $page = $client->refunds->all(['charge' => $chargeId, 'limit' => self::REFUND_PAGE_SIZE]);
            foreach ($page->autoPagingIterator() as $refund) {
                $refunds[] = $refund;
            }

            return $refunds;
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
}
