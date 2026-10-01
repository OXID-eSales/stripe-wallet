<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

/**
 * Stripe's column of the shared contract-state Help (MOL-10): for every row payment-base shows
 * (OXID Contract Status · Meaning) the Stripe PaymentIntent status it corresponds to, keyed by the
 * row's first state. Rows, meanings and markup live in payment-base; this class adds only Stripe's
 * column, its header and the description above the table.
 */
final class StripeContractStateHelp
{
    public const INTRO_IDENT = 'STRIPE_HELP_CONTRACT_STATES_INTRO';
    public const COLUMN_IDENT = 'STRIPE_HELP_COL_STRIPE_STATUS';

    public function introIdent(): string
    {
        return self::INTRO_IDENT;
    }

    public function columnIdent(): string
    {
        return self::COLUMN_IDENT;
    }

    /**
     * Row key (first contract state of the row) => Stripe PaymentIntent status; '' = none, shop-internal.
     *
     * @return array<string, string>
     */
    public function providerStatuses(): array
    {
        return [
            'not_finished' => 'requires_payment_method, requires_confirmation, requires_action',
            'pending' => 'processing',
            'authorized' => 'requires_capture',
            'ready_to_commit' => 'succeeded',
            'committed' => '',
            'cancelled' => 'canceled (requested_by_customer, abandoned, duplicate, fraudulent)',
            'expired' => 'canceled (automatic), checkout.session.expired',
            'failed' => 'payment_intent.payment_failed (no own status)',
        ];
    }
}
