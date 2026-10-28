<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookStateInterface;

final class InMemoryWebhookState implements StripeWebhookStateInterface
{
    /**
     * @param array<string, string> $endpointIds mode => endpoint id
     * @param array<string, string> $secrets mode => signing secret
     */
    public function __construct(public array $endpointIds = [], public array $secrets = [])
    {
    }

    public function endpointId(string $mode): string
    {
        return $this->endpointIds[$mode] ?? '';
    }

    public function hasSecret(string $mode): bool
    {
        return ($this->secrets[$mode] ?? '') !== '';
    }

    public function storeSecret(string $mode, string $secret): void
    {
        $this->secrets[$mode] = $secret;
    }
}
