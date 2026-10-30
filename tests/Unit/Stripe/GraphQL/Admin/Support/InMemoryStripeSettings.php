<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminSettingsInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigSnapshot;

final class InMemoryStripeSettings implements StripeAdminSettingsInterface
{
    /**
     * @param array<string, string> $tokens mode => stored secret key
     * @param array<string, string> $platformKeys mode => stored platform key
     */
    public function __construct(
        private StripeConfigSnapshot $current,
        private array $tokens = ['test' => 'sk_test_fakeAAAAAAAAAAstored', 'live' => ''],
        private array $platformKeys = ['test' => 'sk_test_fakePLATFORMstored', 'live' => ''],
    ) {
    }

    public function snapshot(): StripeConfigSnapshot
    {
        return $this->current;
    }

    public function token(string $mode): string
    {
        return $this->tokens[$mode] ?? '';
    }

    public function platformKey(string $mode): string
    {
        return $this->platformKeys[$mode] ?? '';
    }
}
