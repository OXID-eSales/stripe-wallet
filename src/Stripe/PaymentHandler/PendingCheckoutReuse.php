<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\PaymentHandler;

use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * Decides whether the checkout a shopper already has in flight can be handed
 * back instead of creating another one.
 *
 * The OPC checkout API calls the payment handler repeatedly while the customer
 * works through the accordion. Without this, each call minted a new contract, a
 * new early order and a new Stripe Checkout Session; the customer could then pay
 * in a sheet belonging to any of them, and everything else was left behind as
 * cancelled contracts and orders with no payment type.
 *
 * Stateless and deterministic, hence static — see the module's static-utility
 * convention.
 */
final class PendingCheckoutReuse
{
    /** Stripe's prefix for a Checkout Session; a payment intent is not one. */
    private const SESSION_ID_PREFIX = 'cs_';

    /** The state the handler leaves a prepared, unpaid checkout in. */
    private const REUSABLE_STATE = 'pending';

    /** Stripe's payment_status for a session nobody has paid yet. */
    private const UNPAID = 'unpaid';

    public static function allows(
        ExistingCheckout $existing,
        int $currentAmountMinorUnits,
        string $currentCurrency
    ): bool {
        if ($existing->providerName !== StripeDefinitions::PROVIDER) {
            return false;
        }

        if ($existing->contractState !== self::REUSABLE_STATE) {
            return false;
        }

        if (!str_starts_with($existing->sessionId, self::SESSION_ID_PREFIX)) {
            return false;
        }

        // Never hand back something already paid, and never something for zero:
        // a zero total means the amount could not be determined.
        if ($existing->sessionPaymentStatus !== self::UNPAID || $currentAmountMinorUnits <= 0) {
            return false;
        }

        // The basket may have changed under the customer, and Stripe would still
        // charge what the session was created for.
        return $existing->sessionAmountMinorUnits === $currentAmountMinorUnits
            && strcasecmp($existing->sessionCurrency, $currentCurrency) === 0;
    }
}
