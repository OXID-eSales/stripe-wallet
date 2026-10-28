<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * The Stripe facts of an order's payment for the Payment tab: what the shop stored (intent id, the reference from
 * checkout, the dashboard link) and what Stripe says now (status, charge). When Stripe could not be asked the
 * stored facts stay and `apiError` says why.
 */
final readonly class StripePaymentDetails
{
    public function __construct(
        public ?string $paymentIntentId,
        public ?string $chargeId,
        public ?string $externalTransactionId,
        public ?string $intentStatus,
        public ?string $dashboardUrl,
        public ?string $apiError,
    ) {
    }
}
