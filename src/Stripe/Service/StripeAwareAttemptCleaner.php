<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleanerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * Decorates payment-base's PreviousCheckoutAttemptCleaner: a Stripe attempt is
 * asked about first.
 *
 * Since OPC-175 the one-page checkout retires the attempt a session left open
 * through payment-base's shared chain, whatever provider the next attempt
 * uses. payment-base cannot ask Stripe whether that attempt was paid - and a
 * paid one is money, not garbage (GRAPH-QL / PS3). So a paid Stripe session is
 * committed instead of cancelled; anything else goes to the shared retire
 * unchanged.
 *
 * @since OPC-175
 */
final class StripeAwareAttemptCleaner implements PreviousCheckoutAttemptCleanerInterface
{
    public function __construct(
        private readonly PreviousCheckoutAttemptCleanerInterface $inner,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly PaidSessionSettler $paidSessions
    ) {
    }

    public function clean(?string $contractId): bool
    {
        if ($this->isPaidStripeAttempt($contractId)) {
            return false;
        }

        return $this->inner->clean($contractId);
    }

    /**
     * True only when the attempt is Stripe's and Stripe reports it paid - the
     * settler has then committed it. Any other provider is not asked.
     */
    private function isPaidStripeAttempt(?string $contractId): bool
    {
        if ($contractId === null || $contractId === '') {
            return false;
        }

        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null || $contract->getProvider() !== StripeDefinitions::PROVIDER) {
            return false;
        }

        return $this->paidSessions->settle($contract);
    }
}
