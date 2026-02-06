<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\IdempotentStripeAdapter;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;

/**
 * Factory decorator that wraps StripeAdapterFactory to produce idempotent adapters.
 *
 * Both adapter creation paths (contract-based via LazyStripeAdapter and admin panel
 * via direct factory calls) go through getStripeAdapter(), so decorating at the
 * factory level ensures all paths get idempotency protection.
 *
 * Sprint 42: Idempotency implementation.
 *
 * @since 1.0.0
 */
class IdempotentStripeAdapterFactory implements StripeAdapterFactoryInterface
{
    public function __construct(
        private readonly StripeAdapterFactory $innerFactory,
        private readonly IdempotencyRepositoryInterface $repository
    ) {
    }

    public function getStripeAdapter(): StripeAdapterInterface
    {
        $adapter = $this->innerFactory->getStripeAdapter();
        return new IdempotentStripeAdapter($adapter, $this->repository);
    }

    public function createAdapter(string $providerName): PaymentAdapterInterface
    {
        return $this->innerFactory->createAdapter($providerName);
    }

    public function createDefaultAdapter(): PaymentAdapterInterface
    {
        return $this->innerFactory->createDefaultAdapter();
    }

    public function isProviderSupported(string $providerName): bool
    {
        return $this->innerFactory->isProviderSupported($providerName);
    }

    /**
     * @return array<string>
     */
    public function getSupportedProviders(): array
    {
        return $this->innerFactory->getSupportedProviders();
    }

    public function isTestMode(): bool
    {
        return $this->innerFactory->isTestMode();
    }
}
