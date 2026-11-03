<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Return;

/**
 * Decides whether a return from Stripe Checkout names what it must: a Stripe session and a contract.
 *
 * Pure: request values in, accepted inputs or a named rejection out. Who may come back for the contract is not decided
 * here (VR-20 R5): payment-base's ReturnAccessGuard decides it, once the contract is loaded - a valid return token, or
 * the shopper in the session that started the payment. A return without a token is therefore accepted here.
 */
final class CheckoutReturnInputsResolver
{
    public function resolve(
        ?string $sessionId,
        ?string $contractId,
        ?string $contractToken
    ): CheckoutReturnInputs|CheckoutReturnRejection {
        if ($sessionId === null || $sessionId === '') {
            return CheckoutReturnRejection::MissingSessionId;
        }
        if ($contractId === null || $contractId === '') {
            return CheckoutReturnRejection::MissingContractIdentifiers;
        }

        return new CheckoutReturnInputs($sessionId, $contractId, $contractToken ?? '');
    }
}
