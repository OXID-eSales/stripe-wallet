<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

/**
 * Builds idempotency keys that identify one payment *request*, not one payment.
 *
 * Sprint 133 · Story 2 (F2): the previous keys were `refund:{paymentIntentId}`
 * and `refund_charge:{chargeId}` — one key per payment. Because Stripe supports
 * partial refunds, a second legitimate partial refund collided with the first:
 * on the PaymentIntent path the stored response was replayed as a fresh success
 * (no money moved, admin saw a green refund carrying the *old* refund id), and
 * on the by-charge path no completed-check existed at all, so a retried request
 * refunded twice for real.
 *
 * A key therefore mixes in everything that makes two refunds different:
 * amount, reason, and the caller's request reference — for refunds the
 * pre-refund state of the charge (see RefundService), which makes a *retry*
 * of one submit identical and a *new* refund distinct without trusting any
 * client-supplied token.
 *
 * Capture stays payment-scoped: Stripe permits exactly one capture per
 * PaymentIntent, so `capture:{paymentIntentId}` is already request identity.
 *
 * Keys must fit `oe_payments_idempotency.OXKEY` (VARCHAR(128), UNIQUE) and stay
 * greppable, so the provider id is kept verbatim and only the variable part is
 * hashed.
 *
 * @since 2.0.0
 */
final class IdempotencyKeyFactory
{
    /** Length of the truncated hash appended to the readable prefix. */
    private const HASH_LENGTH = 16;

    /** Hard limit of the OXKEY column. */
    private const MAX_KEY_LENGTH = 128;

    public static function forRefund(
        string $paymentIntentId,
        ?int $amountMinorUnits,
        ?string $reason,
        ?string $requestReference
    ): string {
        return self::build('refund', $paymentIntentId, $amountMinorUnits, $reason, $requestReference);
    }

    public static function forRefundByCharge(
        string $chargeId,
        ?int $amountMinorUnits,
        ?string $reason,
        ?string $requestReference
    ): string {
        return self::build('refund_charge', $chargeId, $amountMinorUnits, $reason, $requestReference);
    }

    /**
     * Capture is once-per-PaymentIntent at Stripe, so the payment id alone is
     * a correct request identity — kept verbatim for backwards compatibility
     * with records written before Sprint 133.
     */
    public static function forCapture(string $paymentIntentId): string
    {
        return 'capture:' . $paymentIntentId;
    }

    private static function build(
        string $operation,
        string $providerId,
        ?int $amountMinorUnits,
        ?string $reason,
        ?string $requestReference
    ): string {
        $fingerprint = substr(
            sha1(implode('|', [
                $amountMinorUnits ?? 'full',
                $reason ?? 'none',
                $requestReference ?? 'no-reference',
            ])),
            0,
            self::HASH_LENGTH
        );

        $key = $operation . ':' . $providerId . ':' . $fingerprint;

        if (strlen($key) <= self::MAX_KEY_LENGTH) {
            return $key;
        }

        // Provider ids are ~30 chars, so this is unreachable in practice; kept
        // so an unexpectedly long id degrades to a still-unique key instead of
        // a silently truncated one colliding with its neighbours.
        $room = self::MAX_KEY_LENGTH - strlen($operation) - strlen($fingerprint) - 2;

        return $operation . ':' . substr($providerId, 0, max(0, $room)) . ':' . $fingerprint;
    }
}
