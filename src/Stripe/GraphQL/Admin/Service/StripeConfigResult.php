<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use LogicException;

final readonly class StripeConfigResult implements StripeAdminOutcomeInterface
{
    private function __construct(private ?StripeConfigSnapshot $config, private ?StripeAdminRefusal $refusal)
    {
    }

    public static function done(StripeConfigSnapshot $config): self
    {
        return new self($config, null);
    }

    public static function refused(StripeAdminRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function refusal(): ?StripeAdminRefusal
    {
        return $this->refusal;
    }

    public function config(): StripeConfigSnapshot
    {
        return $this->config ?? throw new LogicException('A refused change has no configuration');
    }
}
