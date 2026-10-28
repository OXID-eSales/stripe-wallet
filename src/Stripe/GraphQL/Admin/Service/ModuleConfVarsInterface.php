<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * The module-namespaced shop config variables (oxconfig, `module:oe_payments_stripe_wallet`): internal state that
 * must not show up as a field of the classic module configuration form, e.g. the per-mode webhook endpoint id and
 * signing secret.
 */
interface ModuleConfVarsInterface
{
    public function read(string $name): string;

    public function save(string $name, string $value): void;
}
