<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * What the shop remembers of its webhook endpoint per mode: the endpoint id and the signing secret. The secret
 * itself never leaves the storage through this seam, only whether one is there.
 */
interface StripeWebhookStateInterface
{
    public function endpointId(string $mode): string;

    /**
     * Whether webhook signatures can be verified in this mode (the same rule as the module configuration service,
     * including its refusal of the legacy mode-agnostic secret once the other mode has its own).
     */
    public function hasSecret(string $mode): bool;

    /**
     * Stores a signing secret pasted by an admin for a mode.
     */
    public function storeSecret(string $mode, #[\SensitiveParameter] string $secret): void;

    /**
     * Remembers a registered endpoint: id and (only when Stripe just created it, which is the only time it tells
     * it) the signing secret, per mode; the URL and secret also go to the legacy module settings the classic form
     * shows. A null secret keeps the stored one.
     */
    public function storeEndpoint(
        string $mode,
        string $endpointId,
        #[\SensitiveParameter] ?string $secret,
        string $url,
    ): void;

    /**
     * Forgets the endpoint of both modes, their secrets and the legacy settings.
     */
    public function forgetAll(): void;
}
