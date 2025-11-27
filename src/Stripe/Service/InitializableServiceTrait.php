<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

/**
 * Trait for implementing lazy initialization in services
 *
 * This trait provides a standard implementation of the InitializableServiceInterface
 * with state management and automatic initialization checks.
 *
 * Usage:
 * 1. Implement InitializableServiceInterface in your service class
 * 2. Use this trait
 * 3. Implement the doInitialize() method for your specific initialization logic
 * 4. Call ensureInitialized() at the start of methods that require initialization
 *
 * Example:
 * ```php
 * class MyService implements InitializableServiceInterface {
 *     use InitializableServiceTrait;
 *
 *     public function myMethod() {
 *         $this->ensureInitialized();
 *         // ... use initialized resources
 *     }
 *
 *     protected function doInitialize(): void {
 *         // Initialize API client, connections, etc.
 *     }
 *
 *     public function canInitialize(): bool {
 *         return !empty($this->config->getApiKey());
 *     }
 * }
 * ```
 *
 * @package OxidSolutionCatalysts\Payments\Stripe\Service
 * @author OXID eSales AG
 * @since 1.0.0
 */
trait InitializableServiceTrait
{
    /**
     * Initialization state flag
     *
     * @var bool
     */
    private bool $initialized = false;

    /**
     * @inheritDoc
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return; // Already initialized
        }

        if (!$this->canInitialize()) {
            throw new \RuntimeException(
                'Cannot initialize service: required configuration is missing'
            );
        }

        $this->doInitialize();
        $this->initialized = true;
    }

    /**
     * Ensure the service is initialized before use
     *
     * This method should be called at the start of any public method that
     * requires the service to be initialized.
     *
     * @throws \RuntimeException If service cannot be initialized
     * @return void
     */
    protected function ensureInitialized(): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }
    }

    /**
     * Perform the actual initialization
     *
     * This method must be implemented by the service class to perform
     * specific initialization tasks (create API clients, connections, etc.)
     *
     * This is called by initialize() after canInitialize() returns true.
     *
     * @throws \RuntimeException If initialization fails
     * @return void
     */
    abstract protected function doInitialize(): void;

    /**
     * Check if the service can be initialized
     *
     * This method must be implemented by the service class to check
     * if all required configuration and dependencies are available.
     *
     * @return bool True if initialization is possible, false otherwise
     */
    abstract public function canInitialize(): bool;
}
