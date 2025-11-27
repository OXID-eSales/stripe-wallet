<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

/**
 * Interface for services that support lazy initialization
 *
 * Services implementing this interface can be constructed in an uninitialized state
 * and will initialize themselves when first used. This is useful when API credentials
 * may not be available at construction time.
 *
 * Example use cases:
 * - Module not yet configured
 * - API keys being loaded from database
 * - Service instantiated for dependency injection container
 *
 * @package OxidSolutionCatalysts\Payments\Stripe\Service
 * @author OXID eSales AG
 * @since 1.0.0
 */
interface InitializableServiceInterface
{
    /**
     * Check if the service is initialized and ready to use
     *
     * @return bool True if initialized, false otherwise
     */
    public function isInitialized(): bool;

    /**
     * Initialize the service
     *
     * This should set up any required resources (API clients, connections, etc.)
     * and transition the service to initialized state.
     *
     * @throws \RuntimeException If initialization fails
     * @return void
     */
    public function initialize(): void;

    /**
     * Check if the service can be initialized
     *
     * Returns true if all required configuration and dependencies are available
     * for successful initialization.
     *
     * @return bool True if initialization is possible, false otherwise
     */
    public function canInitialize(): bool;
}
