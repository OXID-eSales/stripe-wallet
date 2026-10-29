<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * What the shop remembers of its webhook endpoint per mode: the endpoint id and the signing secret. The secret
 * itself never leaves the storage through this seam, only whether one is there. Read-only: the classic admin writes.
 */
interface StripeWebhookStateInterface
{
    public function endpointId(string $mode): string;

    /**
     * Whether webhook signatures can be verified in this mode (the same rule as the module configuration service,
     * including its refusal of the legacy mode-agnostic secret once the other mode has its own).
     */
    public function hasSecret(string $mode): bool;
}
