<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Return;

/**
 * Why a customer coming back from Stripe was turned away.
 *
 * The customer sees a deliberately vague sentence — telling them which check
 * refused would help an attacker probe the return endpoint. The shop log gets
 * the precise reason, which is what makes such a return diagnosable at all.
 */
enum CheckoutReturnRejection
{
    /** Stripe returned without a checkout session id. */
    case MissingSessionId;

    /** The return carried no contract id and/or no contract token. */
    case MissingContractIdentifiers;

    /** The contract token does not authenticate the contract id. */
    case InvalidContractToken;

    /** The returned contract is not the one this session started. */
    case ContractMismatch;

    /** The contract id is well-formed and signed, but no such contract exists. */
    case ContractNotFound;

    /** The return was accepted, but the handler chain produced no order. */
    case NoOrderCreated;

    /**
     * What the customer is told. Only the missing-session-id case is specific,
     * because it is the one a customer can act on (they never reached payment).
     */
    public function customerMessage(): string
    {
        return $this === self::MissingSessionId
            ? 'Payment information missing'
            : 'Payment verification failed';
    }

    /**
     * What the log records — one stable, greppable token per reason.
     */
    public function logReason(): string
    {
        return match ($this) {
            self::MissingSessionId => 'missing_session_id',
            self::MissingContractIdentifiers => 'missing_contract_identifiers',
            self::InvalidContractToken => 'invalid_contract_token',
            self::ContractMismatch => 'contract_mismatch',
            self::ContractNotFound => 'contract_not_found',
            self::NoOrderCreated => 'no_order_created',
        };
    }
}
