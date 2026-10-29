<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\ModuleConfVarsInterface;

final class InMemoryConfVars implements ModuleConfVarsInterface
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(public array $values = [])
    {
    }

    public function read(string $name): string
    {
        return $this->values[$name] ?? '';
    }
}
