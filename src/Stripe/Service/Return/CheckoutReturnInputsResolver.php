<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Return;

/**
 * Decides whether a return from Stripe Checkout may proceed.
 *
 * Pure: request and session values in, accepted inputs or a named rejection
 * out. Keeping it free of Registry access is what makes each of the five ways
 * a return can be refused directly testable — they used to be five inline
 * `if`s in the controller, all ending in the same untraceable sentence.
 */
final class CheckoutReturnInputsResolver
{
    public function resolve(
        ?string $sessionId,
        ?string $contractId,
        ?string $contractToken,
        bool $contractTokenValid
    ): CheckoutReturnInputs|CheckoutReturnRejection {
        if ($sessionId === null || $sessionId === '') {
            return CheckoutReturnRejection::MissingSessionId;
        }

        if ($contractId === null || $contractId === '' || $contractToken === null || $contractToken === '') {
            return CheckoutReturnRejection::MissingContractIdentifiers;
        }

        // Checked before the id comparison on purpose: a token that does not
        // authenticate its contract is the security-relevant fact, and it would
        // otherwise be reported as a mere mismatch.
        if (!$contractTokenValid) {
            return CheckoutReturnRejection::InvalidContractToken;
        }

        return new CheckoutReturnInputs($sessionId, $contractId, $contractToken);
    }

    /**
     * Second stage, once the contract is loaded: is this contract the current
     * shopper's?
     *
     * This replaces an equality check against the session's `stripe_contract_id`.
     * That pointer names only the *last* contract the order-page path created,
     * while a checkout can end up with several — the OPC payment-handler path
     * creates its own, and each embedded sheet carries the one it was opened
     * with. Paying any but the last one was refused as "Payment verification
     * failed" *after* the customer had been charged, which is the worst outcome
     * available. Ownership is the binding that was actually meant: the contract
     * id is already authenticated by its token, so what remains to check is that
     * it belongs to whoever is checking out.
     *
     * @return CheckoutReturnRejection|null null when the return may proceed
     */
    public function checkOwnership(?string $contractUserId, ?string $currentUserId): ?CheckoutReturnRejection
    {
        // Nothing to compare — a charged payment must not be thrown away over a
        // check that cannot be made.
        if ($contractUserId === null || $contractUserId === '') {
            return null;
        }
        if ($currentUserId === null || $currentUserId === '') {
            return null;
        }

        return $contractUserId === $currentUserId ? null : CheckoutReturnRejection::ContractMismatch;
    }
}
