<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminSettingsInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigChange;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigSnapshot;

final class InMemoryStripeSettings implements StripeAdminSettingsInterface
{
    public int $writes = 0;

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

    public function apply(StripeConfigChange $change): void
    {
        ++$this->writes;
        foreach (['test', 'live'] as $mode) {
            $token = $change->tokenFor($mode);
            if ($token !== null) {
                $this->tokens[$mode] = $token;
            }
        }
        $this->current = ConfigFixtures::snapshotFrom($this->current, [
            'mode' => $change->mode ?? $this->current->mode,
            'captureMode' => $change->captureMode ?? $this->current->captureMode,
            'removeByBillingCountry' => $change->removeByBillingCountry ?? $this->current->removeByBillingCountry,
            'removeByBasketCurrency' => $change->removeByBasketCurrency ?? $this->current->removeByBasketCurrency,
            'provideCustomerEmail' => $change->provideCustomerEmail ?? $this->current->provideCustomerEmail,
            'logLevel' => $change->logLevel ?? $this->current->logLevel,
            'logWebhooks' => $change->logWebhooks ?? $this->current->logWebhooks,
            'testPublishableKey' => $change->testPublishableKey ?? $this->current->testPublishableKey,
            'livePublishableKey' => $change->livePublishableKey ?? $this->current->livePublishableKey,
            'testTokenSet' => ($this->tokens['test'] ?? '') !== '',
            'liveTokenSet' => ($this->tokens['live'] ?? '') !== '',
            'webhookSecretSet' => $change->webhookSecret() !== null || $this->current->webhookSecretSet,
        ]);
    }
}
