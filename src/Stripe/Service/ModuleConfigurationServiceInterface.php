<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Interface for Stripe module configuration management.
 *
 * Provides type-safe access to all module settings and credentials.
 *
 * @since Sprint 43
 */
interface ModuleConfigurationServiceInterface
{
    public function get(string $name): mixed;

    public function isTestMode(): bool;

    /**
     * Returns the current mode string: 'test' or 'live'.
     */
    public function getMode(): string;

    public function getPublishableKey(): string;

    /**
     * Get the secret API token based on current mode (test/live).
     * Single source-of-truth accessor; getSecretKey() is a backward-compat alias.
     */
    public function getToken(): string;

    /**
     * Alias for getToken() — kept for backward compatibility.
     *
     * @see getToken()
     */
    public function getSecretKey(): string;

    public function getWebhookSecret(): string;

    public function getWebhookEndpoint(): string;

    public function isTransactionLoggingEnabled(): bool;

    public function isRemoveByBillingCountry(): bool;

    public function isRemoveByBasketCurrency(): bool;

    public function shouldProvideCustomerEmail(): bool;

    public function getWebhookUrl(): string;

    public function isConfigured(): bool;

    /**
     * Returns the platform secret key for the current mode (sStripeTestKey / sStripeLiveKey).
     * Used to register Connect webhooks on the platform Stripe account.
     */
    public function getPlatformKey(): string;

    /**
     * Returns the module's human-readable description from metadata.php.
     *
     * Used as the `description` field when registering a webhook endpoint on
     * Stripe; the merchant sees it as the endpoint's label in the Stripe Dashboard.
     */
    public function getModuleDescription(): string;

    public function getCaptureMode(): string;

    public function is3DSecureEnabled(): bool;

    public function getMinimumOrderAmount(): float;

    /**
     * Resolve the effective log level: off | errors | normal | debug.
     *
     * When sStripeLogLevel is explicitly set to a known value → return it.
     * When sStripeLogLevel is unset/empty → seed from legacy blStripeLogTransactionInfo:
     *   '1'/truthy → 'normal', '0'/falsy → 'off'. Default: 'normal'.
     * Unknown values → safe default 'normal'.
     */
    public function getLogLevel(): string;

    /**
     * Requests channel: enabled when level ∈ {errors, normal, debug}.
     * Note: at 'errors' level, exceptions are still logged; full request/response at normal+.
     * The gate controls the channel, not individual call severity.
     */
    public function isRequestLoggingEnabled(): bool;

    /**
     * Reconciliation channel: enabled when level ∈ {normal, debug}.
     */
    public function isReconciliationLoggingEnabled(): bool;

    /**
     * Events channel: enabled when level == debug only.
     */
    public function isEventLoggingEnabled(): bool;

    /**
     * Webhook channel: enabled when blStripeLogWebhooks is on AND level ∈ {normal, debug}.
     * The webhook switch is independent so merchants can silence chatty webhooks
     * without going dark on requests/reconciliation.
     */
    public function isWebhookLoggingEnabled(): bool;

    /**
     * Frontend debug: enabled when level == debug.
     * Wired in Phase 5 (frontend); resolver defined here for a single DRY path.
     */
    public function isFrontendDebugEnabled(): bool;
}
